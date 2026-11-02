<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Dispatch;

use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\ShipmentSender;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\ShipmentFactory;
use Psr\Log\LoggerInterface;
use Shubo\Qshpr\Exception\DispatchException;
use Shubo\Qshpr\Model\Carrier\Config;
use Shubo\Qshpr\Model\Delivery\ChosenCourier;
use Shubo\Qshpr\Model\Delivery\DeliveryLog;
use Shubo\Qshpr\Model\Delivery\OrderNotes;

/**
 * Ships a paid order without the admin: builds the shipment of everything
 * still to ship, books the courier exactly as Submit Shipment does (the
 * Dispatcher, before the save), saves shipment and order together and sends
 * the shipment email.
 *
 * Booking comes before the save so that a failed booking leaves no shipment:
 * the order is not completed and the customer gets no "shipped" email.
 * A failure that a later try may fix (Quickshipper did not answer) is tried
 * again after 10 and 30 minutes; any other failure, or the third one, stops
 * automatic booking for the order and tells the merchant on the order, in
 * the admin notifications and in the log. The Ship button keeps working.
 */
class AutoShipper
{
    /** Minutes before the 2nd and the 3rd attempt; there is no 4th. */
    private const array RETRY_MINUTES = [10, 30];

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param ShipmentFactory $shipmentFactory
     * @param Dispatcher $dispatcher
     * @param TransactionFactory $transactionFactory
     * @param ShipmentSender $shipmentSender
     * @param DeliveryLog $deliveryLog
     * @param DeliveryLock $lock
     * @param OrderNotes $orderNotes
     * @param NotifierInterface $notifier
     * @param AutoDispatchTrigger $trigger
     * @param Config $config
     * @param LoggerInterface $logger Bound to ShuboQshprLogger in di.xml.
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ShipmentFactory $shipmentFactory,
        private readonly Dispatcher $dispatcher,
        private readonly TransactionFactory $transactionFactory,
        private readonly ShipmentSender $shipmentSender,
        private readonly DeliveryLog $deliveryLog,
        private readonly DeliveryLock $lock,
        private readonly OrderNotes $orderNotes,
        private readonly NotifierInterface $notifier,
        private readonly AutoDispatchTrigger $trigger,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Book and ship the order of a due delivery row. The caller holds the order's lock.
     *
     * @param array<string, mixed> $row The quoted delivery row.
     * @throws LocalizedException
     * @return void
     */
    public function ship(array $row): void
    {
        $entityId = (int)$row['entity_id'];
        $order = $this->orderRepository->get((int)$row['order_id']);

        if (!$order instanceof Order || !$this->isStillDue($order)) {
            $this->deliveryLog->clearDispatchDue($entityId);
            $this->logger->info(sprintf(
                'auto-dispatch: order %s no longer due (setting off, not fully paid, shipped, cancelled or on hold)',
                (string)$row['increment_id']
            ));

            return;
        }

        $shipment = $this->shipmentFactory->create($order, $this->itemsToShip($order));

        if (!$shipment instanceof Shipment) {
            return;
        }

        $shipment->setData(Dispatcher::SHIPMENT_BOOKED_BY_CALLER, true);
        $shipment->register();

        try {
            $booking = $this->dispatcher->dispatch($shipment);
        } catch (DispatchException $e) {
            $this->failed($row, $e);

            return;
        }

        if ($booking === null) {
            $this->deliveryLog->clearDispatchDue($entityId);

            return;
        }

        try {
            // As the admin's Submit Shipment does: lets the order move to its next state on save.
            $order->setIsInProcess(true);
            $this->transactionFactory->create()->addObject($shipment)->addObject($order)->save();
        } catch (\Throwable $e) {
            $this->savedWithoutShipment($order, $booking->qshprOrderNo, $e);

            return;
        } finally {
            // Normally released when the save commits; without a commit, release it here.
            $lockedOrder = (int)$shipment->getData(Dispatcher::SHIPMENT_LOCKED_ORDER);

            if ($lockedOrder > 0) {
                $this->lock->release($lockedOrder);
            }
        }

        try {
            $this->shipmentSender->send($shipment);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'auto-dispatch: order %s shipped, but the shipment email failed: %s',
                $order->getIncrementId(),
                $e->getMessage()
            ));
        }

        $this->logger->info(sprintf(
            'auto-dispatch: order %s booked %s and shipped (shipment %s)',
            $order->getIncrementId(),
            $booking->qshprOrderNo,
            (string)$shipment->getIncrementId()
        ));
    }

    /**
     * Setting on, a Quickshipper order, paid in full, something left to ship.
     *
     * The order is marked due when an invoice is paid, before that invoice is
     * saved; a failed save leaves the mark behind on an unpaid order.
     *
     * @param Order $order
     * @return bool
     */
    private function isStillDue(Order $order): bool
    {
        return $this->config->isAutoDispatchEnabled((int)$order->getStoreId())
            && ChosenCourier::fromOrder($order) !== null
            && $this->trigger->isFullyPaid($order)
            && $order->canShip()
            && $this->itemsToShip($order) !== [];
    }

    /**
     * Every item left to ship, as the admin's Ship form fills it in.
     *
     * @param Order $order
     * @return array<int, float> Order item id → quantity.
     */
    private function itemsToShip(Order $order): array
    {
        $items = [];

        foreach ($order->getAllItems() as $item) {
            if (!$item->isDummy(true) && $item->getQtyToShip() > 0) {
                $items[(int)$item->getId()] = (float)$item->getQtyToShip();
            }
        }

        return $items;
    }

    /**
     * Booking failed: try again later, or stop and tell the merchant.
     *
     * @param array<string, mixed> $row
     * @param DispatchException $e
     * @return void
     */
    private function failed(array $row, DispatchException $e): void
    {
        $failedAttempts = (int)$row['dispatch_attempts'] + 1;
        $retryIn = $e->isRetryable() ? (self::RETRY_MINUTES[$failedAttempts - 1] ?? null) : null;

        $this->deliveryLog->recordDispatchFailure((int)$row['entity_id'], $e->getMessage(), $retryIn);

        if ($retryIn !== null) {
            $this->logger->warning(sprintf(
                'auto-dispatch: order %s attempt %d failed, trying again in %d min: %s',
                (string)$row['increment_id'],
                $failedAttempts,
                $retryIn,
                $e->getMessage()
            ));

            return;
        }

        $this->alertStopped($row, $e->getMessage(), $failedAttempts);
    }

    /**
     * Shipping failed in a way a booking failure does not cover (e.g. the
     * order was deleted, or Magento refused the shipment): stop automatic
     * booking for the order, so it is not tried every minute, and tell the merchant.
     *
     * @param array<string, mixed> $row The delivery row.
     * @param \Throwable $e
     * @return void
     */
    public function stopAfterError(array $row, \Throwable $e): void
    {
        $failedAttempts = (int)$row['dispatch_attempts'] + 1;

        $this->deliveryLog->recordDispatchFailure((int)$row['entity_id'], $e->getMessage(), null);
        $this->alertStopped($row, $e->getMessage(), $failedAttempts);
    }

    /**
     * Automatic booking stopped for the order: order comment, admin notification, log.
     *
     * @param array<string, mixed> $row
     * @param string $reason
     * @param int $failedAttempts
     * @return void
     */
    private function alertStopped(array $row, string $reason, int $failedAttempts): void
    {
        $incrementId = (string)$row['increment_id'];

        $this->orderNotes->add((int)$row['order_id'], __(
            'Quickshipper could not book the courier automatically: %1 Ship the order with the Ship button.',
            $reason
        ));
        $this->notifier->addCritical(
            (string)__('Quickshipper did not book a courier for order #%1', $incrementId),
            (string)__('Automatic booking stopped: %1 Open the order and ship it with the Ship button.', $reason)
        );
        $this->logger->critical(sprintf(
            'auto-dispatch: order %s not booked after %d attempt(s), merchant notified: %s',
            $incrementId,
            $failedAttempts,
            $reason
        ));
    }

    /**
     * Booked, but Magento did not save the shipment. The booking stays
     * recorded without a shipment: Ship reuses it, or the clean-up cron
     * cancels it after its grace period if no courier has taken it yet.
     *
     * @param Order $order
     * @param string $qshprOrderNo
     * @param \Throwable $e
     * @return void
     */
    private function savedWithoutShipment(Order $order, string $qshprOrderNo, \Throwable $e): void
    {
        $this->orderNotes->add((int)$order->getId(), __(
            'Quickshipper booked %1 automatically, but the shipment could not be saved: %2 Press Ship within '
            . '15 minutes to use this booking; after that it is cancelled if no courier has taken it yet.',
            $qshprOrderNo,
            $e->getMessage()
        ));
        $this->notifier->addCritical(
            (string)__('Order #%1 was booked with Quickshipper but not shipped', (string)$order->getIncrementId()),
            (string)__(
                'Booking %1 has no shipment because saving it failed: %2 Ship the order within 15 minutes '
                . 'to use the booking.',
                $qshprOrderNo,
                $e->getMessage()
            )
        );
        $this->logger->critical(sprintf(
            'auto-dispatch: order %s booked %s but the shipment save failed: %s',
            $order->getIncrementId(),
            $qshprOrderNo,
            $e->getMessage()
        ), ['exception' => $e]);
    }
}

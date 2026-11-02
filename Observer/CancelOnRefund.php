<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Psr\Log\LoggerInterface;
use Shubo\Qshpr\Model\Carrier\Config;
use Shubo\Qshpr\Model\Delivery\ChosenCourier;
use Shubo\Qshpr\Model\Delivery\DeliveryLog;
use Shubo\Qshpr\Model\Delivery\OrderNotes;
use Shubo\Qshpr\Model\Dispatch\Canceller;
use Shubo\Qshpr\Model\Dispatch\CancelOutcome;
use Shubo\Qshpr\Model\OrderStatus;

/**
 * With "Cancel Delivery on Full Refund" on: once an order is refunded in
 * full, its Quickshipper deliveries still under way are cancelled; one a
 * courier already has is reported to the merchant instead. One already
 * delivered is left alone: a refund after delivery (a return) is normal.
 *
 * Runs after the refund is committed, so a refund that fails is never
 * followed by a cancel, and the refund never waits for Quickshipper inside
 * its database transaction. A paid order cannot be cancelled in Magento,
 * only refunded, which is why the refund is the trigger.
 */
class CancelOnRefund implements ObserverInterface
{
    /** Reason shown on the order in the merchant's Quickshipper dashboard. */
    private const string REASON = 'Order refunded in Magento';

    /**
     * @param Config $config
     * @param DeliveryLog $deliveryLog
     * @param Canceller $canceller
     * @param OrderNotes $orderNotes
     * @param NotifierInterface $notifier
     * @param LoggerInterface $logger Bound to ShuboQshprLogger in di.xml.
     */
    public function __construct(
        private readonly Config $config,
        private readonly DeliveryLog $deliveryLog,
        private readonly Canceller $canceller,
        private readonly OrderNotes $orderNotes,
        private readonly NotifierInterface $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handle sales_order_creditmemo_save_commit_after.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $creditmemo = $observer->getEvent()->getData('creditmemo');
        $order = $creditmemo instanceof Creditmemo ? $creditmemo->getOrder() : null;

        if (!$order instanceof Order
            || ChosenCourier::fromOrder($order) === null
            || !$this->config->isCancelOnRefundEnabled((int)$order->getStoreId())
            || !$this->isFullyRefunded($order)
        ) {
            return;
        }

        foreach ($this->deliveryLog->findShippedUnderWay((int)$order->getId()) as $row) {
            $this->cancel($row);
        }
    }

    /**
     * Cancel one delivery; one that could not be cancelled is reported to the merchant.
     *
     * @param array<string, mixed> $row
     * @return void
     */
    private function cancel(array $row): void
    {
        $orderNo = (string)$row['qshpr_order_no'];

        try {
            [$outcome, , $status] = $this->canceller->cancelForShipment((int)$row['shipment_id'], self::REASON);

            if ($outcome->isOff()) {
                return;
            }

            if ($outcome === CancelOutcome::Ended || $outcome === CancelOutcome::NothingToCancel) {
                $this->logger->info(sprintf(
                    'refund: order %s refunded, delivery %s not cancelled: %s',
                    (string)$row['increment_id'],
                    $orderNo,
                    $status !== '' ? $status : $outcome->value
                ));

                return;
            }

            $reason = (string)(OrderStatus::fromAnswer($status)?->label() ?? $outcome->label());
        } catch (\Throwable $e) {
            $reason = $e->getMessage();
        }

        $message = __(
            'The order was refunded, but Quickshipper delivery %1 could not be cancelled (%2). Cancel it in the '
            . 'Quickshipper dashboard or with the courier company.',
            $orderNo,
            $reason
        );
        $this->orderNotes->add((int)$row['order_id'], $message);
        $this->notifier->addCritical(
            (string)__('Refunded order #%1 still has a Quickshipper delivery', (string)$row['increment_id']),
            (string)$message
        );
        $this->logger->critical(sprintf(
            'refund: order %s refunded, delivery %s not cancelled: %s',
            (string)$row['increment_id'],
            $orderNo,
            $reason
        ));
    }

    /**
     * Refunded in full: something was paid and all of it was refunded.
     *
     * @param Order $order
     * @return bool
     */
    private function isFullyRefunded(Order $order): bool
    {
        $paid = (float)$order->getBaseTotalPaid();

        return $paid > 0 && (float)$order->getBaseTotalRefunded() >= $paid;
    }
}

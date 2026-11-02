<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Status;

use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Phrase;
use Psr\Log\LoggerInterface;
use Shubo\Qshpr\Exception\QshprApiException;
use Shubo\Qshpr\Model\Delivery\DeliveryLog;
use Shubo\Qshpr\Model\Delivery\OrderNotes;
use Shubo\Qshpr\Model\Delivery\ShipmentNotes;
use Shubo\Qshpr\Model\Dispatch\DeliveryLock;
use Shubo\Qshpr\Model\OrderStatus;
use Shubo\Qshpr\Model\QshprClient;

/**
 * Brings a shipped delivery up to date with Quickshipper.
 *
 * Always reads the order from Quickshipper (GET /v1/Order) and never trusts
 * a webhook body: Quickshipper does not sign its webhooks. Works under the
 * order's DeliveryLock. A final state (delivered, cancelled, failed) is
 * never left again, and a status that was already recorded changes nothing,
 * so repeated or late webhooks are harmless.
 *
 * Each change adds a note to the shipment. A cancelled or failed delivery
 * also tells the merchant: order comment, admin notification, log.
 */
class StatusUpdater
{
    /** Wait this long for an order another request is working on. */
    private const int LOCK_WAIT_SECONDS = 5;

    /**
     * @param DeliveryLog $deliveryLog
     * @param QshprClient $client
     * @param DeliveryLock $lock
     * @param ShipmentNotes $shipmentNotes
     * @param OrderNotes $orderNotes
     * @param NotifierInterface $notifier
     * @param LoggerInterface $logger Bound to ShuboQshprLogger in di.xml.
     */
    public function __construct(
        private readonly DeliveryLog $deliveryLog,
        private readonly QshprClient $client,
        private readonly DeliveryLock $lock,
        private readonly ShipmentNotes $shipmentNotes,
        private readonly OrderNotes $orderNotes,
        private readonly NotifierInterface $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Update the delivery booked as Quickshipper order $qshprOrderId.
     *
     * @param int $qshprOrderId
     * @param int|null $lockWaitSeconds Shorter wait (0: give up at once when busy).
     * @return UpdateResult
     * @throws QshprApiException When Quickshipper does not answer; nothing was changed.
     */
    public function update(int $qshprOrderId, ?int $lockWaitSeconds = null): UpdateResult
    {
        $row = $this->deliveryLog->findByQshprOrderId($qshprOrderId);

        if ($row === null) {
            return UpdateResult::NotOurs;
        }

        $orderId = (int)$row['order_id'];

        if (!$this->lock->acquire($orderId, $lockWaitSeconds ?? self::LOCK_WAIT_SECONDS)) {
            return UpdateResult::Busy;
        }

        try {
            return $this->apply((int)$row['entity_id']);
        } finally {
            $this->lock->release($orderId);
        }
    }

    /**
     * Read the order from Quickshipper and record what changed. Caller holds the lock.
     *
     * @param int $entityId
     * @return UpdateResult
     * @throws QshprApiException
     */
    private function apply(int $entityId): UpdateResult
    {
        // Read again under the lock: another update may have just run.
        $row = $this->deliveryLog->find($entityId);

        if ($row === null || $row['state'] !== DeliveryLog::STATE_DISPATCHED || $row['shipment_id'] === null) {
            return UpdateResult::Unchanged;
        }

        $answer = $this->client->getOrder((int)$row['qshpr_order_id'], (int)$row['store_id']);
        $order = (array)($answer['order'] ?? []);
        $statusName = is_scalar($order['status'] ?? null) ? (string)$order['status'] : '';
        $status = OrderStatus::fromAnswer($statusName);
        $courier = (array)($answer['courier'] ?? []);
        $courierName = is_scalar($courier['courierName'] ?? null) && trim((string)$courier['courierName']) !== ''
            ? trim((string)$courier['courierName'])
            : null;

        // A missing courier is no news: the recorded name stays.
        $newCourier = $courierName !== null && $courierName !== $row['courier_name'] ? $courierName : null;

        if ($statusName === '' || ($statusName === $row['qshpr_status'] && $newCourier === null)) {
            $this->deliveryLog->markStatusChecked($entityId);

            return UpdateResult::Unchanged;
        }

        $finalState = match ($status) {
            OrderStatus::Delivered => DeliveryLog::STATE_DELIVERED,
            OrderStatus::Cancelled => DeliveryLog::STATE_CANCELLED,
            OrderStatus::DeliveryFailed => DeliveryLog::STATE_FAILED,
            default => null,
        };

        if (!$this->deliveryLog->recordStatus($entityId, $statusName, $courierName, $finalState)) {
            return UpdateResult::Unchanged;
        }

        $orderNo = (string)$row['qshpr_order_no'];
        $this->shipmentNotes->add((int)$row['shipment_id'], $this->note($status, $statusName, $newCourier));

        if ($status?->isEndedWithoutDelivery()) {
            $this->alert($row, $status, $orderNo);
        }

        $this->logger->info(sprintf(
            'status: order %s, %s is now %s%s',
            (string)$row['increment_id'],
            $orderNo,
            $statusName,
            $finalState !== null ? " (final: $finalState)" : ''
        ));

        return UpdateResult::Updated;
    }

    /**
     * Shipment note for a status.
     *
     * @param OrderStatus|null $status
     * @param string $statusName Quickshipper's name, for a status this module does not know.
     * @param string|null $newCourier The courier's name when it is new or changed.
     * @return Phrase
     */
    private function note(?OrderStatus $status, string $statusName, ?string $newCourier): Phrase
    {
        if ($status === OrderStatus::Assigned && $newCourier !== null) {
            return __('Quickshipper: courier assigned (%1).', $newCourier);
        }

        $label = $status?->label() ?? $statusName;

        return $newCourier !== null
            ? __('Quickshipper: %1 (courier: %2).', $label, $newCourier)
            : __('Quickshipper: %1.', $label);
    }

    /**
     * A shipped delivery will not happen: tell the merchant.
     *
     * @param array<string, mixed> $row
     * @param OrderStatus $status
     * @param string $orderNo
     * @return void
     */
    private function alert(array $row, OrderStatus $status, string $orderNo): void
    {
        $incrementId = (string)$row['increment_id'];
        $message = $status === OrderStatus::Cancelled
            ? __(
                'Quickshipper cancelled delivery %1 after the order was shipped. The shipment stays in Magento; '
                . 'book another courier with Book Courier Again on the shipment, or contact the customer.',
                $orderNo
            )
            : __(
                'Quickshipper reports that delivery %1 failed. The shipment stays in Magento; check the '
                . 'Quickshipper dashboard and contact the customer, or book another courier with Book Courier '
                . 'Again on the shipment.',
                $orderNo
            );

        $this->orderNotes->add((int)$row['order_id'], $message);
        $this->notifier->addCritical(
            (string)__('Quickshipper delivery for order #%1: %2', $incrementId, $status->label()),
            (string)$message
        );
        $this->logger->critical(sprintf(
            'status: order %s, delivery %s is %s after shipping; merchant notified',
            $incrementId,
            $orderNo,
            $status->value
        ));
    }
}

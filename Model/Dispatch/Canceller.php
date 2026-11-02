<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Dispatch;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\ShipmentTrackRepositoryInterface;
use Psr\Log\LoggerInterface;
use Shubo\Qshpr\Exception\QshprApiException;
use Shubo\Qshpr\Model\Delivery\DeliveryLog;
use Shubo\Qshpr\Model\Delivery\OrderNotes;
use Shubo\Qshpr\Model\Delivery\ShipmentNotes;
use Shubo\Qshpr\Model\Money;
use Shubo\Qshpr\Model\OrderStatus;
use Shubo\Qshpr\Model\QshprClient;

/**
 * Cancels a shipped Quickshipper delivery at the merchant's request.
 *
 * Quickshipper cancels only before a courier takes the order, so the order
 * is read first. A cancelled delivery keeps its Magento shipment — Magento
 * cannot undo one — but loses its Quickshipper track, so the customer's
 * Track link no longer leads to a cancelled order. Quickshipper returns the
 * delivery price and may keep its service fee.
 */
class Canceller
{
    /** Wait this long for an order another request is working on. */
    private const int LOCK_WAIT_SECONDS = 15;

    /**
     * @param DeliveryLog $deliveryLog
     * @param QshprClient $client
     * @param DeliveryLock $lock
     * @param ShipmentTrackRepositoryInterface $trackRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param ShipmentNotes $shipmentNotes
     * @param OrderNotes $orderNotes
     * @param LoggerInterface $logger Bound to ShuboQshprLogger in di.xml.
     */
    public function __construct(
        private readonly DeliveryLog $deliveryLog,
        private readonly QshprClient $client,
        private readonly DeliveryLock $lock,
        private readonly ShipmentTrackRepositoryInterface $trackRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ShipmentNotes $shipmentNotes,
        private readonly OrderNotes $orderNotes,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Cancel the delivery under way for a shipment.
     *
     * @param int $shipmentId
     * @param string $reason Shown on the order in the Quickshipper dashboard.
     * @return array{CancelOutcome, array<string, mixed>|null, string} Outcome, the delivery row, Quickshipper's status.
     * @throws QshprApiException When Quickshipper cannot be asked for the order, or refuses the cancel.
     */
    public function cancelForShipment(int $shipmentId, string $reason): array
    {
        $row = $this->deliveryLog->findLatestForShipment($shipmentId);

        if ($row === null || $row['state'] !== DeliveryLog::STATE_DISPATCHED) {
            return [CancelOutcome::NothingToCancel, $row, ''];
        }

        $orderId = (int)$row['order_id'];

        if (!$this->lock->acquire($orderId, self::LOCK_WAIT_SECONDS)) {
            return [CancelOutcome::Busy, $row, ''];
        }

        try {
            // Read again under the lock: a status update may have just ended it.
            $row = $this->deliveryLog->find((int)$row['entity_id']) ?? $row;

            return $this->cancel($row, $reason);
        } finally {
            $this->lock->release($orderId);
        }
    }

    /**
     * Cancel one delivery row. Caller holds the order's lock.
     *
     * @param array<string, mixed> $row
     * @param string $reason
     * @return array{CancelOutcome, array<string, mixed>, string}
     * @throws QshprApiException
     */
    private function cancel(array $row, string $reason): array
    {
        if ($row['state'] !== DeliveryLog::STATE_DISPATCHED) {
            return [CancelOutcome::Ended, $row, (string)($row['qshpr_status'] ?? $row['state'])];
        }

        $entityId = (int)$row['entity_id'];
        $qshprOrderId = (int)$row['qshpr_order_id'];
        $order = (array)($this->client->getOrder($qshprOrderId, (int)$row['store_id'])['order'] ?? []);
        $statusName = is_scalar($order['status'] ?? null) ? (string)$order['status'] : '';
        $status = OrderStatus::fromAnswer($statusName);

        if ($status === OrderStatus::Delivered || $status === OrderStatus::DeliveryFailed) {
            // The row becomes final, so no status update will note this later.
            if ($this->deliveryLog->recordStatus(
                $entityId,
                $statusName,
                null,
                $status === OrderStatus::Delivered ? DeliveryLog::STATE_DELIVERED : DeliveryLog::STATE_FAILED
            )) {
                $this->shipmentNotes->add((int)$row['shipment_id'], __('Quickshipper: %1.', $status->label()));
            }

            return [CancelOutcome::Ended, $row, $statusName];
        }

        if ($status === OrderStatus::Cancelled) {
            $this->recordCancelled($row, $statusName, $reason, cancelledNow: false);

            return [CancelOutcome::WasCancelled, $row, $statusName];
        }

        if ($status === null) {
            return [CancelOutcome::UnknownStatus, $row, $statusName];
        }

        if (!$status->isBeforeCourier()) {
            return [CancelOutcome::CourierHasIt, $row, $statusName];
        }

        try {
            $this->client->cancelOrder($qshprOrderId, $reason, (int)$row['store_id']);
        } catch (QshprApiException $e) {
            if ($e->getApiErrorCode() !== null) {
                throw $e;
            }

            return $this->afterUnansweredCancel($row, $reason, $e);
        }

        $this->recordCancelled($row, OrderStatus::Cancelled->value, $reason, cancelledNow: true);

        return [CancelOutcome::Cancelled, $row, OrderStatus::Cancelled->value];
    }

    /**
     * The cancel got no answer, so it may have gone through: read the order once more.
     *
     * Recording it as cancelled now keeps the track off the shipment and
     * stops the next status update from reporting a cancel the merchant
     * did not ask for.
     *
     * @param array<string, mixed> $row
     * @param string $reason
     * @param QshprApiException $e The unanswered cancel.
     * @return array{CancelOutcome, array<string, mixed>, string}
     */
    private function afterUnansweredCancel(array $row, string $reason, QshprApiException $e): array
    {
        try {
            $answer = $this->client->getOrder((int)$row['qshpr_order_id'], (int)$row['store_id']);
            $order = (array)($answer['order'] ?? []);
            $statusName = is_scalar($order['status'] ?? null) ? (string)$order['status'] : '';
        } catch (QshprApiException) {
            $statusName = '';
        }

        if (OrderStatus::fromAnswer($statusName) === OrderStatus::Cancelled) {
            $this->recordCancelled($row, $statusName, $reason, cancelledNow: true);

            return [CancelOutcome::Cancelled, $row, $statusName];
        }

        $this->logger->warning(sprintf(
            'cancel: order %s, delivery %s: no answer to the cancel (%s), status now %s',
            (string)$row['increment_id'],
            (string)$row['qshpr_order_no'],
            $e->getMessage(),
            $statusName !== '' ? $statusName : 'unknown'
        ));

        return [CancelOutcome::NoAnswer, $row, $statusName];
    }

    /**
     * The delivery is off: record it, remove its track, leave notes.
     *
     * @param array<string, mixed> $row
     * @param string $statusName
     * @param string $reason
     * @param bool $cancelledNow Cancelled by this request (else found cancelled).
     * @return void
     */
    private function recordCancelled(array $row, string $statusName, string $reason, bool $cancelledNow): void
    {
        $orderNo = (string)$row['qshpr_order_no'];
        $shipmentId = (int)$row['shipment_id'];

        $this->deliveryLog->recordStatus((int)$row['entity_id'], $statusName, null, DeliveryLog::STATE_CANCELLED);
        $this->removeTrack($shipmentId, $orderNo);
        $this->shipmentNotes->add($shipmentId, $cancelledNow
            ? __('Quickshipper delivery %1 cancelled from Magento (%2); its tracking number was removed.', $orderNo, $reason)
            : __('Quickshipper delivery %1 had been cancelled at Quickshipper; its tracking number was removed.', $orderNo));
        $charged = (int)$row['charged_amount'];
        // 0 when Quickshipper's price could not be read at booking.
        $this->orderNotes->add((int)$row['order_id'], $charged > 0
            ? __(
                'Quickshipper delivery %1 is cancelled. Quickshipper returns the delivery price (%2 GEL) and may keep '
                . 'its service fee.',
                $orderNo,
                Money::toGel($charged)
            )
            : __(
                'Quickshipper delivery %1 is cancelled. Quickshipper returns the delivery price and may keep its '
                . 'service fee.',
                $orderNo
            ));
        $this->logger->info(sprintf(
            'cancel: order %s, delivery %s %s',
            (string)$row['increment_id'],
            $orderNo,
            $cancelledNow ? 'cancelled at Quickshipper' : 'found cancelled at Quickshipper'
        ));
    }

    /**
     * Delete the shipment's track carrying the Quickshipper order number.
     *
     * @param int $shipmentId
     * @param string $orderNo
     * @return void
     */
    private function removeTrack(int $shipmentId, string $orderNo): void
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('parent_id', $shipmentId)
            ->addFilter('track_number', $orderNo)
            ->create();

        foreach ($this->trackRepository->getList($criteria)->getItems() as $track) {
            $this->trackRepository->delete($track);
        }
    }
}

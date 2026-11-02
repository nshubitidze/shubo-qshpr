<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Cron;

use Laminas\Http\Response as HttpResponse;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Phrase;
use Psr\Log\LoggerInterface;
use Shubo\Qshpr\Exception\QshprApiException;
use Shubo\Qshpr\Model\Delivery\DeliveryLog;
use Shubo\Qshpr\Model\Delivery\OrderNotes;
use Shubo\Qshpr\Model\Dispatch\DeliveryLock;
use Shubo\Qshpr\Model\Money;
use Shubo\Qshpr\Model\OrderStatus;
use Shubo\Qshpr\Model\QshprClient;

/**
 * Settles Quickshipper bookings whose Magento shipment was never saved.
 *
 * Such a booking is left when the shipment save failed after Quickshipper
 * accepted, or a Draft could not be confirmed, and the merchant did not
 * submit the shipment again (which would have reused it). The price is
 * held at Quickshipper and, unless cancelled, a courier may come for a
 * parcel Magento does not consider shipped. For each booking untouched for
 * the grace period, Quickshipper's own status decides:
 *
 * - sent to the courier company, no courier yet → cancelled (the price comes
 *   back; Quickshipper may keep its service fee) and marked abandoned;
 * - already cancelled or failed at Quickshipper → marked abandoned;
 * - a Draft (Quickshipper cannot cancel or delete one), a courier already
 *   assigned, or a refused cancel → the merchant is told once, on the order,
 *   in the admin notifications and in the log, and the booking stays for a
 *   retried shipment to use;
 * - a cancel without an answer (it may have gone through) → nothing is
 *   recorded; the next run reads the status again.
 *
 * Each booking is handled under the order's DeliveryLock, and the grace
 * period keeps a shipment that is still being saved out of reach.
 */
class CleanUpDeliveries
{
    /** Bookings younger than this are left alone: their shipment may still be saving. */
    private const int GRACE_MINUTES = 15;

    /** Bookings per run; a backlog drains over several runs. */
    private const int BATCH_LIMIT = 50;

    /** Do not wait for an order a shipment is booking right now; it is due again next run. */
    private const int LOCK_WAIT_SECONDS = 0;

    /** Reason shown on the cancelled order in the merchant's Quickshipper dashboard. */
    public const string CANCEL_COMMENT = 'Cancelled by Magento: the shipment for this booking was never saved.';

    /**
     * @param DeliveryLog $deliveryLog
     * @param QshprClient $client
     * @param DeliveryLock $lock
     * @param OrderNotes $orderNotes
     * @param NotifierInterface $notifier
     * @param LoggerInterface $logger Bound to ShuboQshprLogger in di.xml.
     */
    public function __construct(
        private readonly DeliveryLog $deliveryLog,
        private readonly QshprClient $client,
        private readonly DeliveryLock $lock,
        private readonly OrderNotes $orderNotes,
        private readonly NotifierInterface $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handle every due booking; one failure never stops the run.
     *
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->deliveryLog->findStaleUnlinked(self::GRACE_MINUTES, self::BATCH_LIMIT) as $row) {
            $orderId = (int)$row['order_id'];

            if (!$this->lock->acquire($orderId, self::LOCK_WAIT_SECONDS)) {
                continue;
            }

            try {
                $this->cleanUp((int)$row['entity_id']);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'clean-up: delivery %d (Quickshipper id %d, order %s) failed, retried next run: %s',
                    (int)$row['entity_id'],
                    (int)$row['qshpr_order_id'],
                    (string)$row['increment_id'],
                    $e->getMessage()
                ));
            } finally {
                $this->lock->release($orderId);
            }
        }
    }

    /**
     * Settle one booking without a shipment.
     *
     * @param int $entityId
     * @return void
     * @throws QshprApiException When Quickshipper cannot be asked; the next run retries.
     */
    private function cleanUp(int $entityId): void
    {
        // Read again under the lock: a retried shipment may have used it since.
        $row = $this->deliveryLog->find($entityId);

        if ($row === null
            || $row['shipment_id'] !== null
            || $row['alerted_at'] !== null
            || !in_array($row['state'], DeliveryLog::BOOKED_STATES, true)
        ) {
            return;
        }

        $qshprOrderId = (int)$row['qshpr_order_id'];
        $storeId = (int)$row['store_id'];
        $orderNo = (string)($row['qshpr_order_no'] ?? $qshprOrderId);

        try {
            $details = (array)($this->client->getOrder($qshprOrderId, $storeId)['order'] ?? []);
        } catch (QshprApiException $e) {
            // Quickshipper answers 404 for an order it does not know.
            if ($e->getApiErrorCode() !== HttpResponse::STATUS_CODE_404) {
                throw $e;
            }

            // Unknown to this Quickshipper account, e.g. booked before a
            // switch between the test and live environments: over, like a cancelled one.
            $details = ['status' => OrderStatus::Cancelled->value, 'notFound' => true];
        }

        $status = OrderStatus::fromAnswer($details['status'] ?? null);
        $statusName = match (true) {
            isset($details['notFound']) => 'not found',
            is_scalar($details['status'] ?? null) => (string)$details['status'],
            default => 'unknown',
        };

        if ($status !== null && $status->isEndedWithoutDelivery()) {
            $this->deliveryLog->markAbandoned($entityId);
            $this->orderNotes->add((int)$row['order_id'], __(
                'Quickshipper booking %1 was never shipped in Magento and is %2 at Quickshipper; '
                . 'it is no longer counted as a delivery of this order.',
                $orderNo,
                $statusName
            ));
            $this->logger->info(sprintf(
                'clean-up: order %s: unshipped booking %s (Quickshipper id %d) is %s; marked abandoned',
                (string)$row['increment_id'],
                $orderNo,
                $qshprOrderId,
                $statusName
            ));

            return;
        }

        if ($status !== null && $status->isBeforeCourier()) {
            $this->cancel($row, $orderNo);

            return;
        }

        $this->alert($row, $status === OrderStatus::Draft
            ? __(
                'Quickshipper booking %1 is a Draft that was never confirmed, and no shipment in Magento carries '
                . 'it. Quickshipper holds its delivery price and cannot cancel a Draft. Submit the shipment '
                . 'again to confirm and use it, or settle it in the Quickshipper dashboard.',
                $orderNo
            )
            : __(
                'Quickshipper booking %1 has no shipment in Magento, but Quickshipper reports it as %2, so it '
                . 'was not cancelled automatically. Submit the shipment again to use it, or settle it in the '
                . 'Quickshipper dashboard.',
                $orderNo,
                $statusName
            ), sprintf('Quickshipper status %s', $statusName));
    }

    /**
     * Cancel a booking no courier has taken yet; tell the merchant if Quickshipper refuses.
     *
     * @param array<string, mixed> $row
     * @param string $orderNo
     * @return void
     * @throws QshprApiException When Quickshipper did not answer the cancel; the next run reads the status again.
     */
    private function cancel(array $row, string $orderNo): void
    {
        $qshprOrderId = (int)$row['qshpr_order_id'];

        try {
            $this->client->cancelOrder($qshprOrderId, self::CANCEL_COMMENT, (int)$row['store_id']);
        } catch (QshprApiException $e) {
            // Without an answer the cancel may have gone through: telling the
            // merchant "not cancelled" could be wrong, and the alert would hide
            // the booking for good. The next run sees the real status.
            if ($e->getApiErrorCode() === null) {
                throw $e;
            }

            $this->alert($row, __(
                'Quickshipper booking %1 has no shipment in Magento and could not be cancelled: %2 '
                . 'Submit the shipment again to use it, or settle it in the Quickshipper dashboard.',
                $orderNo,
                $e->getMessage()
            ), sprintf('cancel refused: %s', $e->getMessage()));

            return;
        }

        $this->deliveryLog->markAbandoned((int)$row['entity_id']);
        $this->orderNotes->add((int)$row['order_id'], __(
            'Quickshipper booking %1 (%2 GEL) was cancelled: no shipment in Magento carries it. '
            . 'Quickshipper returns the delivery price and may keep its service fee.',
            $orderNo,
            Money::toGel((int)$row['charged_amount'])
        ));
        $this->logger->info(sprintf(
            'clean-up: order %s: unshipped booking %s (Quickshipper id %d) cancelled at Quickshipper; marked abandoned',
            (string)$row['increment_id'],
            $orderNo,
            $qshprOrderId
        ));
    }

    /**
     * Tell the merchant, once, about a booking only they can settle.
     *
     * Order comment, admin notification (its title names the order) and a log line.
     *
     * @param array<string, mixed> $row
     * @param Phrase $comment Order comment for the merchant.
     * @param string $reason For the log line.
     * @return void
     */
    private function alert(array $row, Phrase $comment, string $reason): void
    {
        if (!$this->deliveryLog->markAlerted((int)$row['entity_id'])) {
            return;
        }

        $this->orderNotes->add((int)$row['order_id'], $comment);
        $this->notifier->addCritical(
            (string)__('Quickshipper booking for order #%1 needs you', (string)$row['increment_id']),
            (string)$comment
        );
        $this->logger->critical(sprintf(
            'clean-up: order %s: booking %s (Quickshipper id %d) has no shipment and needs the merchant (%s)',
            (string)$row['increment_id'],
            (string)($row['qshpr_order_no'] ?? '-'),
            (int)$row['qshpr_order_id'],
            $reason
        ));
    }
}

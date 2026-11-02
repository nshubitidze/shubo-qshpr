<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Cron;

use Magento\Framework\Notification\NotifierInterface;
use Psr\Log\LoggerInterface;
use Shubo\Qshpr\Model\Delivery\DeliveryLog;
use Shubo\Qshpr\Model\Delivery\OrderNotes;
use Shubo\Qshpr\Model\Status\StatusUpdater;

/**
 * The safety net behind the status webhook: asks Quickshipper about shipped
 * deliveries that are still under way, so a lost webhook (store down, URL
 * changed, no public URL at all) only delays a status.
 *
 * A delivery with no news for a week is reported to the merchant once and
 * no longer polled.
 */
class StatusSync
{
    /** Ask again about a delivery after this long. */
    private const int CHECK_EVERY_MINUTES = 15;

    /** Deliveries per run. */
    private const int BATCH_LIMIT = 50;

    /** No status change for this long: report, stop polling. */
    private const int STALE_AFTER_DAYS = 7;

    /** Do not wait for an order a webhook or shipment is working on; it is due again next run. */
    private const int LOCK_WAIT_SECONDS = 0;

    private const int SECONDS_PER_DAY = 86400;

    /**
     * @param DeliveryLog $deliveryLog
     * @param StatusUpdater $statusUpdater
     * @param OrderNotes $orderNotes
     * @param NotifierInterface $notifier
     * @param LoggerInterface $logger Bound to ShuboQshprLogger in di.xml.
     */
    public function __construct(
        private readonly DeliveryLog $deliveryLog,
        private readonly StatusUpdater $statusUpdater,
        private readonly OrderNotes $orderNotes,
        private readonly NotifierInterface $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Check every due delivery; one failure never stops the run.
     *
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->deliveryLog->findStatusDue(self::CHECK_EVERY_MINUTES, self::BATCH_LIMIT) as $row) {
            try {
                if ($this->isStale($row)) {
                    $this->reportStale($row);

                    continue;
                }

                $this->statusUpdater->update((int)$row['qshpr_order_id'], self::LOCK_WAIT_SECONDS);
            } catch (\Throwable $e) {
                // Counted as asked, so a delivery that keeps failing waits its
                // turn instead of coming first in every run.
                $this->deliveryLog->markStatusChecked((int)$row['entity_id']);
                $this->logger->error(sprintf(
                    'status sync: order %s, %s failed, retried in %d minutes: %s',
                    (string)$row['increment_id'],
                    (string)$row['qshpr_order_no'],
                    self::CHECK_EVERY_MINUTES,
                    $e->getMessage()
                ), ['exception' => $e]);
            }
        }
    }

    /**
     * No status change (or, before the first one, since the booking) for a week.
     *
     * The age is compared against the database clock that wrote the row.
     * Before the first status the booking time counts: updated_at changes
     * with every check, so it would never grow old.
     *
     * @param array<string, mixed> $row
     * @return bool
     */
    private function isStale(array $row): bool
    {
        $last = (string)($row['status_changed_at'] ?? $row['created_at']);

        return $this->deliveryLog->secondsSince($last) > self::STALE_AFTER_DAYS * self::SECONDS_PER_DAY;
    }

    /**
     * Tell the merchant once, and stop polling the delivery.
     *
     * @param array<string, mixed> $row
     * @return void
     */
    private function reportStale(array $row): void
    {
        if (!$this->deliveryLog->markAlerted((int)$row['entity_id'])) {
            return;
        }

        $orderNo = (string)$row['qshpr_order_no'];
        $message = __(
            'Quickshipper delivery %1 has had no status change for %2 days (last: %3). It is no longer checked '
            . 'automatically; look it up in the Quickshipper dashboard.',
            $orderNo,
            self::STALE_AFTER_DAYS,
            (string)($row['qshpr_status'] ?? '-')
        );
        $this->orderNotes->add((int)$row['order_id'], $message);
        $this->notifier->addCritical(
            (string)__('Quickshipper delivery for order #%1 has no news', (string)$row['increment_id']),
            (string)$message
        );
        $this->logger->critical(sprintf(
            'status sync: order %s, %s has had no news for %d days; merchant notified, polling stopped',
            (string)$row['increment_id'],
            $orderNo,
            self::STALE_AFTER_DAYS
        ));
    }
}

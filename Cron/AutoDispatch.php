<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Cron;

use Psr\Log\LoggerInterface;
use Shubo\Qshpr\Model\Delivery\DeliveryLog;
use Shubo\Qshpr\Model\Dispatch\AutoShipper;
use Shubo\Qshpr\Model\Dispatch\DeliveryLock;
use Shubo\Qshpr\Model\Schedule\OpeningHours;

/**
 * Books and ships paid orders whose delivery is due for automatic booking
 * (see AutoDispatchTrigger), one order at a time under its DeliveryLock.
 *
 * An "as soon as possible" delivery of a shop that keeps opening hours is
 * not booked while the shop is closed: it is made due again at opening.
 * A delivery with a chosen time is booked at once, for that time.
 */
class AutoDispatch
{
    /** Orders per run; each one waits for Quickshipper, so keep a run short. */
    private const int BATCH_LIMIT = 20;

    /** Do not wait for an order the admin is shipping right now; it is due again next run. */
    private const int LOCK_WAIT_SECONDS = 0;

    /**
     * @param DeliveryLog $deliveryLog
     * @param AutoShipper $autoShipper
     * @param DeliveryLock $lock
     * @param LoggerInterface $logger Bound to ShuboQshprLogger in di.xml.
     * @param OpeningHours $openingHours
     */
    public function __construct(
        private readonly DeliveryLog $deliveryLog,
        private readonly AutoShipper $autoShipper,
        private readonly DeliveryLock $lock,
        private readonly LoggerInterface $logger,
        private readonly OpeningHours $openingHours,
    ) {
    }

    /**
     * Ship every due order; one failure never stops the run.
     *
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->deliveryLog->findDispatchDue(self::BATCH_LIMIT) as $due) {
            $orderId = (int)$due['order_id'];
            $row = null;

            if ($this->waitsForOpening($due)) {
                continue;
            }

            if (!$this->lock->acquire($orderId, self::LOCK_WAIT_SECONDS)) {
                continue;
            }

            try {
                // Read again under the lock: the admin may have shipped it meanwhile.
                $row = $this->deliveryLog->find((int)$due['entity_id']);

                if ($row !== null && $row['state'] === DeliveryLog::STATE_QUOTED && $row['dispatch_due_at'] !== null) {
                    $this->autoShipper->ship($row);
                }
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'auto-dispatch: order %s failed: %s',
                    (string)$due['increment_id'],
                    $e->getMessage()
                ), ['exception' => $e]);
                $this->stop($row ?? $due, $e);
            } finally {
                $this->lock->release($orderId);
            }
        }
    }

    /**
     * Postpone an "as soon as possible" delivery while its shop is closed.
     *
     * @param array<string,mixed> $due
     * @return bool Whether it waits.
     */
    private function waitsForOpening(array $due): bool
    {
        if (($due['scheduled_time'] ?? null) !== null) {
            return false;
        }

        $opening = $this->openingHours->nextOpening((int)$due['store_id']);

        if ($opening === null) {
            return false;
        }

        $this->deliveryLog->postponeDispatch((int)$due['entity_id'], $opening->getTimestamp() - time());
        $this->logger->info(sprintf(
            'auto-dispatch: order %s waits until the shop opens (%s)',
            (string)$due['increment_id'],
            $opening->format(\DateTimeInterface::ATOM)
        ));

        return true;
    }

    /**
     * Take an order that failed unexpectedly out of automatic booking. Left
     * due, it would fail again every minute, and the oldest due orders come
     * first, so a few of them would hold up every other order.
     *
     * @param array<string, mixed> $row
     * @param \Throwable $e
     * @return void
     */
    private function stop(array $row, \Throwable $e): void
    {
        try {
            $this->autoShipper->stopAfterError($row, $e);
        } catch (\Throwable $stopError) {
            $this->logger->critical(sprintf(
                'auto-dispatch: order %s could not be taken out of automatic booking: %s',
                (string)$row['increment_id'],
                $stopError->getMessage()
            ), ['exception' => $stopError]);
        }
    }
}

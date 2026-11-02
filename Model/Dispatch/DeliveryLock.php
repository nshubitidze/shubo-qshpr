<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Dispatch;

use Magento\Framework\Lock\LockManagerInterface;

/**
 * One lock per Magento order around its Quickshipper bookings: booking from
 * a shipment and the clean-up cron take the same name, so two admins
 * submitting a shipment at once cannot both book a courier, and the cron
 * never touches a booking while a shipment is using it.
 *
 * With Magento's default database lock backend the lock belongs to the
 * database connection, so it is also released when the request ends.
 */
class DeliveryLock
{
    /** Hashed below: the lock backend limits name length. */
    private const string LOCK_PREFIX = 'QSHPR_ORD_';

    /** Longer than a booking normally takes (price check, create, confirm). */
    private const int LOCK_TIMEOUT_SECONDS = 15;

    /**
     * @param LockManagerInterface $lockManager
     */
    public function __construct(
        private readonly LockManagerInterface $lockManager,
    ) {
    }

    /**
     * Acquire the order's lock, waiting up to the timeout.
     *
     * @param int $orderId Magento order entity id.
     * @param int|null $timeoutSeconds Shorter wait (e.g. 0 for the cron: skip a busy order).
     * @return bool False when the lock could not be acquired in time.
     */
    public function acquire(int $orderId, ?int $timeoutSeconds = null): bool
    {
        return $this->lockManager->lock($this->name($orderId), $timeoutSeconds ?? self::LOCK_TIMEOUT_SECONDS);
    }

    /**
     * Release the order's lock.
     *
     * @param int $orderId
     * @return void
     */
    public function release(int $orderId): void
    {
        $this->lockManager->unlock($this->name($orderId));
    }

    /**
     * Whether anyone, this request included, holds the order's lock.
     *
     * @param int $orderId
     * @return bool
     */
    public function isLocked(int $orderId): bool
    {
        return $this->lockManager->isLocked($this->name($orderId));
    }

    /**
     * Lock name of an order.
     *
     * @param int $orderId
     * @return string
     */
    private function name(int $orderId): string
    {
        return self::LOCK_PREFIX . sha1((string)$orderId);
    }
}

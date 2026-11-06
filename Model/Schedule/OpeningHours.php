<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Schedule;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Shubo\Qshpr\Model\Carrier\Config;

/**
 * The shop's opening hours, in the store's time zone: the same hours on
 * every day it is open, and the weekdays it is closed.
 *
 * Neither Magento nor Quickshipper knows when a shop can hand a parcel to a
 * courier: Magento's "Store Hours of Operation" is free text, and an
 * instant courier is quoted at any hour. When the merchant keeps opening
 * hours, a courier is not promised or booked "as soon as possible" while
 * the shop is closed.
 */
class OpeningHours
{
    private const string LABEL_FORMAT = 'D H:i';

    /**
     * @param Config $config
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        private readonly Config $config,
        private readonly TimezoneInterface $timezone,
    ) {
    }

    private const int DAYS_PER_WEEK = 7;

    /**
     * When the shop opens next, in store time.
     *
     * Null when it is open now or opening hours are not kept. Opening hours
     * that end before they start are treated as not kept, so a setting
     * mistake never closes the shop for good (nor does marking every day
     * closed, see Config::getClosedDays()).
     *
     * @param int|null $storeId
     * @param \DateTimeImmutable|null $now
     * @return \DateTimeImmutable|null
     */
    public function nextOpening(?int $storeId = null, ?\DateTimeImmutable $now = null): ?\DateTimeImmutable
    {
        $opensAt = $this->config->getOpensAt($storeId);
        $closesAt = $this->config->getClosesAt($storeId);

        if (!$this->config->keepsOpeningHours($storeId) || $opensAt >= $closesAt) {
            return null;
        }

        $zone = new \DateTimeZone($this->timezone->getConfigTimezone(null, $storeId));
        $now = ($now ?? new \DateTimeImmutable())->setTimezone($zone);

        if ($this->isOpenOn($now, $storeId)) {
            $opensToday = $now->setTime($opensAt, 0);

            if ($now < $opensToday) {
                return $opensToday;
            }

            if ($now < $now->setTime($closesAt, 0)) {
                return null;
            }
        }

        for ($day = 1; $day <= self::DAYS_PER_WEEK; $day++) {
            $date = $now->setTime(0, 0)->modify(sprintf('+%d days', $day));

            if ($this->isOpenOn($date, $storeId)) {
                return $date->setTime($opensAt, 0);
            }
        }

        return null;
    }

    /**
     * Whether the shop is open on a date's weekday (whatever the hour).
     *
     * @param \DateTimeImmutable $date In store time.
     * @param int|null $storeId
     * @return bool
     */
    public function isOpenOn(\DateTimeImmutable $date, ?int $storeId = null): bool
    {
        return !in_array((int)$date->format('w'), $this->config->getClosedDays($storeId), true);
    }

    /**
     * When the shop opens, as the customer sees it ("Tue 10:00").
     *
     * @param \DateTimeImmutable $opening
     * @return string
     */
    public function describe(\DateTimeImmutable $opening): string
    {
        return $opening->format(self::LABEL_FORMAT);
    }
}

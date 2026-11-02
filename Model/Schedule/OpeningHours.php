<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Schedule;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Shubo\Qshpr\Model\Carrier\Config;

/**
 * The shop's daily opening hours, in the store's time zone.
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

    /**
     * When the shop opens next, in store time.
     *
     * Null when it is open now or opening hours are not kept. Opening hours
     * that end before they start are treated as not kept, so a setting
     * mistake never closes the shop for good.
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
        $opensToday = $now->setTime($opensAt, 0);

        if ($now < $opensToday) {
            return $opensToday;
        }

        return $now < $now->setTime($closesAt, 0) ? null : $opensToday->modify('+1 day');
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

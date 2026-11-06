<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Schedule;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Shubo\Qshpr\Model\Carrier\Config;
use Shubo\Qshpr\Model\Delivery\ChosenCourier;

/**
 * The delivery times a customer can choose at checkout, from the merchant's
 * settings, in the store's time zone.
 *
 * A slot's value is its start in UTC (ISO 8601, "2026-10-04T06:00:00Z"),
 * which is what Quickshipper's ScheduledDate and scheduledTime take; its
 * label is the slot in store time ("Sat, 4 Oct, 10:00–12:00").
 *
 * A courier takes a delivery time when Quickshipper says so in its price
 * answer (hasScheduledDelivery), whatever the price is called.
 */
class DeliverySlots
{
    public const string UTC_FORMAT = 'Y-m-d\TH:i:s\Z';

    private const string LABEL_DAY_FORMAT = 'D, j M';
    private const string LABEL_TIME_FORMAT = 'H:i';
    private const string UTC = 'UTC';

    /**
     * @param Config $config
     * @param TimezoneInterface $timezone
     * @param ScheduledCouriers $scheduledCouriers
     */
    public function __construct(
        private readonly Config $config,
        private readonly TimezoneInterface $timezone,
        private readonly ScheduledCouriers $scheduledCouriers,
    ) {
    }

    /**
     * The slots that can be chosen now.
     *
     * @param int|null $storeId
     * @param \DateTimeImmutable|null $now Defaults to the current time.
     * @return array<int, array{value: string, label: string}>
     */
    public function getSlots(?int $storeId = null, ?\DateTimeImmutable $now = null): array
    {
        if (!$this->config->isScheduleEnabled($storeId)) {
            return [];
        }

        $zone = new \DateTimeZone($this->timezone->getConfigTimezone(null, $storeId));
        $now = ($now ?? new \DateTimeImmutable())->setTimezone($zone);
        $earliest = $now->modify(sprintf('+%d hours', $this->config->getScheduleNoticeHours($storeId)));
        $first = $this->config->getOpensAt($storeId);
        $last = $this->config->getClosesAt($storeId);
        $length = $this->config->getScheduleSlotHours($storeId);
        $days = $this->config->getScheduleDays($storeId);
        $closedDays = $this->config->getClosedDays($storeId);
        $openDaysAhead = 0;
        $slots = [];

        // Today, then $days open days after it; closed days are skipped
        // (never all seven, see Config::getClosedDays()).
        for ($day = 0; $day === 0 || $openDaysAhead < $days; $day++) {
            $date = $now->setTime(0, 0)->modify(sprintf('+%d days', $day));

            if (in_array((int)$date->format('w'), $closedDays, true)) {
                continue;
            }

            if ($day > 0) {
                $openDaysAhead++;
            }

            for ($hour = $first; $hour + $length <= $last; $hour += $length) {
                $start = $date->setTime($hour, 0);

                if ($start < $earliest) {
                    continue;
                }

                $value = $start->setTimezone(new \DateTimeZone(self::UTC))->format(self::UTC_FORMAT);
                $slots[] = ['value' => $value, 'label' => $this->describe($value, $storeId)];
            }
        }

        return $slots;
    }

    /**
     * A chosen slot in store time ("Sat, 4 Oct, 10:00–12:00"), whether or not it can still be chosen.
     *
     * @param string $value A slot value (UTC start).
     * @param int|null $storeId
     * @return string The value itself when it is not a valid time.
     */
    public function describe(string $value, ?int $storeId = null): string
    {
        $start = \DateTimeImmutable::createFromFormat(self::UTC_FORMAT, $value, new \DateTimeZone(self::UTC));

        if ($start === false) {
            return $value;
        }

        $start = $start->setTimezone(new \DateTimeZone($this->timezone->getConfigTimezone(null, $storeId)));
        $end = $start->modify(sprintf('+%d hours', $this->config->getScheduleSlotHours($storeId)));

        return $start->format(self::LABEL_DAY_FORMAT) . ', '
            . $start->format(self::LABEL_TIME_FORMAT) . '–' . $end->format(self::LABEL_TIME_FORMAT);
    }

    /**
     * The label of a slot that can still be chosen; null when it cannot.
     *
     * @param string $value A slot value (UTC start).
     * @param int|null $storeId
     * @return string|null
     */
    public function getLabel(string $value, ?int $storeId = null): ?string
    {
        foreach ($this->getSlots($storeId) as $slot) {
            if ($slot['value'] === $value) {
                return $slot['label'];
            }
        }

        return null;
    }

    /**
     * Whether a shipping method is a Quickshipper courier that takes a delivery time.
     *
     * @param string $shippingMethod e.g. "shuboqshpr_2_asap".
     * @return bool
     */
    public function acceptsTime(string $shippingMethod): bool
    {
        $chosen = ChosenCourier::fromShippingMethod($shippingMethod);

        return $chosen !== null && $this->scheduledCouriers->takesTime($chosen->providerId);
    }
}

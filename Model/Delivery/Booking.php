<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Delivery;

/**
 * A courier booked at Quickshipper for one shipment.
 */
class Booking
{
    /**
     * @param int $providerId Quickshipper's courier id.
     * @param string $providerName e.g. "Wolt".
     * @param int $qshprOrderId Quickshipper's order id.
     * @param string $qshprOrderNo Order number people see, e.g. "SQ42609" (the tracking number).
     * @param string|null $trackingUrl Quickshipper's tracking page for the order.
     * @param int $chargedAmount Courier price Quickshipper charged, in tetri.
     * @param int|null $serviceFee Quickshipper's platform fee, in tetri, when reported.
     * @param int $paidAmount Shipping the customer paid for this delivery, in tetri.
     * @param bool $isExtraDelivery Another delivery for an order that already had one (partial shipment).
     * @param bool $isReused Booked by an earlier Submit Shipment whose shipment was not saved; nothing new was booked.
     */
    public function __construct(
        public readonly int $providerId,
        public readonly string $providerName,
        public readonly int $qshprOrderId,
        public readonly string $qshprOrderNo,
        public readonly ?string $trackingUrl,
        public readonly int $chargedAmount,
        public readonly ?int $serviceFee,
        public readonly int $paidAmount,
        public readonly bool $isExtraDelivery,
        public readonly bool $isReused = false,
    ) {
    }

    /**
     * Whether Quickshipper charged more than the customer paid for this delivery.
     *
     * @return bool
     */
    public function isChargedAbovePaid(): bool
    {
        return $this->chargedAmount > $this->paidAmount;
    }
}

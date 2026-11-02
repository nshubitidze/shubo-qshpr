<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model;

use Magento\Framework\Phrase;

/**
 * Status of a Quickshipper order, as GET /v1/Order and POST /v1/Order report it.
 *
 * Which one a new order gets depends on a setting in the merchant's
 * Quickshipper dashboard: "assign the courier automatically" creates it
 * ReadyForPickup, "after confirmation" creates it Draft, and only
 * POST /v1/Order/confirm sends a Draft on. The order field autoAssign does
 * not override that setting. A Draft can be neither cancelled nor deleted,
 * and its delivery price is already held.
 */
enum OrderStatus: string
{
    case Draft = 'Draft';
    case ReadyForPickup = 'ReadyForPickup';
    case WaitingForCourier = 'WaitingForCourier';
    case Assigned = 'Assigned';
    case Pickup = 'Pickup';
    case OnTheWay = 'OnTheWay';
    case Delivered = 'Delivered';
    case Cancelled = 'Cancelled';
    case DeliveryFailed = 'DeliveryFailed';

    /**
     * Status from an API answer; null when missing or not one Quickshipper documents.
     *
     * @param mixed $value
     * @return self|null
     */
    public static function fromAnswer(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /**
     * What the status means, for the merchant and the customer.
     *
     * @return Phrase
     */
    public function label(): Phrase
    {
        return match ($this) {
            self::Draft => __('Waiting for confirmation'),
            self::ReadyForPickup => __('Sent to the courier company'),
            self::WaitingForCourier => __('Looking for a courier'),
            self::Assigned => __('Courier assigned'),
            self::Pickup => __('Picked up by the courier'),
            self::OnTheWay => __('On the way'),
            self::Delivered => __('Delivered'),
            self::Cancelled => __('Cancelled'),
            self::DeliveryFailed => __('Delivery failed'),
        };
    }

    /**
     * Sent to the courier company, no courier assigned yet: cancelling now
     * returns the delivery price (Quickshipper keeps its service fee).
     *
     * @return bool
     */
    public function isBeforeCourier(): bool
    {
        return match ($this) {
            self::ReadyForPickup, self::WaitingForCourier => true,
            default => false,
        };
    }

    /**
     * Over without a delivery: no courier will act on this order any more.
     *
     * @return bool
     */
    public function isEndedWithoutDelivery(): bool
    {
        return match ($this) {
            self::Cancelled, self::DeliveryFailed => true,
            default => false,
        };
    }
}

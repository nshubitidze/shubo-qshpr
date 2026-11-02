<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Delivery;

use Magento\Sales\Model\Order;
use Shubo\Qshpr\Model\Ui\ConfigProvider;

/**
 * The courier and price a customer picked, read back from the order's
 * shipping method, e.g. "shuboqshpr_2_asap" → courier 2, price "asap".
 */
class ChosenCourier
{
    /**
     * @param int $providerId Quickshipper's courier id.
     * @param string $priceKey Quickshipper price id in method-code form (see Offer::priceKey()).
     */
    public function __construct(
        public readonly int $providerId,
        public readonly string $priceKey,
    ) {
    }

    /**
     * The order's choice; null when it is not shipped with Quickshipper (or not shipped at all).
     *
     * @param Order $order
     * @return self|null
     */
    public static function fromOrder(Order $order): ?self
    {
        // Null for a virtual order.
        return self::fromShippingMethod((string)$order->getShippingMethod());
    }

    /**
     * Parse an order's shipping method; null when it is not a Quickshipper one.
     *
     * @param string $shippingMethod
     * @return self|null
     */
    public static function fromShippingMethod(string $shippingMethod): ?self
    {
        $prefix = ConfigProvider::CODE . '_';

        if (!str_starts_with($shippingMethod, $prefix)) {
            return null;
        }

        $parts = explode('_', substr($shippingMethod, strlen($prefix)), 2);

        if (count($parts) !== 2 || !ctype_digit($parts[0]) || $parts[1] === '') {
            return null;
        }

        return new self((int)$parts[0], $parts[1]);
    }
}

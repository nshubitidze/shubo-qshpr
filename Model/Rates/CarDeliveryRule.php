<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Rates;

use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Sales\Model\Order\Shipment;
use Shubo\Qshpr\Model\Carrier\Config;

/**
 * Decides whether Quickshipper is asked for a courier with a car.
 *
 * A car is asked for when any item has the merchant's "Car Delivery
 * Attribute" (a Yes/No product attribute of their choice) set to Yes, when
 * the parcel reaches the optional "Car Delivery from Weight", or when "Car
 * Delivery by Default" is on. The same rule prices the checkout (all cart
 * items) and books the courier (the shipment's items), so the courier
 * booked is the kind that was quoted.
 *
 * Quickshipper offers only the couriers that have cars when asked
 * (CarDelivery=true): an instant courier on foot or by bike can then not be
 * chosen for goods it cannot carry.
 */
class CarDeliveryRule
{
    /** Magento stores a Yes/No attribute's "Yes" as 1. */
    private const int YES = 1;

    /**
     * @param Config $config
     * @param ProductResource $productResource
     */
    public function __construct(
        private readonly Config $config,
        private readonly ProductResource $productResource,
    ) {
    }

    /**
     * Whether the checkout prices a car delivery for this cart.
     *
     * Child items of configurable and bundle products are in the request
     * too, so either the parent or the chosen child may carry the Yes.
     *
     * @param RateRequest $request
     * @param int|null $storeId
     * @param float $weightKg The cart's weight in kg; 0 when unknown.
     * @return bool
     */
    public function forRateRequest(RateRequest $request, ?int $storeId, float $weightKg): bool
    {
        $attribute = $this->config->getCarDeliveryAttribute();
        $values = [];

        if ($attribute !== '') {
            foreach ((array)$request->getAllItems() as $item) {
                $product = $item instanceof AbstractItem ? $item->getProduct() : null;

                if ($product !== null && !$product->isVirtual()) {
                    $values[] = $product->getData($attribute);
                }
            }
        }

        return $this->decide($values, $storeId, $weightKg);
    }

    /**
     * Whether the courier for this shipment is booked with a car.
     *
     * Configurable and bundle items are read together with their child
     * products: either may carry the Yes.
     *
     * @param Shipment $shipment
     * @param int $storeId
     * @param float $weightKg The shipment's weight in kg; 0 when unknown.
     * @return bool
     */
    public function forShipment(Shipment $shipment, int $storeId, float $weightKg): bool
    {
        $attribute = $this->config->getCarDeliveryAttribute();
        $values = [];

        if ($attribute !== '') {
            $productIds = [];

            foreach ($shipment->getAllItems() as $item) {
                $orderItem = $item->getOrderItem();

                if ($orderItem === null) {
                    continue;
                }

                $productIds[] = (int)$orderItem->getProductId();

                foreach ($orderItem->getChildrenItems() as $child) {
                    $productIds[] = (int)$child->getProductId();
                }
            }

            foreach (array_unique(array_filter($productIds)) as $productId) {
                $value = $this->productResource->getAttributeRawValue($productId, $attribute, $storeId);
                $values[] = is_array($value) || $value === false ? null : $value;
            }
        }

        return $this->decide($values, $storeId, $weightKg);
    }

    /**
     * The rule itself, over the items' raw values of the Car Delivery Attribute.
     *
     * @param array<int,mixed> $values Empty or never-saved values count as No.
     * @param int|null $storeId
     * @param float $weightKg
     * @return bool
     */
    public function decide(array $values, ?int $storeId, float $weightKg): bool
    {
        foreach ($values as $value) {
            if (is_numeric($value) && (int)$value === self::YES) {
                return true;
            }
        }

        $fromWeight = $this->config->getCarDeliveryFromWeightKg($storeId);

        if ($fromWeight !== null && $weightKg >= $fromWeight) {
            return true;
        }

        return $this->config->isCarDeliveryDefault($storeId);
    }
}

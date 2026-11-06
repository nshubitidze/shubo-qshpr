<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Rates;

use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Sales\Model\Order\Shipment;
use Shubo\Qshpr\Model\Carrier\Config;

/**
 * The boxes a delivery consists of, as Quickshipper takes them: one entry per
 * physical box with its length, width and height in cm, never sides summed
 * across boxes. Sent with the price request (`Parcels`) and the booking
 * (`parcels`); Quickshipper says some couriers price by size.
 *
 * The sizes come from the store's own product attributes chosen in the
 * settings (the module adds none). One box per unit of a product that has
 * all three sizes; products without a size (small items, the usual case)
 * are left out. A configurable item takes its chosen simple product's
 * size, any other item (a bundle too) its own product's. The same rule runs
 * for checkout (cart items) and booking (shipped items), so the booking
 * describes what was quoted.
 */
class ParcelSizes
{
    /**
     * Upper bound on the boxes listed, so a large order cannot make an absurd request.
     */
    public const int MAX_PARCELS = 50;

    private const string CONFIGURABLE = 'configurable';
    private const int PRECISION = 1;

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
     * The attribute codes to load with cart items; empty when parcel sizes are not used.
     *
     * @return string[]
     */
    public function attributeCodes(): array
    {
        return array_values(array_unique($this->config->getParcelSizeAttributes() ?? []));
    }

    /**
     * The cart's boxes.
     *
     * @param RateRequest $request
     * @return array<int,array<string,float>>
     */
    public function forRateRequest(RateRequest $request): array
    {
        $codes = $this->config->getParcelSizeAttributes();
        $parcels = [];

        if ($codes === null) {
            return [];
        }

        foreach ((array)$request->getAllItems() as $item) {
            if (!$item instanceof AbstractItem || $item->getParentItem() !== null) {
                continue;
            }

            $sized = $item;

            if ($item->getProductType() === self::CONFIGURABLE) {
                $children = $item->getChildren();
                $sized = $children !== [] ? reset($children) : $item;
            }

            $product = $sized->getProduct();

            if ($product === null || $product->isVirtual()) {
                continue;
            }

            $size = $this->size(
                array_map(static fn (string $code): mixed => $product->getData($code), $codes)
            );
            $this->add($parcels, $size, (float)$item->getQty());
        }

        return $parcels;
    }

    /**
     * The shipment's boxes.
     *
     * @param Shipment $shipment
     * @param int $storeId
     * @return array<int,array<string,float>>
     */
    public function forShipment(Shipment $shipment, int $storeId): array
    {
        $codes = $this->config->getParcelSizeAttributes();
        $parcels = [];

        if ($codes === null) {
            return [];
        }

        foreach ($shipment->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();

            if ($orderItem === null || $orderItem->getParentItem() !== null || $orderItem->getIsVirtual()) {
                continue;
            }

            $productId = (int)$orderItem->getProductId();

            if ($orderItem->getProductType() === self::CONFIGURABLE) {
                $children = $orderItem->getChildrenItems();
                $productId = $children !== [] ? (int)reset($children)->getProductId() : $productId;
            }

            $values = $this->productResource->getAttributeRawValue($productId, array_values($codes), $storeId);
            $values = is_array($values) ? $values : [];
            $size = $this->size(array_map(static fn (string $code): mixed => $values[$code] ?? null, $codes));
            $this->add($parcels, $size, (float)$item->getQty());
        }

        return $parcels;
    }

    /**
     * The boxes as `GET /v1/Order/fees` query fields ("Parcels[0].Length" …).
     *
     * @param array<int,array<string,float>> $parcels
     * @return array<string,float>
     */
    public function toQuery(array $parcels): array
    {
        $query = [];

        foreach (array_values($parcels) as $i => $parcel) {
            $query[sprintf('Parcels[%d].Length', $i)] = $parcel['length'];
            $query[sprintf('Parcels[%d].Width', $i)] = $parcel['width'];
            $query[sprintf('Parcels[%d].Height', $i)] = $parcel['height'];
        }

        return $query;
    }

    /**
     * A product's size in cm; null unless all three sides are positive numbers.
     *
     * @param array<string,mixed> $values In the settings' unit.
     * @return array<string,float>|null
     */
    private function size(array $values): ?array
    {
        $toCm = $this->config->getParcelSizeToCentimetres();
        $size = [];

        foreach (['length', 'width', 'height'] as $side) {
            $value = $values[$side] ?? null;
            // A size typed with a decimal comma ("12,5") is still a size.
            $value = is_string($value) ? str_replace(',', '.', trim($value)) : $value;

            if (!is_numeric($value) || (float)$value <= 0) {
                return null;
            }

            $size[$side] = round((float)$value * $toCm, self::PRECISION);
        }

        return $size;
    }

    /**
     * Add one box per unit, up to MAX_PARCELS in all.
     *
     * @param array<int,array<string,float>> $parcels
     * @param array<string,float>|null $size
     * @param float $qty
     * @return void
     */
    private function add(array &$parcels, ?array $size, float $qty): void
    {
        $units = min((int)ceil($qty), self::MAX_PARCELS - count($parcels));

        if ($size !== null && $units > 0) {
            array_push($parcels, ...array_fill(0, $units, $size));
        }
    }
}

<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Dispatch;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order\Shipment;

/**
 * Tells whether the items of a new shipment were shipped by someone else
 * after its order was loaded.
 *
 * Magento checks what is left to ship against the order it loaded. Two
 * shipments of one order created at the same time (the admin's Ship and
 * automatic booking, or a double submit) both pass that check; the order's
 * lock only makes the second one wait. Reading the shipped quantities from
 * the database after the wait shows whether the first one was saved.
 */
class ShippedQuantities
{
    private const string ITEM_TABLE = 'sales_order_item';

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    /**
     * Whether the shipment's items were shipped since their order was loaded.
     *
     * Compares each order item's shipped quantity in the database with the
     * one it was loaded with.
     *
     * @param Shipment $shipment Registered, not yet saved.
     * @return bool
     */
    public function changedSinceLoad(Shipment $shipment): bool
    {
        $loaded = [];

        foreach ($shipment->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();

            if ($orderItem?->getId()) {
                $loaded[$orderItem->getId()] = (float)$orderItem->getOrigData('qty_shipped');
            }
        }

        if ($loaded === []) {
            return false;
        }

        $connection = $this->resource->getConnection();
        $current = $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName(self::ITEM_TABLE), ['item_id', 'qty_shipped'])
                ->where('item_id IN (?)', array_keys($loaded))
        );

        // Both values come from the same DECIMAL column, so equal quantities
        // cast to equal floats.
        foreach ($current as $itemId => $qtyShipped) {
            if ((float)$qtyShipped !== $loaded[$itemId]) {
                return true;
            }
        }

        return false;
    }
}

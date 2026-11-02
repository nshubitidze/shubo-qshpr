<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Shipment;
use Shubo\Qshpr\Model\Delivery\DeliveryLog;
use Shubo\Qshpr\Model\Dispatch\Dispatcher;

/**
 * Links a booked delivery to its shipment once Magento has saved the shipment.
 *
 * The courier is booked before the shipment has an id. This runs inside the
 * same database transaction as the shipment save, so if that save fails the
 * link is rolled back with it and the delivery stays without a shipment.
 */
class LinkShipment implements ObserverInterface
{
    /**
     * @param DeliveryLog $deliveryLog
     */
    public function __construct(
        private readonly DeliveryLog $deliveryLog,
    ) {
    }

    /**
     * Handle sales_order_shipment_save_after.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $shipment = $observer->getEvent()->getData('shipment');

        if (!$shipment instanceof Shipment || !$shipment->getId()) {
            return;
        }

        $deliveryId = (int)$shipment->getData(Dispatcher::SHIPMENT_DELIVERY_ID);

        if ($deliveryId > 0) {
            $this->deliveryLog->linkShipment($deliveryId, (int)$shipment->getId());
        }
    }
}

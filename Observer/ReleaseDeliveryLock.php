<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Shipment;
use Shubo\Qshpr\Model\Dispatch\DeliveryLock;
use Shubo\Qshpr\Model\Dispatch\Dispatcher;

/**
 * Releases the order's DeliveryLock once the shipment that booked its courier
 * is committed, and its delivery row linked, so another shipment of the order
 * can book.
 *
 * If the save fails there is no commit and the lock lasts until the request
 * ends; the next request then finds the booking without a shipment and
 * reuses it.
 */
class ReleaseDeliveryLock implements ObserverInterface
{
    /**
     * @param DeliveryLock $lock
     */
    public function __construct(
        private readonly DeliveryLock $lock,
    ) {
    }

    /**
     * Handle sales_order_shipment_save_commit_after.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $shipment = $observer->getEvent()->getData('shipment');

        if (!$shipment instanceof Shipment) {
            return;
        }

        $orderId = (int)$shipment->getData(Dispatcher::SHIPMENT_LOCKED_ORDER);

        if ($orderId > 0) {
            $shipment->unsetData(Dispatcher::SHIPMENT_LOCKED_ORDER);
            $this->lock->release($orderId);
        }
    }
}

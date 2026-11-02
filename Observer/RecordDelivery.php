<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Shubo\Qshpr\Model\Delivery\ChosenCourier;
use Shubo\Qshpr\Model\Delivery\DeliveryLog;
use Shubo\Qshpr\Model\Dispatch\AutoDispatchTrigger;
use Shubo\Qshpr\Model\Geocoding\Address;
use Shubo\Qshpr\Model\Geocoding\Geocoder;
use Shubo\Qshpr\Model\Money;

/**
 * Records what the customer chose when an order is placed with a Quickshipper
 * courier: courier, price, the drop-off point the price was quoted for, and
 * what the customer paid. The courier is booked later, from the shipment —
 * or automatically: a card captured at checkout makes the order paid before
 * this runs, so the row is marked due for automatic booking here.
 *
 * The drop-off point comes from the geocoder, which checkout has just asked
 * for the same address, so this is a cache hit. Placing the order must never
 * fail because of this record: errors are logged, and the courier booking
 * geocodes the address itself when no point was recorded.
 */
class RecordDelivery implements ObserverInterface
{
    /**
     * @param DeliveryLog $deliveryLog
     * @param Geocoder $geocoder
     * @param AutoDispatchTrigger $autoDispatchTrigger
     * @param LoggerInterface $logger Bound to ShuboQshprLogger in di.xml.
     */
    public function __construct(
        private readonly DeliveryLog $deliveryLog,
        private readonly Geocoder $geocoder,
        private readonly AutoDispatchTrigger $autoDispatchTrigger,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handle sales_model_service_quote_submit_success.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');

        if ($order instanceof Order) {
            $this->record($order);
        }
    }

    /**
     * Record one placed order's choice, once.
     *
     * Checkout with Multiple Addresses places its orders without the event
     * above, so RecordMultishippingDeliveries calls this too.
     *
     * @param Order $order
     * @return void
     */
    public function record(Order $order): void
    {
        $chosen = ChosenCourier::fromOrder($order);

        if ($chosen === null || (int)$order->getId() === 0) {
            return;
        }

        try {
            if ($this->deliveryLog->findLatest((int)$order->getId()) !== null) {
                return;
            }

            $storeId = (int)$order->getStoreId();
            $address = $order->getShippingAddress();
            $location = $address === null ? null : $this->geocoder->locate(
                new Address(
                    $address->getStreetLine(1),
                    (string)$address->getCity(),
                    (string)$address->getCountryId()
                ),
                $storeId
            );

            $this->deliveryLog->recordQuote(
                (int)$order->getId(),
                (string)$order->getIncrementId(),
                $storeId,
                $chosen->providerId,
                $chosen->priceKey,
                $location?->latitude,
                $location?->longitude,
                Money::toTetri((float)$order->getBaseShippingAmount()),
            );
            $this->autoDispatchTrigger->markIfPaid($order);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'delivery record for order %s failed: %s',
                $order->getIncrementId(),
                $e->getMessage()
            ), ['exception' => $e]);
        }
    }
}

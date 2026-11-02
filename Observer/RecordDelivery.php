<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Shubo\Qshpr\Model\Delivery\ChosenCourier;
use Shubo\Qshpr\Model\Delivery\DeliveryLog;
use Shubo\Qshpr\Model\Delivery\OrderNotes;
use Shubo\Qshpr\Model\Dispatch\AutoDispatchTrigger;
use Shubo\Qshpr\Model\Geocoding\Address;
use Shubo\Qshpr\Model\Geocoding\Geocoder;
use Shubo\Qshpr\Model\Money;
use Shubo\Qshpr\Model\Schedule\DeliverySlots;
use Shubo\Qshpr\Plugin\SaveDeliveryTime;

/**
 * Records what the customer chose when an order is placed with a Quickshipper
 * courier: courier, price, the drop-off point the price was quoted for, and
 * what the customer paid. The courier is booked later, from the shipment —
 * or automatically: a card captured at checkout makes the order paid before
 * this runs, so the row is marked due for automatic booking here.
 *
 * A delivery time the customer chose travels from the quote to the row, and
 * an order note tells the merchant.
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
     * @param DeliverySlots $deliverySlots
     * @param OrderNotes $orderNotes
     * @param LoggerInterface $logger Bound to ShuboQshprLogger in di.xml.
     */
    public function __construct(
        private readonly DeliveryLog $deliveryLog,
        private readonly Geocoder $geocoder,
        private readonly AutoDispatchTrigger $autoDispatchTrigger,
        private readonly DeliverySlots $deliverySlots,
        private readonly OrderNotes $orderNotes,
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
            $quote = $observer->getEvent()->getData('quote');
            $this->record($order, $quote instanceof Quote ? $quote : null);
        }
    }

    /**
     * Record one placed order's choice, once.
     *
     * Checkout with Multiple Addresses places its orders without the event
     * above, so RecordMultishippingDeliveries calls this too (without a
     * quote: that checkout offers no delivery time).
     *
     * @param Order $order
     * @param Quote|null $quote The placed quote, which carries the delivery time chosen at checkout.
     * @return void
     */
    public function record(Order $order, ?Quote $quote = null): void
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
            $scheduledTime = $quote !== null
                ? trim((string)$quote->getShippingAddress()->getData(SaveDeliveryTime::ADDRESS_FIELD))
                : '';
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
                $scheduledTime !== '' ? $scheduledTime : null,
            );

            if ($scheduledTime !== '') {
                $this->orderNotes->add((int)$order->getId(), __(
                    'The customer asked for delivery at %1 (store time). The courier is booked for that time '
                    . 'when the shipment is submitted.',
                    $this->deliverySlots->describe($scheduledTime, $storeId)
                ));
            }

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

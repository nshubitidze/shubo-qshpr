<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Shubo\Qshpr\Model\Delivery\ChosenCourier;
use Shubo\Qshpr\Model\Schedule\DeliverySlots;
use Shubo\Qshpr\Plugin\SaveDeliveryTime;

/**
 * Adds the delivery time the customer chose to the order's shipping
 * description ("Quickshipper - Wolt (delivery Sat, 7 Nov, 14:00–16:00)").
 *
 * Magento shows that description wherever it shows the shipping method: the
 * order and shipment e-mails, the admin's order, invoice and shipment pages,
 * the customer's account and the PDFs. Runs before the order is saved, so
 * the order e-mail already carries it.
 */
class ShowDeliveryTime implements ObserverInterface
{
    /**
     * @param DeliverySlots $deliverySlots
     */
    public function __construct(
        private readonly DeliverySlots $deliverySlots,
    ) {
    }

    /**
     * Handle sales_model_service_quote_submit_before.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        $quote = $observer->getEvent()->getData('quote');

        if (!$order instanceof Order || !$quote instanceof Quote || $quote->isVirtual()
            || ChosenCourier::fromShippingMethod((string)$order->getShippingMethod()) === null
        ) {
            return;
        }

        $time = trim((string)$quote->getShippingAddress()->getData(SaveDeliveryTime::ADDRESS_FIELD));

        if ($time === '') {
            return;
        }

        $order->setShippingDescription((string)__(
            '%1 (delivery %2)',
            (string)$order->getShippingDescription(),
            $this->deliverySlots->describe($time, (int)$order->getStoreId())
        ));
    }
}

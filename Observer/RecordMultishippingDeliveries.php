<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

/**
 * Records the courier choice of orders placed through "Checkout with
 * Multiple Addresses", which places them without
 * sales_model_service_quote_submit_success and reports them together in
 * checkout_submit_all_after ("orders"). Without a record such an order is
 * never booked automatically.
 *
 * One-page and admin checkout send a single "order" in the same event;
 * RecordDelivery has recorded it already, so it is left alone here.
 */
class RecordMultishippingDeliveries implements ObserverInterface
{
    /**
     * @param RecordDelivery $recordDelivery
     */
    public function __construct(
        private readonly RecordDelivery $recordDelivery,
    ) {
    }

    /**
     * Handle checkout_submit_all_after.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $orders = $observer->getEvent()->getData('orders');

        if (!is_array($orders)) {
            return;
        }

        foreach ($orders as $order) {
            if ($order instanceof Order) {
                $this->recordDelivery->record($order);
            }
        }
    }
}

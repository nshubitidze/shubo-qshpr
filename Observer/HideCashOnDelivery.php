<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Model\Quote;
use Shubo\Qshpr\Model\CashOnDelivery\CashOnDelivery;
use Shubo\Qshpr\Model\Delivery\ChosenCourier;

/**
 * Hides cash-on-delivery payment methods at checkout when the customer chose
 * a Quickshipper courier: Quickshipper has no cash-on-delivery service, so
 * nobody would collect the money (see CashOnDelivery).
 *
 * Runs on payment_method_is_active, which every payment method fires when
 * checkout lists the methods; the list is asked again after the customer
 * picks a delivery option.
 */
class HideCashOnDelivery implements ObserverInterface
{
    /**
     * @param CashOnDelivery $cashOnDelivery
     */
    public function __construct(
        private readonly CashOnDelivery $cashOnDelivery,
    ) {
    }

    /**
     * Mark the method unavailable when it is cash and the delivery is a Quickshipper courier.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $method = $observer->getEvent()->getData('method_instance');
        $result = $observer->getEvent()->getData('result');
        $quote = $observer->getEvent()->getData('quote');

        if (!$method instanceof MethodInterface
            || !$result instanceof DataObject
            || !$quote instanceof Quote
            || !$result->getData('is_available')
            || !$this->cashOnDelivery->isCashPayment((string)$method->getCode())
        ) {
            return;
        }

        if (ChosenCourier::fromShippingMethod((string)$quote->getShippingAddress()->getShippingMethod()) !== null) {
            $result->setData('is_available', false);
        }
    }
}

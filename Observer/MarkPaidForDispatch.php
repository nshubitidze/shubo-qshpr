<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Invoice;
use Shubo\Qshpr\Model\Dispatch\AutoDispatchTrigger;

/**
 * An invoice was paid: the order may now be due for automatic booking.
 *
 * Invoice::pay() has already added the invoice to the order's paid total
 * when this event fires.
 */
class MarkPaidForDispatch implements ObserverInterface
{
    /**
     * @param AutoDispatchTrigger $trigger
     */
    public function __construct(
        private readonly AutoDispatchTrigger $trigger,
    ) {
    }

    /**
     * Handle sales_order_invoice_pay.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $invoice = $observer->getEvent()->getData('invoice');

        if ($invoice instanceof Invoice) {
            $this->trigger->markIfPaid($invoice->getOrder());
        }
    }
}

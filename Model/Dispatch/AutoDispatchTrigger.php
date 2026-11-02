<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Dispatch;

use Magento\Sales\Model\Order;
use Shubo\Qshpr\Model\Carrier\Config;
use Shubo\Qshpr\Model\Delivery\ChosenCourier;
use Shubo\Qshpr\Model\Delivery\DeliveryLog;

/**
 * Marks a fully paid Quickshipper order as due for automatic booking, when
 * the store has "Book Courier Automatically" on. Only marks: the booking
 * itself runs later in the AutoDispatch cron, so a payment is never held up
 * by Quickshipper.
 *
 * Called when an invoice is paid and again once the order's delivery row
 * exists, because a card captured at checkout is paid before that row is
 * written.
 */
class AutoDispatchTrigger
{
    /**
     * @param Config $config
     * @param DeliveryLog $deliveryLog
     */
    public function __construct(
        private readonly Config $config,
        private readonly DeliveryLog $deliveryLog,
    ) {
    }

    /**
     * Mark the order due if it qualifies.
     *
     * @param Order $order
     * @return bool Whether it was marked.
     */
    public function markIfPaid(Order $order): bool
    {
        $orderId = (int)$order->getId();

        if ($orderId === 0
            || ChosenCourier::fromOrder($order) === null
            || !$this->config->isAutoDispatchEnabled((int)$order->getStoreId())
            || !$this->isFullyPaid($order)
        ) {
            return false;
        }

        return $this->deliveryLog->markDispatchDue($orderId);
    }

    /**
     * Paid in full: something was paid and nothing is left to pay.
     *
     * Also checked again just before booking, on the order as it is then.
     *
     * @param Order $order
     * @return bool
     */
    public function isFullyPaid(Order $order): bool
    {
        return (float)$order->getBaseTotalPaid() > 0 && $order->getBaseTotalDue() <= 0;
    }
}

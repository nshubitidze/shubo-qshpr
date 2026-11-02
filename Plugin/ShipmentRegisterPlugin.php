<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Plugin;

use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order\Shipment;
use Shubo\Qshpr\Exception\DispatchException;
use Shubo\Qshpr\Model\Dispatch\Dispatcher;
use Shubo\Qshpr\Model\Money;

/**
 * Books the Quickshipper courier when the merchant submits a shipment in the admin.
 *
 * Magento's admin shipment and invoice ("Create Shipment") controllers
 * register a new shipment after validating it and before the database
 * transaction that saves it — the point where the booking can still stop
 * the save. A DispatchException propagates: the controller shows its
 * message and saves nothing.
 */
class ShipmentRegisterPlugin
{
    /**
     * @param Dispatcher $dispatcher
     * @param ManagerInterface $messageManager
     */
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly ManagerInterface $messageManager,
    ) {
    }

    /**
     * Book the courier once the shipment is registered.
     *
     * @param Shipment $subject
     * @param Shipment $result
     * @return Shipment
     * @throws DispatchException
     */
    public function afterRegister(Shipment $subject, $result)
    {
        if ($subject->getData(Dispatcher::SHIPMENT_BOOKED_BY_CALLER)) {
            return $result;
        }

        $booking = $this->dispatcher->dispatch($subject);

        if ($booking === null) {
            return $result;
        }

        $this->messageManager->addSuccessMessage($booking->isReused
            ? __(
                'This shipment uses the Quickshipper booking of the earlier attempt that was not saved: %1, '
                . 'order %2. No new courier was booked.',
                $booking->providerName,
                $booking->qshprOrderNo
            )
            : __(
                'Quickshipper booked %1 for this shipment: order %2.',
                $booking->providerName,
                $booking->qshprOrderNo
            ));

        if ($booking->isExtraDelivery) {
            $this->messageManager->addWarningMessage(__(
                'This is an extra delivery for the order: Quickshipper charged %1 GEL, '
                . 'and the customer paid for one delivery only.',
                Money::toGel($booking->chargedAmount)
            ));
        } elseif ($booking->isChargedAbovePaid()) {
            $this->messageManager->addWarningMessage(__(
                'Quickshipper charged %1 GEL for this delivery; the customer paid %2 GEL.',
                Money::toGel($booking->chargedAmount),
                Money::toGel($booking->paidAmount)
            ));
        }

        return $result;
    }
}

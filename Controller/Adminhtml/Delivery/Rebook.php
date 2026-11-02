<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Controller\Adminhtml\Delivery;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order\Shipment;
use Shubo\Qshpr\Model\Dispatch\Rebooker;
use Shubo\Qshpr\Model\Money;

/**
 * "Book Courier Again" on the shipment page.
 */
class Rebook extends Action implements HttpPostActionInterface
{
    /**
     * The order "Ship" permission, as core's Submit Shipment: this books and
     * pays for a courier. "Shipments" (Magento_Sales::shipment) covers the
     * shipment pages, not shipping.
     */
    public const ADMIN_RESOURCE = 'Magento_Sales::ship';

    /**
     * @param Context $context
     * @param ShipmentRepositoryInterface $shipmentRepository
     * @param Rebooker $rebooker
     */
    public function __construct(
        Context $context,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly Rebooker $rebooker,
    ) {
        parent::__construct($context);
    }

    /**
     * Book, say what happened, return to the shipment.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $shipmentId = (int)$this->getRequest()->getParam('shipment_id');

        try {
            $shipment = $this->shipmentRepository->get($shipmentId);

            if (!$shipment instanceof Shipment) {
                throw new LocalizedException(__('This shipment could not be loaded.'));
            }

            $booking = $this->rebooker->rebook($shipment);
            $this->messageManager->addSuccessMessage(__(
                'Quickshipper booked %1 for this shipment again: order %2, %3 GEL.',
                $booking->providerName,
                $booking->qshprOrderNo,
                Money::toGel($booking->chargedAmount)
            ));

            if ($booking->isExtraDelivery || $booking->isChargedAbovePaid()) {
                $this->messageManager->addWarningMessage(__(
                    'Quickshipper charged %1 GEL for this delivery; the customer paid %2 GEL for it.',
                    Money::toGel($booking->chargedAmount),
                    Money::toGel($booking->paidAmount)
                ));
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->resultRedirectFactory->create()
            ->setPath('adminhtml/order_shipment/view', ['shipment_id' => $shipmentId]);
    }
}

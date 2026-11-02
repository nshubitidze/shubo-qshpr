<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Controller\Adminhtml\Delivery;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Shubo\Qshpr\Model\Dispatch\Canceller;
use Shubo\Qshpr\Model\Dispatch\CancelOutcome;
use Shubo\Qshpr\Model\OrderStatus;

/**
 * "Cancel Quickshipper Delivery" on the shipment page.
 */
class Cancel extends Action implements HttpPostActionInterface
{
    /** Same permission as booking: the order "Ship" permission. */
    public const ADMIN_RESOURCE = Rebook::ADMIN_RESOURCE;

    /** Reason shown on the order in the merchant's Quickshipper dashboard. */
    private const string REASON = 'Cancelled by the merchant in Magento';

    /**
     * @param Context $context
     * @param Canceller $canceller
     */
    public function __construct(
        Context $context,
        private readonly Canceller $canceller,
    ) {
        parent::__construct($context);
    }

    /**
     * Cancel, say what happened, return to the shipment.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $shipmentId = (int)$this->getRequest()->getParam('shipment_id');

        try {
            [$outcome, $row, $status] = $this->canceller->cancelForShipment($shipmentId, self::REASON);
            $orderNo = (string)($row['qshpr_order_no'] ?? '');
            $label = OrderStatus::fromAnswer($status)?->label() ?? $status;

            match ($outcome) {
                CancelOutcome::Cancelled => $this->messageManager->addSuccessMessage(__(
                    'Quickshipper delivery %1 is cancelled and its tracking number removed. Quickshipper returns '
                    . 'the delivery price and may keep its service fee.',
                    $orderNo
                )),
                CancelOutcome::WasCancelled => $this->messageManager->addSuccessMessage(__(
                    'Quickshipper delivery %1 had already been cancelled at Quickshipper; Magento now shows it '
                    . 'and its tracking number was removed.',
                    $orderNo
                )),
                CancelOutcome::Ended => $this->messageManager->addErrorMessage(__(
                    'Quickshipper delivery %1 is already over (%2); there is nothing to cancel.',
                    $orderNo,
                    $label
                )),
                CancelOutcome::CourierHasIt => $this->messageManager->addErrorMessage(__(
                    'Quickshipper delivery %1 cannot be cancelled from Magento: %2. Cancel it in the '
                    . 'Quickshipper dashboard or with the courier company.',
                    $orderNo,
                    $label
                )),
                CancelOutcome::UnknownStatus => $this->messageManager->addErrorMessage(__(
                    'Quickshipper reports delivery %1 with a status Magento does not know (%2), so it was not '
                    . 'cancelled. Check it in the Quickshipper dashboard.',
                    $orderNo,
                    $status !== '' ? $status : '-'
                )),
                CancelOutcome::NoAnswer => $this->messageManager->addErrorMessage(__(
                    'Quickshipper did not answer the cancel of delivery %1, so it may or may not be cancelled. '
                    . 'Press Cancel Quickshipper Delivery again in a moment to find out.',
                    $orderNo
                )),
                CancelOutcome::NothingToCancel => $this->messageManager->addErrorMessage(__(
                    'This shipment has no Quickshipper delivery under way.'
                )),
                CancelOutcome::Busy => $this->messageManager->addErrorMessage(__(
                    'The order is being updated right now. Try again in a moment.'
                )),
            };
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Quickshipper did not cancel the delivery: %1', $e->getMessage()));
        }

        return $this->resultRedirectFactory->create()
            ->setPath('adminhtml/order_shipment/view', ['shipment_id' => $shipmentId]);
    }
}

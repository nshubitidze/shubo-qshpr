<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Plugin;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Shipping\Block\Adminhtml\View;
use Shubo\Qshpr\Controller\Adminhtml\Delivery\Rebook;
use Shubo\Qshpr\Model\Delivery\DeliveryLog;

/**
 * Adds the Quickshipper buttons to the admin shipment page: "Cancel
 * Quickshipper Delivery" while its delivery is under way, "Book Courier
 * Again" once it was cancelled or failed.
 *
 * Buttons must be in the page's button list before the block's layout is
 * prepared, when the list is moved to the toolbar.
 */
class ShipmentViewButtons
{
    /**
     * @param DeliveryLog $deliveryLog
     * @param AuthorizationInterface $authorization
     */
    public function __construct(
        private readonly DeliveryLog $deliveryLog,
        private readonly AuthorizationInterface $authorization,
    ) {
    }

    /**
     * Add the button that fits the shipment's latest Quickshipper delivery.
     *
     * @param View $subject
     * @param LayoutInterface $layout
     * @return null
     */
    public function beforeSetLayout(View $subject, LayoutInterface $layout)
    {
        $shipment = $subject->getShipment();
        $shipmentId = $shipment ? (int)$shipment->getId() : 0;

        if ($shipmentId === 0 || !$this->authorization->isAllowed(Rebook::ADMIN_RESOURCE)) {
            return null;
        }

        $delivery = $this->deliveryLog->findLatestForShipment($shipmentId);
        $state = $delivery['state'] ?? null;

        if ($state === DeliveryLog::STATE_DISPATCHED) {
            $subject->addButton('shubo_qshpr_cancel', [
                'label' => __('Cancel Quickshipper Delivery'),
                'class' => 'cancel',
                'onclick' => $this->postWithConfirm(
                    $subject,
                    (string)__(
                        'Cancel Quickshipper delivery %1? Possible only until a courier takes it. Quickshipper '
                        . 'returns the delivery price and may keep its service fee.',
                        (string)$delivery['qshpr_order_no']
                    ),
                    $subject->getUrl('shuboqshpr/delivery/cancel', ['shipment_id' => $shipmentId])
                ),
            ]);
        } elseif ($state === DeliveryLog::STATE_CANCELLED || $state === DeliveryLog::STATE_FAILED) {
            $subject->addButton('shubo_qshpr_rebook', [
                'label' => __('Book Courier Again'),
                'onclick' => $this->postWithConfirm(
                    $subject,
                    (string)__('Book a new Quickshipper courier for this shipment at the current price?'),
                    $subject->getUrl('shuboqshpr/delivery/rebook', ['shipment_id' => $shipmentId])
                ),
            ]);
        }

        return null;
    }

    /**
     * Onclick that asks first, then POSTs with the form key (admin's deleteConfirm with post data).
     *
     * @param View $subject
     * @param string $question
     * @param string $url
     * @return string
     */
    private function postWithConfirm(View $subject, string $question, string $url): string
    {
        return sprintf(
            "deleteConfirm('%s', '%s', {data: {}})",
            $subject->escapeJs($subject->escapeHtml($question)),
            $subject->escapeJs($url)
        );
    }
}

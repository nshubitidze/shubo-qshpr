<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Plugin;

use Magento\Quote\Api\Data\ShippingMethodExtensionFactory;
use Magento\Quote\Api\Data\ShippingMethodInterface;
use Magento\Quote\Model\Cart\ShippingMethodConverter;
use Magento\Quote\Model\Quote\Address\Rate;
use Shubo\Qshpr\Model\Schedule\DeliverySlots;
use Shubo\Qshpr\Model\Ui\ConfigProvider;

/**
 * Tells the checkout which Quickshipper shipping methods take a delivery
 * time (extension attribute shubo_qshpr_takes_time), so the "Delivery time"
 * list is shown only for them. The server checks it again when the
 * shipping step is saved.
 */
class ShippingMethodDeliveryTime
{
    /**
     * @param DeliverySlots $deliverySlots
     * @param ShippingMethodExtensionFactory $extensionFactory
     */
    public function __construct(
        private readonly DeliverySlots $deliverySlots,
        private readonly ShippingMethodExtensionFactory $extensionFactory,
    ) {
    }

    /**
     * Mark a Quickshipper method whose courier takes a delivery time.
     *
     * @param ShippingMethodConverter $subject
     * @param ShippingMethodInterface $result
     * @param Rate $rateModel
     * @return ShippingMethodInterface
     */
    public function afterModelToDataObject(
        ShippingMethodConverter $subject,
        ShippingMethodInterface $result,
        $rateModel,
    ): ShippingMethodInterface {
        if ($result->getCarrierCode() !== ConfigProvider::CODE
            || !$this->deliverySlots->acceptsTime($result->getCarrierCode() . '_' . $result->getMethodCode())
        ) {
            return $result;
        }

        $extension = $result->getExtensionAttributes() ?? $this->extensionFactory->create();
        $extension->setShuboQshprTakesTime(true);
        $result->setExtensionAttributes($extension);

        return $result;
    }
}

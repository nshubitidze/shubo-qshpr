<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Plugin;

use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Model\ShippingInformationManagement;
use Magento\Framework\Exception\InputException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote\Address;
use Shubo\Qshpr\Model\Schedule\DeliverySlots;
use Shubo\Qshpr\Model\Schedule\OpeningHours;

/**
 * Keeps the delivery time the customer chose with the quote's shipping
 * address when checkout saves the shipping step.
 *
 * The browser only proposes a time: it is kept only when it is one of the
 * slots the store offers right now and the chosen Quickshipper courier
 * takes a delivery time. Any other delivery clears it. While the shop is
 * closed and keeps opening hours, such a courier needs a time: "as soon as
 * possible" would promise a courier the shop cannot hand a parcel to.
 */
class SaveDeliveryTime
{
    public const string ADDRESS_FIELD = 'shubo_qshpr_delivery_time';

    /**
     * @param DeliverySlots $deliverySlots
     * @param CartRepositoryInterface $cartRepository
     * @param OpeningHours $openingHours
     */
    public function __construct(
        private readonly DeliverySlots $deliverySlots,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly OpeningHours $openingHours,
    ) {
    }

    /**
     * Check the chosen time and put it on the shipping address that is about to be saved.
     *
     * @param ShippingInformationManagement $subject
     * @param int $cartId
     * @param ShippingInformationInterface $addressInformation
     * @return null
     * @throws InputException When the time is not one the store offers now, or none is chosen while closed.
     */
    public function beforeSaveAddressInformation(
        ShippingInformationManagement $subject,
        $cartId,
        ShippingInformationInterface $addressInformation,
    ) {
        $address = $addressInformation->getShippingAddress();
        $chosen = trim((string)$addressInformation->getExtensionAttributes()?->getShuboQshprDeliveryTime());
        $method = $addressInformation->getShippingCarrierCode() . '_' . $addressInformation->getShippingMethodCode();

        if (!$address instanceof Address) {
            return null;
        }

        if (!$this->deliverySlots->acceptsTime($method)) {
            $address->setData(self::ADDRESS_FIELD, null);

            return null;
        }

        $storeId = (int)$this->cartRepository->getActive((int)$cartId)->getStoreId();

        if ($chosen === '') {
            $closed = $this->openingHours->nextOpening($storeId) !== null;

            if ($closed && $this->deliverySlots->getSlots($storeId) !== []) {
                throw new InputException(
                    __('We are closed now, so "As soon as possible" is not available. Please choose a delivery time.')
                );
            }

            $address->setData(self::ADDRESS_FIELD, null);

            return null;
        }

        if ($this->deliverySlots->getLabel($chosen, $storeId) === null) {
            throw new InputException(__('The delivery time you chose is no longer available. Please choose another.'));
        }

        $address->setData(self::ADDRESS_FIELD, $chosen);

        return null;
    }
}

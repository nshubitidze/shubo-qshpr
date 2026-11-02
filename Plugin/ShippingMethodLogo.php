<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Plugin;

use Magento\Quote\Api\Data\ShippingMethodExtensionFactory;
use Magento\Quote\Api\Data\ShippingMethodInterface;
use Magento\Quote\Model\Cart\ShippingMethodConverter;
use Magento\Quote\Model\Quote\Address\Rate;
use Shubo\Qshpr\Model\Carrier\Config;
use Shubo\Qshpr\Model\Rates\CourierLogos;
use Shubo\Qshpr\Model\Ui\ConfigProvider;

/**
 * Adds the courier's logo URL to Quickshipper shipping methods sent to the
 * checkout (extension attribute shubo_qshpr_logo_url).
 *
 * Quickshipper method codes start with the courier id ("2_asap" = Wolt), so
 * the logo is looked up by that id.
 */
class ShippingMethodLogo
{
    private const string CODE_SEPARATOR = '_';

    /**
     * @param Config $config
     * @param CourierLogos $courierLogos
     * @param ShippingMethodExtensionFactory $extensionFactory
     */
    public function __construct(
        private readonly Config $config,
        private readonly CourierLogos $courierLogos,
        private readonly ShippingMethodExtensionFactory $extensionFactory,
    ) {
    }

    /**
     * Put the logo on a Quickshipper method, when logos are shown and one is known.
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
        if ($result->getCarrierCode() !== ConfigProvider::CODE) {
            return $result;
        }

        $quote = $rateModel->getAddress()?->getQuote();
        $storeId = $quote !== null ? (int)$quote->getStoreId() : null;

        if (!$this->config->areCourierLogosShown($storeId)) {
            return $result;
        }

        $providerId = strtok((string)$result->getMethodCode(), self::CODE_SEPARATOR);
        $url = is_numeric($providerId) ? $this->courierLogos->get((int)$providerId) : '';

        if ($url !== '') {
            $extension = $result->getExtensionAttributes() ?? $this->extensionFactory->create();
            $extension->setShuboQshprLogoUrl($url);
            $result->setExtensionAttributes($extension);
        }

        return $result;
    }
}

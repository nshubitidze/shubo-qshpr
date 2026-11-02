<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Plugin;

use Magento\Quote\Model\Quote\Config as QuoteConfig;
use Shubo\Qshpr\Model\Carrier\Config;

/**
 * Loads the product attribute chosen as "Car Delivery Attribute" with cart
 * items, so checkout rates can read it without a query per product.
 *
 * Magento loads only the attributes listed for quote items; the list is
 * fixed in catalog_attributes.xml, but this one is the merchant's choice.
 */
class QuoteItemCarDeliveryAttribute
{
    /**
     * @param Config $config
     */
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * Add the chosen attribute to the attributes loaded with cart items.
     *
     * @param QuoteConfig $subject
     * @param string[] $result
     * @return string[]
     */
    public function afterGetProductAttributes(QuoteConfig $subject, array $result): array
    {
        $code = $this->config->getCarDeliveryAttribute();

        if ($code !== '' && !in_array($code, $result, true)) {
            $result[] = $code;
        }

        return $result;
    }
}

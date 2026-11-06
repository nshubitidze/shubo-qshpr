<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Plugin;

use Magento\Quote\Model\Quote\Config as QuoteConfig;
use Shubo\Qshpr\Model\Rates\ParcelSizes;

/**
 * Loads the product attributes chosen for parcel length, width and height
 * with cart items, so checkout rates can read them without a query per
 * product (Magento loads only the attributes listed for quote items).
 */
class QuoteItemParcelSizeAttributes
{
    /**
     * @param ParcelSizes $parcelSizes
     */
    public function __construct(
        private readonly ParcelSizes $parcelSizes,
    ) {
    }

    /**
     * Add the chosen attributes to the attributes loaded with cart items.
     *
     * @param QuoteConfig $subject
     * @param string[] $result
     * @return string[]
     */
    public function afterGetProductAttributes(QuoteConfig $subject, array $result): array
    {
        return array_values(array_unique(array_merge($result, $this->parcelSizes->attributeCodes())));
    }
}

<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;

/**
 * The merchant's own Yes/No product attributes, for settings that let them
 * reuse an attribute they already keep (e.g. "Bulky item") instead of the
 * module adding its own.
 *
 * Only attributes created in the store are listed: Magento's own Yes/No
 * attributes are internal switches (e.g. a bundle's "Dynamic Price").
 */
class YesNoProductAttribute implements OptionSourceInterface
{
    /** Magento's "Yes/No" catalog input type. */
    private const string INPUT_YES_NO = 'boolean';

    /**
     * @param CollectionFactory $attributeCollectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $attributeCollectionFactory,
    ) {
    }

    /**
     * @inheritDoc
     *
     * @return array<int, array{value: string, label: Phrase|string}>
     */
    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => __('-- Not used --')]];
        $attributes = $this->attributeCollectionFactory->create()
            ->addFieldToFilter('frontend_input', self::INPUT_YES_NO)
            ->addFieldToFilter('is_user_defined', 1)
            ->setOrder('frontend_label', 'ASC');

        foreach ($attributes as $attribute) {
            $options[] = [
                'value' => (string)$attribute->getAttributeCode(),
                'label' => sprintf('%s (%s)', $attribute->getDefaultFrontendLabel(), $attribute->getAttributeCode()),
            ];
        }

        return $options;
    }
}

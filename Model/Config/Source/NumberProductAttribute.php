<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;

/**
 * The merchant's own number-like product attributes (text field, price or
 * weight input), for settings that reuse sizes the store already keeps
 * (e.g. "Length") instead of the module adding its own attributes.
 */
class NumberProductAttribute implements OptionSourceInterface
{
    /** Catalog input types that can hold a number. */
    private const array NUMBER_INPUTS = ['text', 'price', 'weight'];

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
            ->addFieldToFilter('frontend_input', ['in' => self::NUMBER_INPUTS])
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

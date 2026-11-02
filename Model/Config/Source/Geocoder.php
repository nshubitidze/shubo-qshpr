<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;
use Shubo\Qshpr\Model\Geocoding\Geocoder as GeocodingService;

/**
 * The admin's geocoder list: every provider registered in di.xml.
 */
class Geocoder implements OptionSourceInterface
{
    /**
     * @param GeocodingService $geocoder
     */
    public function __construct(
        private readonly GeocodingService $geocoder,
    ) {
    }

    /**
     * @inheritDoc
     *
     * @return array<int, array{value: string, label: Phrase}>
     */
    public function toOptionArray(): array
    {
        $options = [];

        foreach ($this->geocoder->getProviderLabels() as $code => $label) {
            $options[] = ['value' => (string)$code, 'label' => $label];
        }

        return $options;
    }
}

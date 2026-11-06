<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;

/**
 * Units the store's product size attributes can be kept in; Quickshipper takes centimetres.
 */
class SizeUnit implements OptionSourceInterface
{
    public const string CENTIMETRE = 'cm';
    public const string MILLIMETRE = 'mm';
    public const string METRE = 'm';
    public const string INCH = 'in';

    /** Centimetres per unit. */
    public const array TO_CENTIMETRES = [
        self::CENTIMETRE => 1.0,
        self::MILLIMETRE => 0.1,
        self::METRE => 100.0,
        self::INCH => 2.54,
    ];

    /**
     * @inheritDoc
     *
     * @return array<int, array{value: string, label: Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::CENTIMETRE, 'label' => __('Centimetres (cm)')],
            ['value' => self::MILLIMETRE, 'label' => __('Millimetres (mm)')],
            ['value' => self::METRE, 'label' => __('Metres (m)')],
            ['value' => self::INCH, 'label' => __('Inches (in)')],
        ];
    }
}

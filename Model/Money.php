<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model;

/**
 * Quickshipper quotes prices in lari with decimals; the module keeps amounts
 * as integer tetri (1/100 lari). The one place that converts between them.
 */
class Money
{
    /** Quickshipper's name for the lari, and the only currency the module offers. */
    public const string CURRENCY = 'GEL';

    private const int TETRI_PER_GEL = 100;

    /**
     * Lari (as Quickshipper or Magento give it) → integer tetri.
     *
     * @param int|float|string $gel
     * @return int
     */
    public static function toTetri(int|float|string $gel): int
    {
        return (int)round((float)$gel * self::TETRI_PER_GEL);
    }

    /**
     * Tetri → lari as a number for Quickshipper's API, e.g. 550 → 5.5.
     *
     * @param int $tetri
     * @return float
     */
    public static function toGelAmount(int $tetri): float
    {
        return round($tetri / self::TETRI_PER_GEL, 2);
    }

    /**
     * Tetri → lari for display, e.g. 550 → "5.50".
     *
     * @param int $tetri
     * @return string
     */
    public static function toGel(int $tetri): string
    {
        return number_format($tetri / self::TETRI_PER_GEL, 2, '.', '');
    }
}

<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model;

use Shubo\Qshpr\Model\Carrier\Config;

/**
 * Magento weights → kilograms, the unit Quickshipper prices in.
 *
 * Magento stores product weights in the unit the store is set to ("lbs" by
 * default, or "kgs").
 */
class WeightConverter
{
    private const string WEIGHT_UNIT_LBS = 'lbs';
    private const float KG_PER_LB = 0.45359237;
    private const int PRECISION = 3;

    /**
     * @param Config $config
     */
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * A weight in the store's unit, in kg (3 decimals).
     *
     * @param float $weight
     * @param int|null $storeId
     * @return float
     */
    public function toKg(float $weight, ?int $storeId = null): float
    {
        $kg = $this->config->getWeightUnit($storeId) === self::WEIGHT_UNIT_LBS ? $weight * self::KG_PER_LB : $weight;

        return round($kg, self::PRECISION);
    }
}

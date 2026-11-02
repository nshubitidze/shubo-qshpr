<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Carrier;

/**
 * Where the courier collects: the shop's pickup coordinates, address and
 * contact, all set (see Config::getPickupPoint()).
 */
class PickupPoint
{
    /**
     * @param float $latitude
     * @param float $longitude
     * @param string $street Shipping Origin street line.
     * @param string $city Shipping Origin city.
     * @param string $name Store Information name.
     * @param string $phone Store Information phone.
     */
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly string $street,
        public readonly string $city,
        public readonly string $name,
        public readonly string $phone,
    ) {
    }
}

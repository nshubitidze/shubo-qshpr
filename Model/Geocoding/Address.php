<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Geocoding;

/**
 * The part of a shipping address a geocoder needs, as the customer typed it.
 */
class Address
{
    /**
     * @param string $street First street line (street and house number).
     * @param string $city
     * @param string $countryId ISO 3166-1 alpha-2, e.g. "GE".
     */
    public function __construct(
        public readonly string $street,
        public readonly string $city,
        public readonly string $countryId,
    ) {
    }
}

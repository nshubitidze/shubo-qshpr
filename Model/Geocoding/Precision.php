<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Geocoding;

/**
 * How exactly a geocoder located an address. Only House is precise enough to
 * price a courier delivery; a street-level point can be hundreds of metres
 * off.
 */
enum Precision: string
{
    case House = 'house';
    case Street = 'street';
    case Area = 'area';
}

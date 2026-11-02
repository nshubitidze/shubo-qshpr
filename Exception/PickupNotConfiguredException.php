<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * The store's pickup point is incomplete: a coordinate, the Shipping Origin
 * street or city, or the Store Information name or phone is missing.
 * Quickshipper cannot price or book a delivery without all of them.
 */
class PickupNotConfiguredException extends LocalizedException
{
}

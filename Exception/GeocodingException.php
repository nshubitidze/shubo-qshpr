<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * A geocoder could not answer: transport error, unexpected HTTP status or an
 * unreadable response. Not thrown for "address not found" — that is a normal
 * null result.
 */
class GeocodingException extends LocalizedException
{
}

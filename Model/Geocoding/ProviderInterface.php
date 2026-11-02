<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Geocoding;

use Shubo\Qshpr\Exception\GeocodingException;

/**
 * A geocoding service (OpenStreetMap Nominatim, Google, …).
 *
 * Providers are registered in di.xml (Geocoder's "providers" argument): the
 * item's key is the provider's code, with its admin label and the model. The
 * merchant picks one in the admin. A provider only asks its service and
 * reports what it found — deciding whether the answer is good enough,
 * naming the provider on it, and caching it, is the Geocoder's job.
 */
interface ProviderInterface
{
    /**
     * Look up one address.
     *
     * @param string $query The normalized one-line address ("street 12, city").
     * @param Address $address The address as typed (country, city, …).
     * @param int|null $storeId
     * @return Location|null The best match, or null when the service found nothing.
     * @throws GeocodingException When the service could not be asked or its answer is unreadable.
     */
    public function search(string $query, Address $address, ?int $storeId): ?Location;
}

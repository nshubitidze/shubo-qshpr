<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Geocoding;

/**
 * A geocoder's answer for one address.
 */
class Location
{
    /**
     * @param float $latitude
     * @param float $longitude
     * @param Precision $precision
     * @param string $city The city the geocoder placed the address in, in the
     *     language it was asked for; empty when it did not say.
     * @param string $provider Code of the geocoder that answered; set by the Geocoder.
     */
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly Precision $precision,
        public readonly string $city,
        public readonly string $provider = '',
    ) {
    }

    /**
     * The same answer, naming the geocoder that gave it.
     *
     * @param string $provider The provider's code (its key in di.xml).
     * @return self
     */
    public function withProvider(string $provider): self
    {
        return new self($this->latitude, $this->longitude, $this->precision, $this->city, $provider);
    }

    /**
     * Plain-array form, for the cache.
     *
     * @return array{lat: float, lng: float, precision: string, city: string, provider: string}
     */
    public function toArray(): array
    {
        return [
            'lat' => $this->latitude,
            'lng' => $this->longitude,
            'precision' => $this->precision->value,
            'city' => $this->city,
            'provider' => $this->provider,
        ];
    }
}

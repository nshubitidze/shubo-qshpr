<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Geocoding;

use Laminas\Http\Response as HttpResponse;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Shubo\Qshpr\Exception\GeocodingException;
use Shubo\Qshpr\Model\Carrier\Config;

/**
 * OpenStreetMap Nominatim (https://nominatim.org), on the public server or a
 * self-hosted one — same API, different URL.
 *
 * Precision comes from Nominatim's place_rank: 30 for buildings, house
 * numbers and points of interest, 26–29 for streets, lower for areas. The
 * public server's usage policy asks for an identifying User-Agent, at most one
 * request per second and caching of results (the Geocoder caches).
 */
class NominatimProvider implements ProviderInterface
{
    private const string SEARCH_PATH = '/search';
    private const int RANK_HOUSE = 30;
    private const int RANK_STREET = 26;

    /**
     * Keys of Nominatim's "address" object that can name the settlement, most
     * specific first.
     */
    private const array CITY_KEYS = ['city', 'town', 'village'];

    /**
     * @param CurlFactory $curlFactory
     * @param Config $config
     * @param Json $json
     * @param StoreManagerInterface $storeManager
     * @param int $timeoutSeconds Set in di.xml; a slow self-hosted server may need more.
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Config $config,
        private readonly Json $json,
        private readonly StoreManagerInterface $storeManager,
        private readonly int $timeoutSeconds = 5,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function search(string $query, Address $address, ?int $storeId): ?Location
    {
        $url = $this->config->getGeocoderUrl($storeId) . self::SEARCH_PATH . '?' . http_build_query([
            'q' => $query,
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'limit' => 1,
            'countrycodes' => strtolower($address->countryId),
            // Answer in the script the customer typed the city in, so the
            // returned city name can be compared with it.
            'accept-language' => preg_match('/\p{Georgian}/u', $address->city) ? 'ka' : 'en',
        ]);

        $curl = $this->curlFactory->create();
        $storeUrl = $this->storeManager->getStore($storeId)->getBaseUrl();
        $curl->setHeaders([
            'User-Agent' => 'Shubo_Qshpr Magento module (' . $storeUrl . ')',
            'Accept' => 'application/json',
        ]);
        $curl->setTimeout($this->timeoutSeconds);

        try {
            $curl->get($url);
        } catch (\Exception $e) {
            throw new GeocodingException(__('Nominatim request failed: %1', $e->getMessage()), $e);
        }

        if ($curl->getStatus() !== HttpResponse::STATUS_CODE_200) {
            throw new GeocodingException(__('Nominatim returned HTTP %1.', $curl->getStatus()));
        }

        try {
            $results = $this->json->unserialize($curl->getBody());
        } catch (\InvalidArgumentException $e) {
            throw new GeocodingException(__('Nominatim returned an unreadable response.'), $e);
        }

        if (!is_array($results)) {
            throw new GeocodingException(__('Nominatim returned an unreadable response.'));
        }

        $best = $results[0] ?? null;

        if (!is_array($best) || !is_numeric($best['lat'] ?? null) || !is_numeric($best['lon'] ?? null)) {
            return null;
        }

        return new Location(
            (float)$best['lat'],
            (float)$best['lon'],
            $this->precision((int)($best['place_rank'] ?? 0)),
            $this->city(is_array($best['address'] ?? null) ? $best['address'] : []),
        );
    }

    /**
     * Map Nominatim's place_rank to a precision.
     *
     * @param int $rank
     * @return Precision
     */
    private function precision(int $rank): Precision
    {
        return match (true) {
            $rank >= self::RANK_HOUSE => Precision::House,
            $rank >= self::RANK_STREET => Precision::Street,
            default => Precision::Area,
        };
    }

    /**
     * The settlement name from Nominatim's address details.
     *
     * @param array<string, mixed> $address
     * @return string
     */
    private function city(array $address): string
    {
        foreach (self::CITY_KEYS as $key) {
            if (is_string($address[$key] ?? null) && $address[$key] !== '') {
                return $address[$key];
            }
        }

        return '';
    }
}

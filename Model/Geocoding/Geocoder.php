<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Geocoding;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Shubo\Qshpr\Exception\GeocodingException;
use Shubo\Qshpr\Model\Carrier\Config;
use Shubo\Qshpr\Model\LogSanitizer;

/**
 * Turns a shipping address into coordinates precise enough to price a courier
 * delivery, or says it cannot (null).
 *
 * Quickshipper prices and dispatches by coordinates and does not geocode
 * itself, so this is what every rate lookup starts with. The geocoding service
 * is the one picked in the admin, from the providers registered in di.xml.
 *
 * Only answers that are exact to the building AND lie in the city the
 * customer typed are used: a street-level point can be hundreds of metres
 * off, and a common street name can match a street in another city — either
 * would price, and send a courier to, the wrong place.
 *
 * Answers are cached (the public Nominatim server's usage policy requires it):
 * found addresses for 30 days — also the longest Google's terms allow — and
 * "not found" for a day. The cache key is an HMAC of the query, so no address
 * text is stored. A service failure is not cached; the next attempt asks again.
 */
class Geocoder
{
    private const string CACHE_KEY_PREFIX = 'shubo_qshpr_geocode_';
    private const string CACHE_TAG = 'SHUBO_QSHPR_GEOCODE';
    private const string CACHED_NOT_FOUND = 'none';
    private const int FOUND_CACHE_SECONDS = 2592000;
    private const int NOT_FOUND_CACHE_SECONDS = 86400;

    /**
     * @param AddressNormalizer $normalizer
     * @param Config $config
     * @param CacheInterface $cache
     * @param EncryptorInterface $encryptor
     * @param Json $json
     * @param LogSanitizer $logSanitizer
     * @param LoggerInterface $logger Bound to ShuboQshprLogger in di.xml.
     * @param array<string, array{label: string, model: ProviderInterface}> $providers By provider code (di.xml).
     */
    public function __construct(
        private readonly AddressNormalizer $normalizer,
        private readonly Config $config,
        private readonly CacheInterface $cache,
        private readonly EncryptorInterface $encryptor,
        private readonly Json $json,
        private readonly LogSanitizer $logSanitizer,
        private readonly LoggerInterface $logger,
        private readonly array $providers = [],
    ) {
    }

    /**
     * Coordinates of the address, or null when they cannot be known precisely.
     *
     * @param Address $address
     * @param int|null $storeId
     * @return Location|null
     */
    public function locate(Address $address, ?int $storeId = null): ?Location
    {
        if (trim($address->street) === '' || trim($address->city) === '') {
            return null;
        }

        $code = $this->config->getGeocoder($storeId);
        $provider = $this->provider($code);

        if ($provider === null) {
            $this->logger->error(sprintf('geocoder "%s" is not available', $this->logSanitizer->line($code)));

            return null;
        }

        $query = $this->config->isAddressCleanUpEnabled($storeId)
            ? $this->normalizer->normalize($address)
            : $this->normalizer->plain($address);
        $cacheKey = self::CACHE_KEY_PREFIX . $this->encryptor->hash(
            implode("\n", [$code, $address->countryId, $query])
        );
        $cached = $this->cache->load($cacheKey);

        if (is_string($cached) && $cached !== '') {
            $location = $cached === self::CACHED_NOT_FOUND ? null : $this->fromCache($cached);
        } else {
            $location = $this->ask($provider, $code, $query, $address, $storeId);

            if ($location === false) {
                return null;
            }

            $this->cache->save(
                $location === null ? self::CACHED_NOT_FOUND : $this->json->serialize($location->toArray()),
                $cacheKey,
                [self::CACHE_TAG],
                $location === null ? self::NOT_FOUND_CACHE_SECONDS : self::FOUND_CACHE_SECONDS
            );
        }

        return $location !== null && $this->isUsable($location, $address) ? $location : null;
    }

    /**
     * Names and codes of the registered geocoders, for the admin list.
     *
     * @return array<string, Phrase> Label by provider code.
     */
    public function getProviderLabels(): array
    {
        $labels = [];

        foreach (array_keys($this->providers) as $code) {
            $label = $this->providers[$code]['label'] ?? null;

            if ($this->provider((string)$code) !== null && is_string($label) && $label !== '') {
                // The label is marked translatable in di.xml, so it is in the module's phrases.
                $labels[(string)$code] = __($label);
            }
        }

        return $labels;
    }

    /**
     * The provider registered under a code; null when there is none or its entry is malformed.
     *
     * @param string $code
     * @return ProviderInterface|null
     */
    private function provider(string $code): ?ProviderInterface
    {
        $entry = $this->providers[$code] ?? null;
        $model = is_array($entry) ? ($entry['model'] ?? null) : null;

        return $model instanceof ProviderInterface ? $model : null;
    }

    /**
     * Ask the provider and log one line about it.
     *
     * @param ProviderInterface $provider
     * @param string $code
     * @param string $query
     * @param Address $address
     * @param int|null $storeId
     * @return Location|null|false The answer, null for "not found", false when the service failed.
     */
    private function ask(
        ProviderInterface $provider,
        string $code,
        string $query,
        Address $address,
        ?int $storeId,
    ): Location|null|false {
        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug(sprintf('geocode %s query: %s', $code, $this->logSanitizer->line($query)));
        }

        $startedAt = microtime(true);

        try {
            $location = $provider->search($query, $address, $storeId)?->withProvider($code);
        } catch (GeocodingException $e) {
            $this->logger->error(sprintf(
                'geocode %s failed after %dms: %s',
                $code,
                $this->elapsedMs($startedAt),
                $this->logSanitizer->line($e->getMessage())
            ));

            return false;
        }

        $this->logger->info(sprintf(
            'geocode %s in %dms: %s',
            $code,
            $this->elapsedMs($startedAt),
            $location === null ? 'not found' : $location->precision->value . ' level'
        ));

        return $location;
    }

    /**
     * Whether an answer is precise enough to price a delivery.
     *
     * @param Location $location
     * @param Address $address
     * @return bool
     */
    private function isUsable(Location $location, Address $address): bool
    {
        return $location->precision === Precision::House
            && $this->sameCity($location->city, $address->city);
    }

    /**
     * Compare city names case- and space-insensitively.
     *
     * @param string $found
     * @param string $typed
     * @return bool
     */
    private function sameCity(string $found, string $typed): bool
    {
        $canonical = static fn (string $name): string => mb_strtolower(trim((string)preg_replace('~\s+~u', ' ', $name)));

        return $found !== '' && $canonical($found) === $canonical($typed);
    }

    /**
     * Rebuild a cached Location (Location::toArray() as JSON); null when unreadable.
     *
     * @param string $value
     * @return Location|null
     */
    private function fromCache(string $value): ?Location
    {
        try {
            $data = $this->json->unserialize($value);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $precision = is_array($data) && is_string($data['precision'] ?? null)
            ? Precision::tryFrom($data['precision'])
            : null;

        if ($precision === null || !is_numeric($data['lat'] ?? null) || !is_numeric($data['lng'] ?? null)) {
            return null;
        }

        return new Location(
            (float)$data['lat'],
            (float)$data['lng'],
            $precision,
            (string)($data['city'] ?? ''),
            (string)($data['provider'] ?? ''),
        );
    }

    /**
     * Milliseconds since $startedAt.
     *
     * @param float $startedAt
     * @return int
     */
    private function elapsedMs(float $startedAt): int
    {
        return (int)round((microtime(true) - $startedAt) * 1000);
    }
}

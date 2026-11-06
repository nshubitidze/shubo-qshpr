<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Rates;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Psr\Log\LoggerInterface;
use Shubo\Qshpr\Exception\QshprApiException;
use Shubo\Qshpr\Exception\PickupNotConfiguredException;
use Shubo\Qshpr\Model\Carrier\Config;
use Shubo\Qshpr\Model\Geocoding\Address;
use Shubo\Qshpr\Model\Geocoding\Geocoder;
use Shubo\Qshpr\Model\Money;
use Shubo\Qshpr\Model\QshprClient;
use Shubo\Qshpr\Model\WeightConverter;

/**
 * Turns a checkout rate request into Quickshipper courier offers.
 *
 * Every reason Quickshipper cannot be offered — store currency not GEL, no
 * pickup coordinates, an address that cannot be located to the building,
 * a timeout or API error — gives an empty list, so checkout simply shows the
 * other shipping methods.
 *
 * Magento asks for rates several times during one checkout, so answers are
 * cached for 10 minutes per trip. That also softens Quickshipper's slow first
 * answer after a quiet period. How long a quote stays valid is not
 * documented; the courier is booked later with a fresh quote.
 */
class RateQuoter
{
    private const string CACHE_KEY_PREFIX = 'shubo_qshpr_fees_';
    private const string CACHE_TAG = 'SHUBO_QSHPR_RATES';
    private const int CACHE_SECONDS = 600;

    /**
     * Quickshipper reads the query flag as text: "true", not "1".
     */
    private const string QUERY_TRUE = 'true';

    /**
     * @param Geocoder $geocoder
     * @param QshprClient $client
     * @param Config $config
     * @param CacheInterface $cache
     * @param EncryptorInterface $encryptor
     * @param Json $json
     * @param WeightConverter $weightConverter
     * @param CarDeliveryRule $carDeliveryRule
     * @param LoggerInterface $logger Bound to ShuboQshprLogger in di.xml.
     */
    public function __construct(
        private readonly Geocoder $geocoder,
        private readonly QshprClient $client,
        private readonly Config $config,
        private readonly CacheInterface $cache,
        private readonly EncryptorInterface $encryptor,
        private readonly Json $json,
        private readonly WeightConverter $weightConverter,
        private readonly CarDeliveryRule $carDeliveryRule,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Courier offers for the request's destination, cheapest first.
     *
     * @param RateRequest $request
     * @return Offer[] Empty when Quickshipper cannot be offered.
     */
    public function quote(RateRequest $request): array
    {
        $storeId = $request->getStoreId() !== null ? (int)$request->getStoreId() : null;

        $currency = $this->config->getBaseCurrency($storeId);

        if ($currency !== Money::CURRENCY) {
            $this->logger->warning(sprintf('rates: base currency %s is not GEL, Quickshipper not offered', $currency));

            return [];
        }

        try {
            $pickup = $this->config->getPickupPoint($storeId);
        } catch (PickupNotConfiguredException $e) {
            $this->logger->error(sprintf('rates: Quickshipper not offered: %s', $e->getMessage()));

            return [];
        }

        $street = strtok((string)$request->getDestStreet(), "\n") ?: '';
        $city = (string)$request->getDestCity();
        $location = $this->geocoder->locate(new Address($street, $city, (string)$request->getDestCountryId()), $storeId);

        if ($location === null) {
            return [];
        }

        $query = [
            'FromStreetName' => $pickup->street,
            'FromCityName' => $pickup->city,
            'FromLatitude' => $pickup->latitude,
            'FromLongitude' => $pickup->longitude,
            'ToStreetName' => $street,
            'ToCityName' => $city,
            'ToLatitude' => $location->latitude,
            'ToLongitude' => $location->longitude,
            'CartAmount' => round((float)$request->getPackageValue(), 2),
        ];

        $weightKg = $this->weightConverter->toKg((float)$request->getPackageWeight(), $storeId);

        if ($weightKg > 0) {
            $query['CartWeight'] = $weightKg;
        }

        // Part of the query, so also part of the cache key.
        if ($this->carDeliveryRule->forRateRequest($request, $storeId, $weightKg)) {
            $query['CarDelivery'] = self::QUERY_TRUE;
        }

        $fees = $this->fees($query, $storeId);

        return $fees === null ? [] : $this->offers($fees);
    }

    /**
     * The "fees" list for a trip, from the cache or Quickshipper; null on failure.
     *
     * @param array<string, string|int|float> $query
     * @param int|null $storeId
     * @return array<mixed>|null
     */
    private function fees(array $query, ?int $storeId): ?array
    {
        // HMAC of the whole query: no address text in the cache key.
        $cacheKey = self::CACHE_KEY_PREFIX . $this->encryptor->hash($this->json->serialize($query));
        $cached = $this->cache->load($cacheKey);

        if (is_string($cached) && $cached !== '') {
            try {
                $fees = $this->json->unserialize($cached);
            } catch (\InvalidArgumentException) {
                $fees = null;
            }

            if (is_array($fees)) {
                return $fees;
            }
        }

        try {
            $response = $this->client->getFees($query, $storeId);
        } catch (QshprApiException) {
            // QshprClient has logged the failure.
            return null;
        }

        $fees = is_array($response['fees'] ?? null) ? $response['fees'] : [];
        $this->cache->save($this->json->serialize($fees), $cacheKey, [self::CACHE_TAG], self::CACHE_SECONDS);

        return $fees;
    }

    /**
     * Turn Quickshipper's fees list into offers: one per active courier and
     * GEL price, cheapest first. Malformed entries are skipped.
     *
     * @param array<mixed> $fees
     * @return Offer[]
     */
    private function offers(array $fees): array
    {
        $offers = [];

        foreach ($fees as $fee) {
            if (!is_array($fee)
                || ($fee['isActive'] ?? true) === false
                || !is_numeric($fee['providerId'] ?? null)
                || !is_array($fee['prices'] ?? null)
            ) {
                continue;
            }

            foreach ($fee['prices'] as $price) {
                if (!is_array($price)
                    || ($price['currencyName'] ?? null) !== Money::CURRENCY
                    || !is_numeric($price['amount'] ?? null)
                    || (float)$price['amount'] <= 0
                    || !is_scalar($price['id'] ?? null)
                    || !preg_match('/[A-Za-z0-9]/', (string)$price['id'])
                ) {
                    continue;
                }

                $offers[] = new Offer(
                    (int)$fee['providerId'],
                    trim((string)($fee['providerName'] ?? '')),
                    (string)$price['id'],
                    trim((string)($price['deliverySpeedName'] ?? '')),
                    Money::toTetri($price['amount']),
                    is_numeric($fee['serviceFee'] ?? null) ? Money::toTetri($fee['serviceFee']) : 0,
                    is_string($fee['providerLogoUrl'] ?? null) ? $fee['providerLogoUrl'] : '',
                );
            }
        }

        usort($offers, static fn (Offer $a, Offer $b): int => $a->amount <=> $b->amount);

        return $offers;
    }
}

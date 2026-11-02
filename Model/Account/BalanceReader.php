<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Account;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Shubo\Qshpr\Exception\QshprApiException;
use Shubo\Qshpr\Model\Carrier\Config;
use Shubo\Qshpr\Model\QshprClient;

/**
 * The merchant's Quickshipper balance, as Quickshipper words it (e.g. "6.80 ₾").
 *
 * Quickshipper accounts are prepaid and a booking with too little balance is
 * refused, so the admin shows the balance. GET /v1/Account returns it as a
 * display string, not a number, so it is shown as given and never parsed.
 *
 * Kept in the cache for a few minutes per Quickshipper login, so busy admin
 * pages do not call Quickshipper on every load; a failed read is cached
 * briefly too, so an outage does not make every page wait for it again.
 */
class BalanceReader
{
    private const string CACHE_KEY_PREFIX = 'shubo_qshpr_balance_';
    private const string CACHE_TAG = 'SHUBO_QSHPR_BALANCE';
    private const string CACHED_UNKNOWN = 'none';
    private const int CACHE_SECONDS = 300;
    private const int UNKNOWN_CACHE_SECONDS = 60;
    private const string FIELD_BALANCE = 'balance';

    /**
     * @param QshprClient $client
     * @param Config $config
     * @param CacheInterface $cache
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly QshprClient $client,
        private readonly Config $config,
        private readonly CacheInterface $cache,
        private readonly EncryptorInterface $encryptor,
    ) {
    }

    /**
     * The balance of the store's Quickshipper account; null when it cannot be read.
     *
     * @param int|null $storeId
     * @return string|null
     */
    public function getBalance(?int $storeId = null): ?string
    {
        $username = $this->config->getUsername($storeId);

        if ($username === '') {
            return null;
        }

        $cacheKey = self::CACHE_KEY_PREFIX . $this->encryptor->hash(implode("\n", [
            $this->config->getApiUrl($storeId),
            $username,
        ]));
        $cached = $this->cache->load($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached === self::CACHED_UNKNOWN ? null : $cached;
        }

        $balance = $this->read($storeId);
        $this->cache->save(
            $balance ?? self::CACHED_UNKNOWN,
            $cacheKey,
            [self::CACHE_TAG],
            $balance === null ? self::UNKNOWN_CACHE_SECONDS : self::CACHE_SECONDS
        );

        return $balance;
    }

    /**
     * Ask Quickshipper; the client logs any failure.
     *
     * @param int|null $storeId
     * @return string|null
     */
    private function read(?int $storeId): ?string
    {
        try {
            $balance = $this->client->getAccount($storeId)[self::FIELD_BALANCE] ?? null;
        } catch (QshprApiException) {
            return null;
        }

        return is_scalar($balance) && trim((string)$balance) !== '' ? trim((string)$balance) : null;
    }
}

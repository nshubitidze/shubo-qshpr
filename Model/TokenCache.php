<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config as AppConfig;
use Magento\Framework\Encryption\EncryptorInterface;
use Shubo\Qshpr\Exception\QshprApiException;
use Shubo\Qshpr\Model\Carrier\Config;

/**
 * Keeps the Quickshipper bearer token in Magento's cache, encrypted.
 *
 * The key is derived (HMAC with the deployment key) from the environment and
 * the credentials, so changing any of them in the admin stops the old token
 * being used, and the password never appears in the key. Quickshipper issues
 * tokens for a year, but one is kept at most MAX_SECONDS: a one-year bearer
 * credential should not sit in a cache that long, and a fresh login costs one
 * request. Tagged with the config cache, so flushing that drops it too.
 */
class TokenCache
{
    private const string KEY_PREFIX = 'shubo_qshpr_token_';
    private const int MAX_SECONDS = 86400;

    /**
     * Stop using a token this long before Quickshipper says it expires.
     */
    private const int EXPIRY_MARGIN_SECONDS = 60;

    /**
     * @param Config $config
     * @param CacheInterface $cache
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly Config $config,
        private readonly CacheInterface $cache,
        private readonly EncryptorInterface $encryptor,
    ) {
    }

    /**
     * The cached token for the store's configured account, if any.
     *
     * @param int|null $storeId
     * @return string|null
     * @throws QshprApiException When the credentials are not configured.
     */
    public function load(?int $storeId): ?string
    {
        $cached = $this->cache->load($this->key($storeId));
        $token = is_string($cached) && $cached !== '' ? $this->encryptor->decrypt($cached) : '';

        return $token !== '' ? $token : null;
    }

    /**
     * Cache a freshly issued token.
     *
     * @param string $token
     * @param int $expiresIn Seconds, as reported by Quickshipper (0 when unknown).
     * @param int|null $storeId
     * @return int How long the token is cached, in seconds.
     * @throws QshprApiException When the credentials are not configured.
     */
    public function save(#[\SensitiveParameter] string $token, int $expiresIn, ?int $storeId): int
    {
        // Never 0 — to the cache that means "no expiry". Without a usable
        // expires_in the token is used once and the next call logs in again.
        $lifetime = max(1, min(self::MAX_SECONDS, $expiresIn - self::EXPIRY_MARGIN_SECONDS));
        $this->cache->save($this->encryptor->encrypt($token), $this->key($storeId), [AppConfig::CACHE_TAG], $lifetime);

        return $lifetime;
    }

    /**
     * Forget the cached token, e.g. after Quickshipper rejected it.
     *
     * @param int|null $storeId
     * @return void
     * @throws QshprApiException When the credentials are not configured.
     */
    public function remove(?int $storeId): void
    {
        $this->cache->remove($this->key($storeId));
    }

    /**
     * Cache key bound to the environment and credentials.
     *
     * @param int|null $storeId
     * @return string
     * @throws QshprApiException
     */
    private function key(?int $storeId): string
    {
        return self::KEY_PREFIX . $this->encryptor->hash(implode("\n", [
            $this->config->getAuthUrl($storeId),
            $this->config->getClientId($storeId, strict: true),
            $this->config->getUsername($storeId, strict: true),
            $this->config->getPassword($storeId, strict: true),
        ]));
    }
}

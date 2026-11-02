<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Rates;

use Magento\Framework\App\CacheInterface;

/**
 * Remembers each courier's logo URL from Quickshipper's price answers, so the
 * checkout can show it beside the courier's shipping method.
 *
 * Magento keeps only standard fields of a shipping rate (code, titles,
 * price), so the logo cannot travel with the rate itself; it is kept per
 * courier id instead. Courier ids and their logos are Quickshipper's and
 * rarely change; every price answer refreshes them.
 */
class CourierLogos
{
    private const string CACHE_KEY_PREFIX = 'shubo_qshpr_logo_';
    private const string CACHE_TAG = 'SHUBO_QSHPR_RATES';
    private const int CACHE_SECONDS = 604800;
    private const string SECURE_PREFIX = 'https://';

    /**
     * @param CacheInterface $cache
     */
    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Keep a courier's logo URL; ignored unless it is a usable https URL.
     *
     * @param int $providerId
     * @param string $url
     * @return void
     */
    public function remember(int $providerId, string $url): void
    {
        $url = $this->clean($url);

        if ($url !== '' && $this->cache->load($this->cacheKey($providerId)) !== $url) {
            $this->cache->save($url, $this->cacheKey($providerId), [self::CACHE_TAG], self::CACHE_SECONDS);
        }
    }

    /**
     * The courier's logo URL, or '' when none is known.
     *
     * @param int $providerId
     * @return string
     */
    public function get(int $providerId): string
    {
        $url = $this->cache->load($this->cacheKey($providerId));

        return is_string($url) ? $url : '';
    }

    /**
     * A logo URL safe to put in an img tag: https only, spaces encoded.
     *
     * Quickshipper sends some logo URLs with a raw space in the file name
     * ("…-image 12.png"), which is not a valid URL until encoded.
     *
     * @param string $url
     * @return string
     */
    private function clean(string $url): string
    {
        $url = str_replace(' ', '%20', trim($url));

        return str_starts_with(strtolower($url), self::SECURE_PREFIX) && filter_var($url, FILTER_VALIDATE_URL) !== false
            ? $url
            : '';
    }

    /**
     * Cache key of one courier's logo.
     *
     * @param int $providerId
     * @return string
     */
    private function cacheKey(int $providerId): string
    {
        return self::CACHE_KEY_PREFIX . $providerId;
    }
}

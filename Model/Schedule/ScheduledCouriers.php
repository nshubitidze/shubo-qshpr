<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Schedule;

use Magento\Framework\App\CacheInterface;

/**
 * Which couriers take a delivery time, as Quickshipper says.
 *
 * Quickshipper tells per courier, in every price answer, whether it takes
 * a scheduled delivery (hasScheduledDelivery). Magento keeps only standard
 * fields of a shipping rate, so the answer is remembered here per courier
 * id, refreshed by every price answer.
 */
class ScheduledCouriers
{
    private const string CACHE_KEY_PREFIX = 'shubo_qshpr_schedule_';
    private const string CACHE_TAG = 'SHUBO_QSHPR_RATES';
    private const int CACHE_SECONDS = 86400;
    private const string CACHED_YES = '1';
    private const string CACHED_NO = '0';

    /**
     * @param CacheInterface $cache
     */
    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Keep what Quickshipper said about a courier and delivery times.
     *
     * @param int $providerId
     * @param bool $takesTime
     * @return void
     */
    public function remember(int $providerId, bool $takesTime): void
    {
        $this->cache->save(
            $takesTime ? self::CACHED_YES : self::CACHED_NO,
            self::CACHE_KEY_PREFIX . $providerId,
            [self::CACHE_TAG],
            self::CACHE_SECONDS
        );
    }

    /**
     * Whether the courier takes a delivery time. False when Quickshipper has
     * not said so recently: a time the courier ignores would be a promise
     * the shop cannot keep.
     *
     * @param int $providerId
     * @return bool
     */
    public function takesTime(int $providerId): bool
    {
        return $this->cache->load(self::CACHE_KEY_PREFIX . $providerId) === self::CACHED_YES;
    }
}

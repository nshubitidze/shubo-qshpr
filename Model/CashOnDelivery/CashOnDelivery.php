<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\CashOnDelivery;

use Magento\Framework\App\CacheInterface;

/**
 * Cash on delivery with Quickshipper couriers: which payment methods are
 * "pay the courier in cash", and which couriers can collect cash.
 *
 * Quickshipper tells per courier, in every price answer, whether it can
 * collect cash (hasCashOnDelivery); some courier companies forbid cash.
 * Magento keeps only standard fields of a shipping rate, so the answer is
 * remembered here per courier id, refreshed by every price answer.
 *
 * The payment method codes come from di.xml ("paymentMethods"), so a shop
 * that uses another cash-on-delivery method can add its code there.
 */
class CashOnDelivery
{
    private const string CACHE_KEY_PREFIX = 'shubo_qshpr_cod_';
    private const string CACHE_TAG = 'SHUBO_QSHPR_RATES';
    private const int CACHE_SECONDS = 86400;
    private const string CACHED_YES = '1';
    private const string CACHED_NO = '0';

    /**
     * @param CacheInterface $cache
     * @param string[] $paymentMethods Codes of cash-on-delivery payment methods (di.xml).
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly array $paymentMethods = [],
    ) {
    }

    /**
     * Whether a payment method means the courier collects the money.
     *
     * @param string $paymentMethodCode
     * @return bool
     */
    public function isCashPayment(string $paymentMethodCode): bool
    {
        return in_array($paymentMethodCode, $this->paymentMethods, true);
    }

    /**
     * Keep what Quickshipper said about a courier and cash.
     *
     * @param int $providerId
     * @param bool $collectsCash
     * @return void
     */
    public function remember(int $providerId, bool $collectsCash): void
    {
        $this->cache->save(
            $collectsCash ? self::CACHED_YES : self::CACHED_NO,
            self::CACHE_KEY_PREFIX . $providerId,
            [self::CACHE_TAG],
            self::CACHE_SECONDS
        );
    }

    /**
     * Whether the courier can collect cash. False when Quickshipper has not
     * said so recently: offering cash to a courier that refuses it would
     * leave the shop with an order nobody collects payment for.
     *
     * @param int $providerId
     * @return bool
     */
    public function courierCollectsCash(int $providerId): bool
    {
        return $this->cache->load(self::CACHE_KEY_PREFIX . $providerId) === self::CACHED_YES;
    }
}

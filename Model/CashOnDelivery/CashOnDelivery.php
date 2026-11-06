<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\CashOnDelivery;

/**
 * Cash on delivery and Quickshipper: which payment methods mean "pay the
 * courier in cash".
 *
 * Quickshipper has no cash-on-delivery service (its answer of 2026-11-06),
 * although its API still carries cashOnDelivery fields and a per-courier
 * hasCashOnDelivery flag. A courier booked through it would hand over the
 * goods without collecting the money, so these methods are never offered
 * with a Quickshipper courier and an order paid with one is never booked.
 *
 * The payment method codes come from di.xml ("paymentMethods"), so a shop
 * that uses another cash-on-delivery method can add its code there.
 */
class CashOnDelivery
{
    /**
     * @param string[] $paymentMethods Codes of cash-on-delivery payment methods (di.xml).
     */
    public function __construct(
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
}

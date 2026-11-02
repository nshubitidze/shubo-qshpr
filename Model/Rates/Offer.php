<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Rates;

/**
 * One courier price for one trip, as Quickshipper quoted it.
 */
class Offer
{
    /**
     * @param int $providerId Quickshipper's courier id (Wolt, Glovo, OnWay, …).
     * @param string $providerName
     * @param string $feeId Quickshipper's id for this price ("asap", "85", …),
     *     sent back when the courier is booked.
     * @param string $speedName e.g. "45-60 min delivery⚡".
     * @param int $amount Quickshipper's price in tetri (GEL minor units), its service fee included:
     *     what is taken from the merchant's Quickshipper balance.
     * @param int $serviceFee Quickshipper's service fee in tetri, the part of $amount that is not the courier's.
     * @param string $logoUrl The courier's logo as Quickshipper sends it; '' when none.
     * @param bool $collectsCash Whether the courier can collect cash on delivery.
     */
    public function __construct(
        public readonly int $providerId,
        public readonly string $providerName,
        public readonly string $feeId,
        public readonly string $speedName,
        public readonly int $amount,
        public readonly int $serviceFee,
        public readonly string $logoUrl = '',
        public readonly bool $collectsCash = false,
    ) {
    }

    /**
     * What the customer pays for this offer, in tetri.
     *
     * Quickshipper's amount already contains its service fee: the same trip
     * is quoted exactly the fee cheaper while an account is exempt from it.
     * So a shop that pays the fee itself takes it off the amount.
     *
     * @param bool $includeServiceFee
     * @return int
     */
    public function customerPrice(bool $includeServiceFee): int
    {
        return $includeServiceFee ? $this->amount : max(0, $this->amount - $this->serviceFee);
    }

    /**
     * Magento method code: courier id and price id, e.g. "2_asap", "25_85".
     *
     * The price id is the only per-price key Quickshipper always sends:
     * deliverySpeedId is null for Wolt and Glovo, and for OnWay both change
     * with the weight bracket (85/86/87). So the code stays the same while the
     * cart does, and a weight change asks the customer to pick again.
     *
     * @return string
     */
    public function methodCode(): string
    {
        return $this->providerId . '_' . self::priceKey($this->feeId);
    }

    /**
     * A Quickshipper price id as it appears in a method code: letters and
     * digits only, lower-cased ("ASAP" → "asap"), so it is safe in Magento's
     * method code. Booking compares fresh price ids in this form.
     *
     * @param string $feeId
     * @return string
     */
    public static function priceKey(string $feeId): string
    {
        return strtolower((string)preg_replace('/[^A-Za-z0-9]/', '', $feeId));
    }

    /**
     * Title shown at checkout, e.g. "Wolt – 45-60 min delivery⚡".
     *
     * @return string
     */
    public function title(): string
    {
        return $this->speedName === '' ? $this->providerName : $this->providerName . ' – ' . $this->speedName;
    }
}

<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Dispatch;

use Magento\Framework\Phrase;

/**
 * What happened when the merchant asked to cancel a shipped delivery.
 */
enum CancelOutcome: string
{
    /** Cancelled at Quickshipper now. */
    case Cancelled = 'cancelled';

    /** It had already been cancelled at Quickshipper; now recorded. */
    case WasCancelled = 'was_cancelled';

    /** Already delivered or failed: nothing to cancel. */
    case Ended = 'ended';

    /** A courier has it (or it is in a state Quickshipper cannot cancel). */
    case CourierHasIt = 'courier_has_it';

    /** Quickshipper reports a status this module does not know (or none). */
    case UnknownStatus = 'unknown_status';

    /** Quickshipper did not answer the cancel, and the order does not read as cancelled. */
    case NoAnswer = 'no_answer';

    /** No delivery under way for this shipment. */
    case NothingToCancel = 'nothing';

    /** Another request is working on the order right now. */
    case Busy = 'busy';

    /**
     * Whether the delivery is now off.
     *
     * @return bool
     */
    public function isOff(): bool
    {
        return match ($this) {
            self::Cancelled, self::WasCancelled => true,
            default => false,
        };
    }

    /**
     * What happened, for the merchant.
     *
     * @return Phrase
     */
    public function label(): Phrase
    {
        return match ($this) {
            self::Cancelled => __('cancelled'),
            self::WasCancelled => __('already cancelled at Quickshipper'),
            self::Ended => __('already delivered or failed'),
            self::CourierHasIt => __('a courier already has it'),
            self::UnknownStatus => __('status unknown to Magento'),
            self::NoAnswer => __('Quickshipper did not answer the cancel'),
            self::NothingToCancel => __('no delivery under way'),
            self::Busy => __('the order was being updated'),
        };
    }
}

<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * A courier could not be booked for a shipment. The message is written for
 * the merchant: it is shown in the admin and the shipment is not saved.
 */
class DispatchException extends LocalizedException
{
    /**
     * @param Phrase $phrase
     * @param \Exception|null $cause
     * @param int $code
     * @param bool $retryable Trying again later may succeed (Quickshipper did not
     *     answer, the order was busy); false for a refusal or a setup problem.
     */
    public function __construct(
        Phrase $phrase,
        ?\Exception $cause = null,
        int $code = 0,
        private readonly bool $retryable = false,
    ) {
        parent::__construct($phrase, $cause, $code);
    }

    /**
     * Whether an automatic retry may succeed.
     *
     * @return bool
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}

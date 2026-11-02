<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * A Quickshipper API call failed: missing credentials, transport error, login
 * refused, unexpected HTTP status, non-JSON body, or a business envelope with
 * success:false.
 */
class QshprApiException extends LocalizedException
{
    /**
     * @param Phrase $phrase
     * @param \Exception|null $cause
     * @param int $code
     * @param int|null $apiErrorCode Quickshipper's own status code when the
     *     failure is DEFINITIVE: the body's httpStatusCode of a success:false
     *     envelope, or 400 for a request validation error. Null when nothing
     *     definite came back (transport, login or server failure): the
     *     request may or may not have taken effect at Quickshipper.
     */
    public function __construct(
        Phrase $phrase,
        ?\Exception $cause = null,
        int $code = 0,
        private readonly ?int $apiErrorCode = null,
    ) {
        parent::__construct($phrase, $cause, $code);
    }

    /**
     * Quickshipper's status code for definitive failures, null otherwise.
     *
     * @return int|null
     */
    public function getApiErrorCode(): ?int
    {
        return $this->apiErrorCode;
    }
}

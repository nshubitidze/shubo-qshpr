<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Status;

use Laminas\Http\Response as HttpResponse;

/**
 * Outcome of one status update, and what the webhook answers Quickshipper.
 */
enum UpdateResult: string
{
    /** Not a booking of this store (another shop on the same account, or an old order). */
    case NotOurs = 'not_ours';

    /** Quickshipper reports what was already recorded, or the delivery has ended. */
    case Unchanged = 'unchanged';

    /** A new status or courier was recorded and noted on the shipment. */
    case Updated = 'updated';

    /** Another request is working on the order right now. */
    case Busy = 'busy';

    /**
     * HTTP status for the webhook answer: 503 asks Quickshipper to send it again.
     *
     * @return int
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::Busy => HttpResponse::STATUS_CODE_503,
            default => HttpResponse::STATUS_CODE_200,
        };
    }
}

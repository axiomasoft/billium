<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Handle to a separately verified, one-use host approval bound to the current writer ticket and archived bytes. */
#[Api(since: '1.0')]
final readonly class ReplayAuthorization
{
    public function __construct(public string $approvalRef, public string $writerTicketRef, public string $requestDigest, public DateTimeImmutable $expiresAt)
    {
        if ($approvalRef === '' || $writerTicketRef === '' || preg_match('/^sha256:[a-f0-9]{64}$/D', $requestDigest) !== 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid original replay approval binding');
        }
    }
}

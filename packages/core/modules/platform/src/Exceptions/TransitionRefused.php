<?php

declare(strict_types=1);

namespace Billium\Platform\Exceptions;

use Billium\Core\Errors\BilliumCoreErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Exceptions\CodedException;

/**
 * A session or module transition was refused or could not take its barrier in time. Nothing changed and the transition
 * claim rolled back; a seal refusal never asserts that the session was safely sealed (D54).
 */
#[Api(since: '1.0')]
final class TransitionRefused extends CodedException
{
    /** @param list<string> $blockers */
    public function __construct(public readonly string $reason, public readonly array $blockers = [])
    {
        parent::__construct('transition refused: '.$reason.($blockers === [] ? '' : ' ['.implode(', ', $blockers).']'));
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        $code = BilliumCoreErrorCode::TransitionRefused;
        $details = ['reason' => $this->reason];

        if ($this->blockers !== []) {
            $details['blockers'] = $this->blockers;
        }

        return new ErrorDescriptor(
            code: $code,
            category: $code->category(),
            retryable: $code->isRetryable(),
            details: $details,
        );
    }
}

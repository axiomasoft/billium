<?php

declare(strict_types=1);

namespace Billium\Platform\Exceptions;

use Billium\Contracts\Attributes\Api;
use RuntimeException;

/**
 * A session or module transition was refused or could not take its barrier in time. Nothing changed and the transition
 * claim rolled back; a seal refusal never asserts that the session was safely sealed (D54).
 */
#[Api]
final class TransitionRefused extends RuntimeException
{
    /** @param list<string> $blockers */
    public function __construct(public readonly string $reason, public readonly array $blockers = [])
    {
        parent::__construct('transition refused: '.$reason.($blockers === [] ? '' : ' ['.implode(', ', $blockers).']'));
    }
}

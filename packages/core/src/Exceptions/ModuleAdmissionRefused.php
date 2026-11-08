<?php

declare(strict_types=1);

namespace Billium\Core\Exceptions;

use Billium\Contracts\Attributes\Api;
use RuntimeException;

/**
 * Typed module-unavailable refusal of the admission barrier (D55): the module is missing, its lifecycle state closes the
 * operation class, or its schema generation is outside the descriptor write range. No receipt is stored; the claim
 * rolls back with the whole root Tx.
 */
#[Api]
final class ModuleAdmissionRefused extends RuntimeException
{
    public function __construct(public readonly string $moduleId, public readonly string $reason)
    {
        parent::__construct("module {$moduleId} refused: {$reason}");
    }
}

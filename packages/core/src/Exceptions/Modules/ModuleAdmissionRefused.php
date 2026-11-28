<?php

declare(strict_types=1);

namespace Billium\Core\Exceptions\Modules;

use Billium\Core\Errors\BilliumCoreErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Exceptions\CodedException;

/**
 * Typed module-unavailable refusal of the admission barrier: the module is missing, its lifecycle state closes the
 * operation class, or its schema generation is outside the descriptor write range. No receipt is stored; the claim
 * rolls back with the whole root Tx.
 */
#[Api(since: '1.0')]
final class ModuleAdmissionRefused extends CodedException
{
    public function __construct(public readonly string $moduleId, public readonly string $reason)
    {
        parent::__construct("module {$moduleId} refused: {$reason}");
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        $code = BilliumCoreErrorCode::ModuleAdmissionRefused;

        return new ErrorDescriptor(
            code: $code,
            category: $code->category(),
            retryable: $code->isRetryable(),
            details: [
                'module_id' => $this->moduleId,
                'reason' => $this->reason,
            ],
        );
    }
}

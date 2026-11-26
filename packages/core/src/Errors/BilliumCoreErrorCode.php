<?php

declare(strict_types=1);

namespace Billium\Core\Errors;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Runtime and admission error codes emitted by `axiomasoft/billium-core`.
 */
#[Api(since: '1.0')]
enum BilliumCoreErrorCode: string implements ErrorCode
{
    case LockOrderViolation = 'billium.lock_order_violation';
    case LockPlanChanged = 'billium.lock_plan_changed';
    case ModuleAdmissionRefused = 'billium.module_admission_refused';
    case TransitionRefused = 'billium.transition_refused';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }

    public function category(): ErrorCategory
    {
        return match ($this) {
            self::LockOrderViolation => ErrorCategory::Internal,
            self::LockPlanChanged, self::TransitionRefused => ErrorCategory::Conflict,
            self::ModuleAdmissionRefused => ErrorCategory::Unavailable,
        };
    }

    public function defaultStatus(): int
    {
        return match ($this) {
            self::LockOrderViolation => 500,
            self::LockPlanChanged, self::TransitionRefused => 409,
            self::ModuleAdmissionRefused => 503,
        };
    }

    public function isRetryable(): bool
    {
        return $this === self::LockPlanChanged;
    }

    public function title(): string
    {
        return match ($this) {
            self::LockOrderViolation => 'Lock acquisition violated the global lock order.',
            self::LockPlanChanged => 'Locked row set changed after pre-read; retry root operation.',
            self::ModuleAdmissionRefused => 'Module admission refused.',
            self::TransitionRefused => 'Session or module transition refused.',
        };
    }

    /** @return list<string> */
    public function detailKeys(): array
    {
        return match ($this) {
            self::LockOrderViolation, self::LockPlanChanged => [],
            self::ModuleAdmissionRefused => ['module_id', 'reason'],
            self::TransitionRefused => ['reason', 'blockers'],
        };
    }
}

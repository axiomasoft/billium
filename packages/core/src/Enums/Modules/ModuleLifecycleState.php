<?php

declare(strict_types=1);

namespace Billium\Core\Enums\Modules;

use Packman\Attributes\Api;

/**
 * accepting → draining → recovery_only → retired (D55). The step list is operation-class based, never a Boolean:
 * stop-new-intents keeps pending/unknown/lookup/callback/refund/history readers alive.
 */
#[Api(since: '1.0')]
enum ModuleLifecycleState: string
{
    case Accepting = 'accepting';
    case Draining = 'draining';
    case RecoveryOnly = 'recovery_only';
    case Retired = 'retired';

    /** Whether this state serves the operation class at all; a descriptor may narrow it further. */
    public function permits(ModuleOperationClass $class): bool
    {
        return match ($this) {
            self::Accepting => true,
            self::Draining => $class !== ModuleOperationClass::NewIntent,
            self::RecoveryOnly => in_array($class, [
                ModuleOperationClass::ReceiptReplay,
                ModuleOperationClass::HistoricalRead,
                ModuleOperationClass::UnknownResolution,
                ModuleOperationClass::CallbackCustody,
                ModuleOperationClass::ObservedFactRecording,
            ], true),
            self::Retired => in_array($class, [
                ModuleOperationClass::ReceiptReplay,
                ModuleOperationClass::HistoricalRead,
            ], true),
        };
    }

    /** Forward steps one at a time; draining may resume to accepting. Retired is terminal. */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Accepting => $target === self::Draining,
            self::Draining => $target === self::RecoveryOnly || $target === self::Accepting,
            self::RecoveryOnly => $target === self::Retired,
            self::Retired => false,
        };
    }
}

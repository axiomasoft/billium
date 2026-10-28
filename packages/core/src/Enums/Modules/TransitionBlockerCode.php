<?php

declare(strict_types=1);

namespace Billium\Core\Enums\Modules;

use Billium\Contracts\Attributes\Api;

/** Reasons a disable/retire/uninstall preview refuses. */
#[Api]
enum TransitionBlockerCode: string
{
    case PendingOperations = 'pending_operations';
    case UnknownEffects = 'unknown_effects';
    case FeedGaps = 'feed_gaps';
    case UnexportedFacts = 'unexported_facts';
    case OutboxInboxGaps = 'outbox_inbox_gaps';
    case HistoricalReaderMissing = 'historical_reader_missing';
    case DependentModuleActive = 'dependent_module_active';
    case ActiveBindings = 'active_bindings';
    case SecretRecoveryDependency = 'secret_recovery_dependency';
    case RetentionFloor = 'retention_floor';
    case TransitionInvalid = 'transition_invalid';
    case ModuleUnknown = 'module_unknown';
    case HostObservationsActive = 'host_observations_active';
    case ModuleNotRetired = 'module_not_retired';
    case ReadBundleMissing = 'read_bundle_missing';
}

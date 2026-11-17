<?php

declare(strict_types=1);

namespace Billium\Core\Enums\Modules;

use Packman\Attributes\Api;

/**
 * Doctor finding identities of boot/composition validation (spec/modules.json findingCodes).
 * A finding keeps effect-capable boot closed; liveness may stay green, readiness is false.
 */
#[Api(since: '1.0')]
enum CompatibilityFindingCode: string
{
    case DuplicateId = 'module.duplicate_id';
    case Unknown = 'module.unknown';
    case DependencyCycle = 'module.dependency_cycle';
    case DependencyMissing = 'module.dependency_missing';
    case DependencyRange = 'module.dependency_range';
    case PortUnknown = 'module.port_unknown';
    case PortMissing = 'module.port_missing';
    case PortForbidden = 'module.port_forbidden';
    case CoreApiUnsupported = 'module.core_api_unsupported';
    case SchemaReadUnsupported = 'module.schema_read_unsupported';
    case SchemaWriteUnsupported = 'module.schema_write_unsupported';
    case MigrationUnapplied = 'module.migration_unapplied';
    case CompositionUnknown = 'module.composition_unknown';
    case CompositionForbidden = 'module.composition_forbidden';
    case CompositionRequired = 'module.composition_required';
    case HistoricalReaderMissing = 'module.historical_reader_missing';
    case OwnershipConflict = 'module.ownership_conflict';
    case EventContractConflict = 'module.event_contract_conflict';
}

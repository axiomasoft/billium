<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Core\Enums\Modules\ModuleLifecycleState;
use Packman\Attributes\Api;

/**
 * Persisted lifecycle state of one module in one installation (billium_module_state, owned by P3.3).
 * Only a value read inside the root Tx, after the idempotency claim and the installation lock, may gate money.
 */
#[Api(since: '1.0')]
final readonly class ModuleState
{
    public function __construct(
        public string $installationRef,
        public string $moduleId,
        public ModuleLifecycleState $state,
        public int $configRevision,
        public int $schemaVersion,
        public ?string $lastTransitionRef = null,
        // Incremented by every applied transition; a context binds it, and dispatch re-checks it (D55).
        public int $generation = 1,
    ) {}
}

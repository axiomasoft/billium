<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Modules;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Core\Data\Modules\ModuleState;

/**
 * Implemented by P3.3 over billium_module_state. The implementation must read inside the caller's root Tx after the
 * idempotency claim and the installation lock, so disable against an accepted command has a serializable boundary.
 * Long-running workers re-read generation/fence before every dispatch.
 */
#[Api]
#[Spi(SpiKind::Implement)]
interface ModuleStateReader
{
    /** Null when the module was never installed for this installation. */
    public function read(string $installationRef, string $moduleId): ?ModuleState;
}

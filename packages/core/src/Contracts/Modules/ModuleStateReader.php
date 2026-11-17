<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Modules;

use Billium\Core\Data\Modules\ModuleState;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Implemented by P3.3 over billium_module_state. The implementation must read inside the caller's root Tx after the
 * idempotency claim and the installation lock, so disable against an accepted command has a serializable boundary.
 * Long-running workers re-read generation/fence before every dispatch.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ModuleStateReader
{
    /** Null when the module was never installed for this installation. */
    public function read(string $installationRef, string $moduleId): ?ModuleState;
}

<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Modules;

use Billium\Core\Data\Modules\ModuleAdmission;
use Billium\Core\Data\Modules\ModuleState;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Operation-class admission by lifecycle state. The verdict is pure; it is race-safe only when the ModuleState was
 * read inside the same root Tx, after the idempotency claim and the installation lock (P3.3 owns that persistence
 * and the transition mutator). A cached Boolean or a state read outside the Tx proves nothing.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface ModuleAdmissionGate
{
    public function admit(ModuleState $state, ModuleOperationClass $class): ModuleAdmission;
}

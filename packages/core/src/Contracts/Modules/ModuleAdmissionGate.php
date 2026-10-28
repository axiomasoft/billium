<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Modules;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Core\Data\Modules\ModuleAdmission;
use Billium\Core\Data\Modules\ModuleState;
use Billium\Core\Enums\Modules\ModuleOperationClass;

/**
 * Operation-class admission by lifecycle state. The verdict is pure; it is race-safe only when the ModuleState was
 * read inside the same root Tx, after the idempotency claim and the installation lock (P3.3 owns that persistence
 * and the transition mutator). A cached Boolean or a state read outside the Tx proves nothing.
 */
#[Api]
#[Spi(SpiKind::Call)]
interface ModuleAdmissionGate
{
    public function admit(ModuleState $state, ModuleOperationClass $class): ModuleAdmission;
}

<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Core\Data\Modules\ModuleDescriptor;
use Billium\Platform\Data\EventContracts\EventContractRegistrationPlan;

/**
 * Platform port through which a module declares its public event contracts (D63/F10). Modules only call it: they
 * never ALTER platform constraints or INSERT registry rows themselves. The implementation, the registry tables and
 * the command-lane migrations belong to P3.4; internal commands are declared separately and never registered here.
 */
#[Api]
#[Spi(SpiKind::Call)]
interface EventContractRegistrar
{
    /** Validates owner/API/schema ownership against the registry and returns the plan; writes nothing. */
    public function plan(ModuleDescriptor $descriptor, bool $enabled): EventContractRegistrationPlan;

    /**
     * Applies a conflict-free plan inside the explicit host install/upgrade command. Never called by HTTP or worker
     * boot; throws on a plan with conflicts.
     */
    public function register(EventContractRegistrationPlan $plan): void;
}

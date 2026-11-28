<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\EventContracts;

use Billium\Core\Data\Modules\ModuleDescriptor;
use Billium\Platform\Data\EventContracts\EventContractRegistrationPlan;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Platform port through which a module declares its public event contracts. Modules only call it: they
 * never ALTER platform constraints or INSERT registry rows themselves. The implementation, the registry tables and
 * internal commands are declared separately and never registered here.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
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

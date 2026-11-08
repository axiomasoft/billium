<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Modules;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\Modules\ModuleState;
use Billium\Core\Data\Modules\ModuleTransitionFacts;

/** Owning-module evidence, resolved only while the transition holds its sorted exclusive module barrier. */
#[Api]
#[Spi(SpiKind::Call)]
interface ModuleTransitionEvidence
{
    /**
     * Read or validate every owning-module fact in this exact root transaction, for this installation and generation.
     * A reference or a cached pre-barrier snapshot alone is insufficient. Return null when freshness, completeness,
     * or ownership cannot be established. Owners must hold module admission for writes that change these facts.
     */
    public function read(TransactionContext $context, ModuleState $current, string $evidenceRef): ?ModuleTransitionFacts;
}

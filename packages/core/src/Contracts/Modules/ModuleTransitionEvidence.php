<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Modules;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Modules\ModuleState;
use Billium\Core\Data\Modules\ModuleTransitionFacts;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Owning-module evidence, resolved only while the transition holds its sorted exclusive module barrier. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface ModuleTransitionEvidence
{
    /**
     * Read or validate every owning-module fact in this exact root transaction, for this installation and generation.
     * A reference or a cached pre-barrier snapshot alone is insufficient. Return null when freshness, completeness,
     * or ownership cannot be established. Owners must hold module admission for writes that change these facts.
     */
    public function read(TransactionContext $context, ModuleState $current, string $evidenceRef): ?ModuleTransitionFacts;
}

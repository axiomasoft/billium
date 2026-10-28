<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Contracts\Attributes\Api;

/**
 * Counted facts of an installation that a transition/uninstall preview weighs. The owning module (P3.3 persistence
 * and later phases) fills them; the preview rules in core only interpret them.
 */
#[Api]
final readonly class ModuleTransitionFacts
{
    /**
     * @param  list<string>  $missingHistoricalReaders  readers (DTO/schema/binding/driver) absent for existing history
     * @param  list<string>  $activeDependents  modules still short of the target state that depend on this one
     */
    public function __construct(
        public int $pendingOperations = 0,
        public int $unknownEffects = 0,
        public int $feedGaps = 0,
        public int $unexportedFacts = 0,
        public int $outboxInboxGaps = 0,
        public int $activeBindings = 0,
        public int $retentionBlockers = 0,
        public int $secretRecoveryDependencies = 0,
        public array $missingHistoricalReaders = [],
        public array $activeDependents = [],
        public bool $hostAcceptsObservations = false,
        public bool $hasHistory = false,
        public bool $readBundleInstalled = false,
    ) {}
}

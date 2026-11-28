<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Modules;

use Billium\Core\Contracts\Modules\ModuleAdmissionGate;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Modules\ModuleTransitionPlanner;
use Billium\Core\Data\Modules\ModuleAdmission;
use Billium\Core\Data\Modules\ModuleBlocker;
use Billium\Core\Data\Modules\ModuleDescriptor;
use Billium\Core\Data\Modules\ModuleState;
use Billium\Core\Data\Modules\ModuleTransitionFacts;
use Billium\Core\Data\Modules\ModuleTransitionPreview;
use Billium\Core\Enums\Modules\ModuleLifecycleState;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Modules\ModuleTransitionKind;
use Billium\Core\Enums\Modules\TransitionBlockerCode as Blocker;
use Packman\Attributes\Internal;

/**
 * Operation-class admission and disable/retire/uninstall preview rules. No storage: the platform installation supplies the state and
 * the facts inside the root Tx and applies the transition. Unknown effects are never cancelled by a rule here: they
 * only ever block retirement.
 */
#[Internal]
final readonly class ModuleLifecycle implements ModuleAdmissionGate, ModuleTransitionPlanner
{
    public function __construct(private ModuleRegistry $registry) {}

    public function admit(ModuleState $state, ModuleOperationClass $class): ModuleAdmission
    {
        $descriptor = $this->registry->descriptor($state->moduleId);
        $deny = static fn (string $reason): ModuleAdmission => new ModuleAdmission($state->moduleId, $state->state, $class, false, $reason);
        if ($descriptor === null) {
            return $deny('module_unknown');
        }
        if ($state->state === ModuleLifecycleState::Accepting) {
            return new ModuleAdmission($state->moduleId, $state->state, $class, true, 'accepting');
        }
        if (! $state->state->permits($class)) {
            return $deny("{$state->state->value}_closes_{$class->value}");
        }
        if (! $descriptor->disablePolicy->keeps($class)) {
            return $deny("module_does_not_keep_{$class->value}");
        }
        $conditions = $class === ModuleOperationClass::OriginalOwnerRefund
            ? ['original_owner_only', 'refund_lock_and_capacity', 'current_rights']
            : [];

        return new ModuleAdmission($state->moduleId, $state->state, $class, true, $state->state->value, $conditions);
    }

    public function previewTransition(ModuleState $current, ModuleLifecycleState $target, ModuleTransitionFacts $facts): ModuleTransitionPreview
    {
        $descriptor = $this->registry->descriptor($current->moduleId);
        if ($descriptor === null) {
            return $this->preview($current, ModuleTransitionKind::Lifecycle, $target, [new ModuleBlocker(Blocker::ModuleUnknown, 1)], []);
        }
        $blockers = [];
        $cascade = [];
        if (! $current->state->canTransitionTo($target)) {
            $blockers[] = new ModuleBlocker(Blocker::TransitionInvalid, 1, ["{$current->state->value}->{$target->value}"]);
        } elseif ($target === ModuleLifecycleState::Retired) {
            array_push($blockers, ...$this->retirementBlockers($descriptor, $facts));
            $this->guardHost($descriptor, $facts, $blockers);
        } elseif ($target !== ModuleLifecycleState::Accepting) {
            if ($facts->activeDependents !== []) {
                if ($descriptor->disablePolicy->cascadeDependents) {
                    $cascade = $facts->activeDependents;
                } else {
                    $blockers[] = new ModuleBlocker(Blocker::DependentModuleActive, count($facts->activeDependents), $facts->activeDependents);
                }
            }
            if ($facts->missingHistoricalReaders !== []) {
                $blockers[] = new ModuleBlocker(Blocker::HistoricalReaderMissing, count($facts->missingHistoricalReaders), $facts->missingHistoricalReaders);
            }
            $this->guardHost($descriptor, $facts, $blockers);
            if ($target === ModuleLifecycleState::RecoveryOnly) {
                // Admitted frozen work still needs dispatch, which stays in draining.
                $this->count($blockers, Blocker::PendingOperations, $facts->pendingOperations);
            }
        }

        return $this->preview($current, ModuleTransitionKind::Lifecycle, $target, $blockers, $cascade);
    }

    public function previewUninstall(ModuleState $current, ModuleTransitionFacts $facts): ModuleTransitionPreview
    {
        $descriptor = $this->registry->descriptor($current->moduleId);
        if ($descriptor === null) {
            return $this->preview($current, ModuleTransitionKind::Uninstall, null, [new ModuleBlocker(Blocker::ModuleUnknown, 1)], []);
        }
        $blockers = [];
        if ($facts->hasHistory) {
            if ($current->state !== ModuleLifecycleState::Retired) {
                $blockers[] = new ModuleBlocker(Blocker::ModuleNotRetired, 1);
            }
            if ($descriptor->uninstallPolicy->readBundleRequiredWithHistory && ! $facts->readBundleInstalled) {
                $blockers[] = new ModuleBlocker(Blocker::ReadBundleMissing, 1);
            }
        }
        array_push($blockers, ...$this->retirementBlockers($descriptor, $facts));

        return $this->preview($current, ModuleTransitionKind::Uninstall, null, $blockers, []);
    }

    /** @return list<ModuleBlocker> */
    private function retirementBlockers(ModuleDescriptor $descriptor, ModuleTransitionFacts $facts): array
    {
        $blockers = [];
        $policy = $descriptor->retirementPolicy;
        $this->count($blockers, Blocker::PendingOperations, $facts->pendingOperations, $policy->blocksOn(Blocker::PendingOperations));
        $this->count($blockers, Blocker::UnknownEffects, $facts->unknownEffects, $policy->blocksOn(Blocker::UnknownEffects));
        $this->count($blockers, Blocker::FeedGaps, $facts->feedGaps, $policy->blocksOn(Blocker::FeedGaps));
        $this->count($blockers, Blocker::UnexportedFacts, $facts->unexportedFacts, $policy->blocksOn(Blocker::UnexportedFacts));
        $this->count($blockers, Blocker::OutboxInboxGaps, $facts->outboxInboxGaps, $policy->blocksOn(Blocker::OutboxInboxGaps));
        $this->count($blockers, Blocker::ActiveBindings, $facts->activeBindings, $policy->blocksOn(Blocker::ActiveBindings));
        $this->count($blockers, Blocker::SecretRecoveryDependency, $facts->secretRecoveryDependencies, $policy->blocksOn(Blocker::SecretRecoveryDependency));
        $this->count($blockers, Blocker::RetentionFloor, $facts->retentionBlockers, $policy->blocksOn(Blocker::RetentionFloor));
        if ($facts->activeDependents !== []) {
            $blockers[] = new ModuleBlocker(Blocker::DependentModuleActive, count($facts->activeDependents), $facts->activeDependents);
        }
        if ($facts->missingHistoricalReaders !== []) {
            $blockers[] = new ModuleBlocker(Blocker::HistoricalReaderMissing, count($facts->missingHistoricalReaders), $facts->missingHistoricalReaders);
        }

        return $blockers;
    }

    /** @param list<ModuleBlocker> $blockers */
    private function guardHost(ModuleDescriptor $descriptor, ModuleTransitionFacts $facts, array &$blockers): void
    {
        if ($descriptor->disablePolicy->hostObservationGuard && $facts->hostAcceptsObservations) {
            $blockers[] = new ModuleBlocker(Blocker::HostObservationsActive, 1);
        }
    }

    /** @param list<ModuleBlocker> $blockers */
    private function count(array &$blockers, Blocker $code, int $count, bool $applies = true): void
    {
        if ($applies && $count > 0) {
            $blockers[] = new ModuleBlocker($code, $count);
        }
    }

    /**
     * @param  list<ModuleBlocker>  $blockers
     * @param  list<string>  $cascade
     */
    private function preview(ModuleState $current, ModuleTransitionKind $kind, ?ModuleLifecycleState $target, array $blockers, array $cascade): ModuleTransitionPreview
    {
        $descriptor = $this->registry->descriptor($current->moduleId);
        $after = $target ?? $current->state;
        $served = [];
        foreach (ModuleOperationClass::cases() as $class) {
            if ($after->permits($class) && ($after === ModuleLifecycleState::Accepting || $descriptor?->disablePolicy->keeps($class) === true)) {
                $served[] = $class;
            }
        }

        return new ModuleTransitionPreview($current->moduleId, $kind, $current->state, $target, $blockers, $cascade, $served);
    }
}

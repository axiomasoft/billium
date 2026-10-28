<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit\Modules;

use Billium\Core\Data\Modules\ModuleBlocker;
use Billium\Core\Data\Modules\ModuleState;
use Billium\Core\Data\Modules\ModuleTransitionFacts;
use Billium\Core\Enums\Modules\ModuleLifecycleState as State;
use Billium\Core\Enums\Modules\ModuleOperationClass as Operation;
use Billium\Core\Enums\Modules\ModuleTransitionKind;
use Billium\Core\Enums\Modules\TransitionBlockerCode as Blocker;
use PHPUnit\Framework\Attributes\DataProvider;

final class ModuleLifecycleTest extends ModuleTestCase
{
    /** @return iterable<string, array{State, Operation, bool}> */
    public static function ledgerMatrix(): iterable
    {
        $matrix = [
            'accepting' => [State::Accepting, []],
            'draining' => [State::Draining, [Operation::NewIntent]],
            'recovery_only' => [State::RecoveryOnly, [Operation::NewIntent, Operation::FrozenIntentCompletion, Operation::OriginalOwnerRefund]],
            'retired' => [State::Retired, [
                Operation::NewIntent, Operation::FrozenIntentCompletion, Operation::OriginalOwnerRefund,
                Operation::UnknownResolution, Operation::CallbackCustody, Operation::ObservedFactRecording,
            ]],
        ];
        foreach ($matrix as $name => [$state, $denied]) {
            foreach (Operation::cases() as $operation) {
                yield "{$name}/{$operation->value}" => [$state, $operation, ! in_array($operation, $denied, true)];
            }
        }
    }

    #[DataProvider('ledgerMatrix')]
    public function test_admission_is_decided_per_operation_class(State $state, Operation $operation, bool $allowed): void
    {
        $admission = self::lifecycle()->admit(self::state('ledger', $state), $operation);

        self::assertSame($allowed, $admission->allowed, $admission->reason);
    }

    public function test_draining_keeps_original_owner_refund_with_conditions_and_recovery_only_still_records_facts(): void
    {
        $lifecycle = self::lifecycle();

        $refund = $lifecycle->admit(self::state('ledger', State::Draining), Operation::OriginalOwnerRefund);
        self::assertTrue($refund->allowed);
        self::assertSame(['original_owner_only', 'refund_lock_and_capacity', 'current_rights'], $refund->conditions);

        $fact = $lifecycle->admit(self::state('execution', State::RecoveryOnly), Operation::ObservedFactRecording);
        self::assertTrue($fact->allowed, 'an observed PSP fact is recorded even though no new intent is');
        self::assertFalse($lifecycle->admit(self::state('execution', State::RecoveryOnly), Operation::NewIntent)->allowed);
        self::assertTrue($lifecycle->admit(self::state('execution', State::RecoveryOnly), Operation::UnknownResolution)->allowed, 'unknown stays resolvable by lookup');
    }

    public function test_a_module_only_serves_what_its_descriptor_keeps(): void
    {
        $lifecycle = self::lifecycle();

        self::assertTrue($lifecycle->admit(self::state('usage', State::Draining), Operation::ReceiptReplay)->allowed);
        self::assertTrue($lifecycle->admit(self::state('usage', State::Draining), Operation::HistoricalRead)->allowed);
        self::assertFalse($lifecycle->admit(self::state('usage', State::Draining), Operation::UnknownResolution)->allowed);
        self::assertFalse($lifecycle->admit(self::state('ghost', State::Accepting), Operation::HistoricalRead)->allowed);
    }

    public function test_transition_graph_is_forward_only_with_resume_from_draining(): void
    {
        $lifecycle = self::lifecycle();
        $facts = new ModuleTransitionFacts;

        $allowed = [['accepting', State::Draining], ['draining', State::RecoveryOnly], ['draining', State::Accepting], ['recovery_only', State::Retired]];
        foreach ($allowed as [$from, $to]) {
            self::assertTrue($lifecycle->previewTransition(self::state('usage', State::from($from)), $to, $facts)->allowed(), "{$from}->{$to->value}");
        }
        foreach ([['accepting', State::RecoveryOnly], ['accepting', State::Retired], ['draining', State::Retired], ['recovery_only', State::Accepting], ['retired', State::Accepting], ['retired', State::Draining]] as [$from, $to]) {
            $preview = $lifecycle->previewTransition(self::state('usage', State::from($from)), $to, $facts);
            self::assertSame([Blocker::TransitionInvalid], self::codes($preview->blockers), "{$from}->{$to->value}");
        }
    }

    public function test_disable_cascades_or_blocks_dependents_and_guards_host_observations(): void
    {
        $lifecycle = self::lifecycle();

        $execution = $lifecycle->previewTransition(self::state('execution', State::Accepting), State::Draining, new ModuleTransitionFacts(activeDependents: ['collection']));
        self::assertTrue($execution->allowed());
        self::assertSame(['collection'], $execution->cascade);

        $platform = $lifecycle->previewTransition(self::state('platform', State::Accepting), State::Draining, new ModuleTransitionFacts(activeDependents: ['ledger']));
        self::assertSame([Blocker::DependentModuleActive], self::codes($platform->blockers));

        $ledger = $lifecycle->previewTransition(self::state('ledger', State::Accepting), State::Draining, new ModuleTransitionFacts(hostAcceptsObservations: true, missingHistoricalReaders: ['payment-binding']));
        self::assertEqualsCanonicalizing([Blocker::HostObservationsActive, Blocker::HistoricalReaderMissing], self::codes($ledger->blockers));
    }

    public function test_recovery_only_waits_for_admitted_work_but_not_for_unknown_effects(): void
    {
        $lifecycle = self::lifecycle();
        $current = self::state('execution', State::Draining);

        $pending = $lifecycle->previewTransition($current, State::RecoveryOnly, new ModuleTransitionFacts(pendingOperations: 2, unknownEffects: 1));
        self::assertSame([Blocker::PendingOperations], self::codes($pending->blockers));

        self::assertTrue($lifecycle->previewTransition($current, State::RecoveryOnly, new ModuleTransitionFacts(unknownEffects: 1))->allowed());
    }

    public function test_retire_lists_every_blocker_and_unknown_is_never_cancelled(): void
    {
        $lifecycle = self::lifecycle();
        $preview = $lifecycle->previewTransition(
            self::state('execution', State::RecoveryOnly),
            State::Retired,
            new ModuleTransitionFacts(
                pendingOperations: 1,
                unknownEffects: 2,
                feedGaps: 1,
                unexportedFacts: 3,
                outboxInboxGaps: 1,
                activeBindings: 1,
                retentionBlockers: 1,
                secretRecoveryDependencies: 1,
                missingHistoricalReaders: ['driver-v1'],
                activeDependents: ['collection'],
            ),
        );

        self::assertFalse($preview->allowed());
        self::assertEqualsCanonicalizing([
            Blocker::PendingOperations, Blocker::UnknownEffects, Blocker::FeedGaps, Blocker::UnexportedFacts,
            Blocker::OutboxInboxGaps, Blocker::ActiveBindings, Blocker::RetentionFloor, Blocker::SecretRecoveryDependency,
            Blocker::DependentModuleActive, Blocker::HistoricalReaderMissing,
        ], self::codes($preview->blockers));
        self::assertFalse($preview->purgesData);

        $clean = $lifecycle->previewTransition(self::state('execution', State::RecoveryOnly), State::Retired, new ModuleTransitionFacts);
        self::assertTrue($clean->allowed());
        self::assertSame([Operation::ReceiptReplay, Operation::HistoricalRead], $clean->stillServed);
    }

    public function test_preview_reports_what_a_stopped_module_still_serves(): void
    {
        $preview = self::lifecycle()->previewTransition(self::state('ledger', State::Accepting), State::Draining, new ModuleTransitionFacts);

        self::assertSame(ModuleTransitionKind::Lifecycle, $preview->kind);
        self::assertNotContains(Operation::NewIntent, $preview->stillServed);
        foreach ([Operation::OriginalOwnerRefund, Operation::UnknownResolution, Operation::CallbackCustody, Operation::HistoricalRead] as $kept) {
            self::assertContains($kept, $preview->stillServed);
        }
    }

    public function test_uninstall_with_history_needs_a_retired_module_and_a_read_bundle(): void
    {
        $lifecycle = self::lifecycle();

        $refused = $lifecycle->previewUninstall(self::state('ledger', State::Draining), new ModuleTransitionFacts(hasHistory: true));
        self::assertEqualsCanonicalizing([Blocker::ModuleNotRetired, Blocker::ReadBundleMissing], self::codes($refused->blockers));
        self::assertSame(ModuleTransitionKind::Uninstall, $refused->kind);

        $unbundled = $lifecycle->previewUninstall(self::state('ledger', State::Retired), new ModuleTransitionFacts(hasHistory: true));
        self::assertSame([Blocker::ReadBundleMissing], self::codes($unbundled->blockers));

        $safe = $lifecycle->previewUninstall(self::state('ledger', State::Retired), new ModuleTransitionFacts(hasHistory: true, readBundleInstalled: true));
        self::assertTrue($safe->allowed());
        self::assertFalse($safe->purgesData, 'binary removal is not a schema purge');
    }

    public function test_uninstall_block_list_covers_pending_intents_documents_exports_and_retention(): void
    {
        $preview = self::lifecycle()->previewUninstall(
            self::state('documents', State::Retired),
            new ModuleTransitionFacts(pendingOperations: 1, unexportedFacts: 1, retentionBlockers: 1, missingHistoricalReaders: ['legal-document-schema'], hasHistory: true, readBundleInstalled: true),
        );

        self::assertEqualsCanonicalizing(
            [Blocker::PendingOperations, Blocker::UnexportedFacts, Blocker::RetentionFloor, Blocker::HistoricalReaderMissing],
            self::codes($preview->blockers),
        );
    }

    public function test_a_never_used_module_can_be_removed_without_a_bundle(): void
    {
        self::assertTrue(self::lifecycle()->previewUninstall(self::state('usage', State::Accepting), new ModuleTransitionFacts)->allowed());
    }

    public function test_unknown_module_cannot_transition(): void
    {
        $preview = self::lifecycle()->previewTransition(new ModuleState('inst_1', 'ghost', State::Accepting, 1, 0), State::Draining, new ModuleTransitionFacts);

        self::assertSame([Blocker::ModuleUnknown], self::codes($preview->blockers));
    }

    /**
     * @param  list<ModuleBlocker>  $blockers
     * @return list<Blocker>
     */
    private static function codes(array $blockers): array
    {
        return array_map(static fn (ModuleBlocker $blocker): Blocker => $blocker->code, $blockers);
    }
}

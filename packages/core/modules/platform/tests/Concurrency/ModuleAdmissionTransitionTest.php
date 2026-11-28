<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Concurrency;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Modules\ModuleTransitionEvidence;
use Billium\Core\Contracts\Modules\ModuleTransitionPlanner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Modules\ModuleManifest;
use Billium\Core\Data\Modules\ModuleTransitionFacts;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Enums\Modules\ModuleLifecycleState;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Exceptions\Modules\ModuleAdmissionRefused;
use Billium\Core\Internal\Modules\ModuleCatalog;
use Billium\Core\Internal\Modules\ModuleLifecycle;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Data\Installation\ModuleTransitionRequest;
use Billium\Platform\Exceptions\TransitionRefused;
use Billium\Platform\Internal\Installation\DatabaseInstallation;
use Billium\Platform\Tests\Concurrency\Scenarios\RetirementEvidenceScenario;
use Billium\Platform\Tests\Concurrency\Scenarios\RootOperationScenario;
use Billium\Platform\Tests\PlatformTestCase;
use Billium\Platform\Tests\Support\CurrentTransitionEvidence;
use LogicException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Admission holds module rows FOR SHARE at rank 25 to commit; a transition takes the affected rows FOR UPDATE
 * in module order. Disable versus command has one committed order: admitted work completes, or admission refuses
 * before any financial effect. History is append-only with a strictly increasing generation.
 */
#[Group('D55')]
#[Group('CV-R11')]
#[Group('pg')]
final class ModuleAdmissionTransitionTest extends PlatformTestCase
{
    private CurrentTransitionEvidence $evidence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
        $this->evidence = new CurrentTransitionEvidence;
        $this->application()->instance(ModuleTransitionEvidence::class, $this->evidence);
    }

    public function test_admitted_work_completes_before_a_concurrent_disable(): void
    {
        $results = ConcurrencyStand::run(RootOperationScenario::class, 2, ['workers' => [
            ['action' => 'run', 'key' => 'k-first', 'body_sleep_ms' => 1500],
            ['action' => 'transition', 'ref' => 'drain-ledger', 'module' => 'ledger', 'target' => 'draining', 'delay_ms' => 500],
        ]]);
        [$run, $transition] = [self::ok($results[0]), self::ok($results[1])];
        self::assertTrue($run['accepted'], json_encode($results, JSON_THROW_ON_ERROR));
        self::assertSame(['ledger' => 1, 'platform' => 1], self::arr($run['body'])['generations']);
        self::assertSame('draining', self::arr(self::arr($transition['transitions'])[0])['to']);
        self::assertGreaterThanOrEqual(600, $transition['elapsed_ms'], 'the transition waited for the admitted root');
        self::assertSame(1, $this->rows('billium_test_effect'));
        $this->assertNewIntentRefused('draining_closes_new_intent');
    }

    public function test_admission_waiting_behind_a_transition_refuses_before_any_effect(): void
    {
        $results = ConcurrencyStand::run(RootOperationScenario::class, 2, ['workers' => [
            ['action' => 'transition', 'ref' => 'drain-first', 'module' => 'ledger', 'target' => 'draining', 'planner_delay_ms' => 1500],
            ['action' => 'run', 'key' => 'k-second', 'delay_ms' => 500],
        ]]);
        [$transition, $run] = [self::ok($results[0]), self::ok($results[1])];
        self::assertSame('draining', self::arr(self::arr($transition['transitions'])[0])['to'], json_encode($results, JSON_THROW_ON_ERROR));
        self::assertSame(['refused' => 'draining_closes_new_intent', 'module' => 'ledger'], array_intersect_key($run, ['refused' => 1, 'module' => 1]));
        self::assertGreaterThanOrEqual(600, $run['elapsed_ms'], 'admission waited for the transition');
        self::assertSame(0, $this->rows('billium_test_effect'));
        self::assertSame(0, $this->rows('billium_money_commit_log'));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', 'command_key = ?', ['k-second']));
    }

    public function test_dependency_closure_is_enforced_for_operations_and_transitions(): void
    {
        try {
            $this->runner()->run($this->spec(modules: ['ledger']), static fn (): Receipt => self::receipt());
            self::fail('a closure without platform is incomplete');
        } catch (LogicException $exception) {
            self::assertStringContainsString('incomplete module closure', $exception->getMessage());
        }
        try {
            $this->runner()->run($this->spec(modules: ['ledger', 'platform', 'usage']), static fn (): Receipt => self::receipt());
            self::fail('a module never installed is unavailable');
        } catch (ModuleAdmissionRefused $refused) {
            self::assertSame(['usage', 'module_not_installed'], [$refused->moduleId, $refused->reason]);
        }

        $this->assertTransitionRefused(new ModuleTransitionRequest('drain-platform', 'platform', ModuleLifecycleState::Draining, 'dependency'), 'blocked', ['dependent_module_active']);
        $this->transition('drain-ledger', 'ledger', ModuleLifecycleState::Draining);
        $this->transition('drain-platform', 'platform', ModuleLifecycleState::Draining);
        $this->assertTransitionRefused(new ModuleTransitionRequest('resume-ledger', 'ledger', ModuleLifecycleState::Accepting, 'too early'), 'dependency_not_accepting', ['platform']);
        $this->transition('resume-platform', 'platform', ModuleLifecycleState::Accepting);
        $this->transition('resume-ledger', 'ledger', ModuleLifecycleState::Accepting);
        self::assertTrue($this->runner()->run($this->spec(), static fn (): Receipt => self::receipt())->accepted);
    }

    public function test_operation_classes_survive_draining_and_recovery_only(): void
    {
        $this->transition('drain', 'ledger', ModuleLifecycleState::Draining);
        $this->assertNewIntentRefused('draining_closes_new_intent');
        foreach ([ModuleOperationClass::FrozenIntentCompletion, ModuleOperationClass::OriginalOwnerRefund, ModuleOperationClass::UnknownResolution] as $class) {
            self::assertTrue($this->runner()->run($this->spec(class: $class), static fn (): Receipt => self::receipt())->accepted, $class->value);
        }

        // recovery_only needs declared evidence and no pending dispatch; elapsed time is not evidence.
        $this->assertTransitionRefused(new ModuleTransitionRequest('recover', 'ledger', ModuleLifecycleState::RecoveryOnly, 'no evidence'), 'evidence_required');
        $this->evidence->pendingOperations = 2;
        $this->assertTransitionRefused(new ModuleTransitionRequest('recover', 'ledger', ModuleLifecycleState::RecoveryOnly, 'pending', 'evidence:drain', new ModuleTransitionFacts(pendingOperations: 2)), 'blocked', ['pending_operations']);
        $this->evidence->pendingOperations = 0;
        $this->transition('recover', 'ledger', ModuleLifecycleState::RecoveryOnly, 'evidence:drained');

        foreach ([ModuleOperationClass::ObservedFactRecording, ModuleOperationClass::UnknownResolution, ModuleOperationClass::CallbackCustody] as $class) {
            $outcome = $this->runner()->run($this->spec(class: $class), static fn (TransactionContext $context): Receipt => self::receipt(['generation' => $context->moduleGeneration('ledger')]));
            self::assertSame(['generation' => 3], $outcome->body, $class->value);
        }
        foreach ([ModuleOperationClass::NewIntent, ModuleOperationClass::FrozenIntentCompletion, ModuleOperationClass::OriginalOwnerRefund] as $class) {
            try {
                $this->runner()->run($this->spec(class: $class), static fn (TransactionContext $context): Receipt => self::receipt(['effect' => self::effect($context, 'never')]));
                self::fail("{$class->value} is closed in recovery_only");
            } catch (ModuleAdmissionRefused $refused) {
                self::assertSame("recovery_only_closes_{$class->value}", $refused->reason);
            }
        }
        self::assertSame(0, $this->rows('billium_test_effect'));
    }

    public function test_receipt_replay_needs_no_new_intent_admission(): void
    {
        $original = $this->runner()->run($this->spec(key: 'k-before-drain'), static fn (TransactionContext $context): Receipt => self::receipt(['effect' => self::effect($context, 'x')]));
        $this->transition('drain', 'ledger', ModuleLifecycleState::Draining);
        $replay = $this->runner()->run($this->spec(key: 'k-before-drain'), static fn (): Receipt => self::receipt());
        self::assertTrue($replay->replayed);
        self::assertSame($original->body, $replay->body);
        // The current caller scope is still checked on replay.
        $this->callers->as('seller-b');
        try {
            $this->runner()->run($this->spec(key: 'k-before-drain', seller: 'seller-a'), static fn (): Receipt => self::receipt());
            self::fail('replay re-authorizes the caller');
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::AuthDenied, $problem->problem);
        }
    }

    public function test_retirement_rechecks_owning_evidence_after_waiting_for_admitted_recovery(): void
    {
        $this->transition('drain-for-retirement', 'ledger', ModuleLifecycleState::Draining);
        $this->transition('recover-for-retirement', 'ledger', ModuleLifecycleState::RecoveryOnly, 'evidence:drained');
        self::assertSame(0, $this->rows('billium_outbox_event'));

        $results = ConcurrencyStand::run(RetirementEvidenceScenario::class, 2, ['barrier' => random_int(1, 2_147_483_647)]);
        self::assertSame(['accepted' => true, 'retirement_waited' => true], self::ok($results[0]));
        self::assertSame(['refused' => 'blocked', 'blockers' => ['outbox_inbox_gaps']], self::ok($results[1]));
        self::assertSame(1, $this->rows('billium_outbox_event', "event_id = '6JYHEE84XV4DZPXY5HP39QSKBR'"));
        self::assertSame(['state' => 'recovery_only', 'generation' => 3, 'last_transition_ref' => 'recover-for-retirement'],
            $this->row("SELECT state, generation, last_transition_ref FROM billium_module_state WHERE module_id = 'ledger'"));
        self::assertSame(0, $this->rows('billium_module_transitions', "transition_ref = 'retire-after-recovery'"));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_key = 'retire-after-recovery'"));
    }

    public function test_an_evidence_reference_without_a_current_owner_reader_cannot_authorize_retirement(): void
    {
        $this->transition('drain-for-evidence', 'ledger', ModuleLifecycleState::Draining);
        $this->transition('recover-for-evidence', 'ledger', ModuleLifecycleState::RecoveryOnly, 'evidence:drained');
        $this->evidence->available = false;
        $this->assertTransitionRefused(new ModuleTransitionRequest('retire-unverified', 'ledger', ModuleLifecycleState::Retired, 'stale zero', 'evidence:zero'), 'evidence_unavailable');
        $this->application()->forgetInstance(ModuleTransitionEvidence::class);
        $this->assertTransitionRefused(new ModuleTransitionRequest('retire-unverified', 'ledger', ModuleLifecycleState::Retired, 'stale zero', 'evidence:zero'), 'evidence_unavailable');
        self::assertSame('recovery_only', $this->value("SELECT state FROM billium_module_state WHERE module_id = 'ledger'"));
    }

    public function test_current_clear_owner_evidence_allows_retirement_and_receipt_replay(): void
    {
        $this->transition('drain-clear', 'ledger', ModuleLifecycleState::Draining);
        $this->transition('recover-clear', 'ledger', ModuleLifecycleState::RecoveryOnly, 'evidence:drained');
        $this->transition('retire-clear', 'ledger', ModuleLifecycleState::Retired, 'evidence:clear');
        $this->application()->forgetInstance(ModuleTransitionEvidence::class);
        $replay = $this->installation()->transitionModule(new ModuleTransitionRequest('retire-clear', 'ledger', ModuleLifecycleState::Retired, 'test', 'evidence:clear'));
        self::assertTrue($replay->replayed);
        self::assertSame('retired', $this->value("SELECT state FROM billium_module_state WHERE module_id = 'ledger'"));
    }

    public function test_a_recovery_cascade_requires_current_evidence_from_every_affected_owner(): void
    {
        $this->transition('drain-child', 'ledger', ModuleLifecycleState::Draining);
        $this->transition('drain-parent', 'platform', ModuleLifecycleState::Draining);
        $json = file_get_contents(__DIR__.'/../../../../resources/modules.json');
        self::assertIsString($json);
        $manifest = self::arr(json_decode($json, true, 64, JSON_THROW_ON_ERROR));
        $modules = self::arr($manifest['modules']);
        foreach ($modules as &$module) {
            self::assertIsArray($module);
            if (($module['moduleId'] ?? null) === 'platform') {
                $policy = self::arr($module['disablePolicy']);
                $policy['dependents'] = 'cascade';
                $module['disablePolicy'] = $policy;
            }
        }
        unset($module);
        $manifest['modules'] = $modules;
        $planner = new ModuleLifecycle(new ModuleCatalog(ModuleManifest::fromArray($manifest)));
        $this->application()->instance(ModuleTransitionPlanner::class, $planner);
        $this->application()->forgetInstance(DatabaseInstallation::class);
        $this->evidence->pendingByModule = ['ledger' => 2];
        $request = new ModuleTransitionRequest('recover-cascade', 'platform', ModuleLifecycleState::RecoveryOnly, 'cascade', 'evidence:parent-clear');
        $this->assertTransitionRefused($request, 'blocked', ['pending_operations']);
        self::assertSame(['draining', 'draining'], array_column($this->table('SELECT state FROM billium_module_state ORDER BY module_id'), 'state'));
        self::assertSame(0, $this->rows('billium_module_transitions', 'transition_ref LIKE ?', ['recover-cascade%']));

        $this->evidence->pendingByModule = [];
        self::assertTrue($this->installation()->transitionModule($request)->accepted);
        self::assertSame(['recovery_only', 'recovery_only'], array_column($this->table('SELECT state FROM billium_module_state ORDER BY module_id'), 'state'));
    }

    public function test_transition_history_is_immutable_and_generations_strictly_increase(): void
    {
        $first = $this->transition('step-1', 'ledger', ModuleLifecycleState::Draining);
        $replay = $this->installation()->transitionModule(new ModuleTransitionRequest('step-1', 'ledger', ModuleLifecycleState::Draining, 'test'));
        self::assertTrue($replay->replayed);
        self::assertSame($first, $replay->body['transitions']);
        try {
            $this->installation()->transitionModule(new ModuleTransitionRequest('step-1', 'ledger', ModuleLifecycleState::Accepting, 'test'));
            self::fail('the same transition ref with another request conflicts');
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::IdempotencyConflict, $problem->problem);
        }
        $this->transition('step-2', 'ledger', ModuleLifecycleState::Accepting);

        $history = $this->table("SELECT transition_ref, from_state, to_state, from_generation, to_generation, actor_ref FROM billium_module_transitions WHERE module_id = 'ledger' ORDER BY to_generation");
        self::assertSame([null, 'accepting', 'draining'], array_column($history, 'from_state'));
        self::assertSame(['accepting', 'draining', 'accepting'], array_column($history, 'to_state'));
        self::assertSame([1, 2, 3], array_column($history, 'to_generation'));
        self::assertSame('actor-1', $history[1]['actor_ref']);
        $state = $this->row("SELECT state, generation, last_transition_ref FROM billium_module_state WHERE module_id = 'ledger'");
        self::assertSame(['state' => 'accepting', 'generation' => 3, 'last_transition_ref' => 'step-2'], $state);

        foreach ([[TestEnvironment::pdo(owner: true), '55000'], [TestEnvironment::pdo(), '42501']] as [$pdo, $code]) {
            self::assertSame($code, self::sqlState($pdo, "UPDATE billium_module_transitions SET to_state = 'retired'"));
            self::assertSame($code, self::sqlState($pdo, 'DELETE FROM billium_module_transitions'));
        }
        self::assertSame(3, $this->rows('billium_module_transitions', "module_id = 'ledger'"));
    }

    /** @return list<array<string, mixed>> */
    private function transition(string $ref, string $module, ModuleLifecycleState $target, ?string $evidence = null): array
    {
        $outcome = $this->installation()->transitionModule(new ModuleTransitionRequest($ref, $module, $target, 'test', $evidence));

        return array_values(array_map(static fn (mixed $transition): array => self::assoc((object) self::arr($transition)), self::arr($outcome->body['transitions'])));
    }

    /** @param list<string> $blockers */
    private function assertTransitionRefused(ModuleTransitionRequest $request, string $reason, array $blockers = []): void
    {
        try {
            $this->installation()->transitionModule($request);
            self::fail("transition {$request->transitionRef} must be refused");
        } catch (TransitionRefused $refused) {
            self::assertSame($reason, $refused->reason);
            self::assertSame($blockers, $refused->blockers);
        }
        self::assertSame(0, $this->rows('billium_idempotency_receipt', 'command_key = ?', [$request->transitionRef]), 'refusal keeps no claim');
    }

    private function assertNewIntentRefused(string $reason): void
    {
        try {
            $this->runner()->run($this->spec(key: 'k-new-'.$reason), static fn (TransactionContext $context): Receipt => self::receipt(['effect' => self::effect($context, 'new')]));
            self::fail('new intent must be refused');
        } catch (ModuleAdmissionRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }
}

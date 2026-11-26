<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Migration;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Modules\ModuleStateReader;
use Billium\Core\Contracts\Modules\ModuleTransitionEvidence;
use Billium\Core\Contracts\Modules\ModuleTransitionPlanner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Modules\ModuleState;
use Billium\Core\Data\Modules\ModuleTransitionFacts;
use Billium\Core\Enums\Modules\ModuleLifecycleState;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Modules\ModuleAdmissionRefused;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\PaymentDriver;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\CallbackCustodyTest as Callbacks;
use Billium\Platform\Data\Installation\ModuleTransitionRequest;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Platform\Exceptions\TransitionRefused;
use Billium\Testkit\Drivers\ResponseMode;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

#[Group('pg')]
#[Group('P9.7')]
final class ModuleRetirementDrillTest extends ExecutionTestCase
{
    public function test_mgate_09_disable_keeps_original_refund_callback_and_receipt_history(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        $scope = Fixture::scope();
        try {
            // Leave the accepted charge's provider work pending at disable.
            $charge = Callbacks::dispatched($app, $path);
            $this->transition('disable-execution', ModuleLifecycleState::Draining);
            try {
                $this->runner()->run($this->spec(kind: 'execution.drill.new', modules: ['execution', 'platform']), static fn () => self::fail('New intent crossed the disable barrier'));
            } catch (ModuleAdmissionRefused $refused) {
                self::assertSame('draining_closes_new_intent', $refused->reason);
            }
            $store = $app->make(ExecutionStore::class);
            $original = $store->operation($scope, 'root-a')?->binding;
            self::assertNotNull($original);
            self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce(Callbacks::work($charge))->disposition);
            $refund = Refunds::refund('draining-refund', 25, 2);
            $receipt = $app->make(RefundCommands::class)->admit($scope, $refund)->orThrow();
            $app->make(AttemptWorker::class)->dispatch($scope, Refunds::attemptRef('draining-refund'), 'draining-worker', new DateTimeImmutable('+1 minute'))->orThrow();
            $child = $store->operation($scope, 'draining-refund')?->binding;
            self::assertNotNull($child);
            self::assertSame([$original->rootOperationRef, $original->merchantAccountRef, $original->merchantRevisionRef],
                [$child->rootOperationRef, $child->merchantAccountRef, $child->merchantRevisionRef]);
            $this->application()->instance(ModuleTransitionEvidence::class, new RetirementFixtureEvidence);
            $this->transition('execution-recovery-only', ModuleLifecycleState::RecoveryOnly, 'no-pending-dispatch');
            $replayed = $app->make(RefundCommands::class)->admit($scope, $refund)->orThrow();
            self::assertTrue($replayed->replayed);
            self::assertSame($receipt->body, $replayed->body);
            self::assertEquals($original, $store->operation($scope, 'root-a')?->binding);
            self::assertSame(2, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    #[TestWith(['driver'], 'driver-unavailable')]
    #[TestWith(['credential'], 'credential-revoked')]
    public function test_mgate_10_lost_original_recovery_path_blocks_retirement_and_uninstall(string $failure): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        $scope = Fixture::scope();
        try {
            Fixture::seedMerchant($app);
            Fixture::host($app, $path, mode: ResponseMode::AcceptedThenTimeout);
            $wire = Fixture::wire();
            $receipt = $app->make(RootPaymentCommands::class)->admit($scope, $wire)->orThrow();
            $ref = UnknownResultTest::attemptRef();
            self::assertSame('unknown', $app->make(AttemptWorker::class)
                ->dispatch($scope, $ref, 'old-sender', new DateTimeImmutable('+1 minute'))->orThrow()->body['status']);
            $store = $app->make(ExecutionStore::class);
            $binding = $store->operation($scope, 'root-a')?->binding;
            $attempt = $store->attempt($scope, $ref);
            self::assertNotNull($binding);
            self::assertSame(AttemptState::Unknown, $attempt?->state);
            $this->transition('disable-with-unknown', ModuleLifecycleState::Draining);
            $this->application()->instance(ModuleTransitionEvidence::class, $evidence = new RetirementFixtureEvidence);
            $this->transition('recover-with-unknown', ModuleLifecycleState::RecoveryOnly, 'no-pending-dispatch');

            if ($failure === 'driver') {
                $app->instance(PaymentDrivers::class, new class implements PaymentDrivers
                {
                    public function resolve(string $driverId, string $contractVersion): PaymentDriver
                    {
                        throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Historical driver removed from fixture');
                    }

                    public function capabilities(MerchantRevision $merchant): CapabilitySnapshot
                    {
                        throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Historical capabilities removed from fixture');
                    }
                });
            } else {
                $registry = $app->make(DatabaseMerchantRegistry::class);
                $this->runner()->run($this->spec(kind: 'execution.revoke_lookup', mode: FinancialMode::Administrative,
                    class: ModuleOperationClass::HistoricalRead, modules: ['execution', 'platform']),
                    static function (TransactionContext $context) use ($registry, $scope): PlatformReceipt {
                        $registry->appendLifecycle($context, $scope, 'merchant-a', 'revoke-old-lookup', 'disable_recovery', 'lookup',
                            new DateTimeImmutable, null, 'original lookup grant revoked', 'fixture-operator');

                        return self::receipt();
                    })->orThrow();
            }
            // Wait for the scheduled lookup, then exercise the original recovery path from a new worker.
            usleep(1_050_000);
            $recover = static fn () => $app->make(AttemptWorker::class)->recover($scope, $ref, 'restarted-worker', new DateTimeImmutable('+1 minute'));
            if ($failure === 'driver') {
                try {
                    $recover();
                    self::fail('A missing historical driver allowed recovery');
                } catch (ProblemException $problem) {
                    self::assertSame(ProblemCode::CapabilityUnsupported, $problem->problem);
                }
            } else {
                self::assertSame('unknown', $recover()->orThrow()->body['status']);
            }
            self::assertSame(0, UnknownResultTest::events($path, 'provider.lookup'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            $evidence->unavailable = $failure;
            $expectedBlocker = $failure === 'driver' ? 'historical_reader_missing' : 'secret_recovery_dependency';
            try {
                $this->transition('retire-unresolved', ModuleLifecycleState::Retired, 'current-owner-inspection');
                self::fail('Retirement removed the only original recovery path');
            } catch (TransitionRefused $refused) {
                self::assertSame('blocked', $refused->reason);
                self::assertContains('unknown_effects', $refused->blockers);
                self::assertContains($expectedBlocker, $refused->blockers);
            }
            self::assertSame('recovery_only', $this->value("SELECT state FROM billium_module_state WHERE module_id = 'execution'"));
            self::assertSame(0, $this->rows('billium_module_transitions', "transition_ref = 'retire-unresolved'"));
            $current = $app->make(ModuleStateReader::class)->read(self::INSTALLATION, 'execution');
            self::assertNotNull($current);
            $facts = $evidence->lastRead;
            self::assertNotNull($facts);
            $preview = $app->make(ModuleTransitionPlanner::class)->previewUninstall($current,
                $facts);
            self::assertFalse($preview->allowed());
            self::assertFalse($preview->purgesData);
            $retained = $store->attempt($scope, $ref);
            self::assertNotNull($retained);
            self::assertSame(AttemptState::Unknown, $retained->state);
            self::assertSame($attempt->providerReference, $retained->providerReference);
            self::assertEquals($binding, $store->operation($scope, 'root-a')?->binding);
            self::assertSame($receipt->receiptId, $app->make(RootPaymentCommands::class)->admit($scope, $wire)->orThrow()->receiptId);
            self::assertSame(1, $this->rows('billium_execution_business_binding'));
            self::assertGreaterThan(0, $this->rows('billium_execution_provider_evidence'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    private function transition(string $ref, ModuleLifecycleState $state, ?string $evidence = null): void
    {
        $this->installation()->transitionModule(new ModuleTransitionRequest($ref, 'execution', $state, 'fixture lifecycle drill', $evidence))->orThrow();
    }
}

/** A fixture host reads unresolved work under the real transition barrier and reports its separately checked recovery rights. */
final class RetirementFixtureEvidence implements ModuleTransitionEvidence
{
    public ?string $unavailable = null;

    public ?ModuleTransitionFacts $lastRead = null;

    public function read(TransactionContext $context, ModuleState $current, string $evidenceRef): ?ModuleTransitionFacts
    {
        if ($current->moduleId !== 'execution' || $current->installationRef !== $context->caller()->installationRef) {
            return null;
        }
        $attempts = ModuleRetirementDrillTest::connection($context->connectionName())->table('billium_execution_attempt')
            ->where('installation_ref', $current->installationRef)
            ->where('seller_ref', $context->caller()->sellerRef)
            ->where('environment', $context->caller()->environment);
        $unresolved = (clone $attempts)->whereIn('state', ['dispatching', 'pending', 'unknown'])->count();

        return $this->lastRead = new ModuleTransitionFacts(pendingOperations: (clone $attempts)->where('state', 'admitted')->count(),
            unknownEffects: $unresolved, secretRecoveryDependencies: $this->unavailable === 'credential' ? $unresolved : 0,
            missingHistoricalReaders: $this->unavailable === 'driver' && $unresolved > 0 ? ['original-driver'] : [],
            hasHistory: $attempts->exists());
    }
}

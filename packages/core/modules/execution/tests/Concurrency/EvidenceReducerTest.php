<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Concurrency;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\IncomingCommandAuthenticator;
use Billium\Core\Contracts\Runtime\QualifiedFactualCommandPublisher;
use Billium\Core\Contracts\Runtime\ScopedCallerContext;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FactualCommandBinding;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Data\Runtime\SourceAuthorityGrant;
use Billium\Core\Internal\Runtime\PaymentObservationCommandReceiver;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\CapacityParticipants;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\AdmissionPlan;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ProviderEvidenceMode;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Internal\Merchants\ContinuityMerchantCredentials;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\CallbackCustodyTest as Inputs;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Platform\Contracts\Outbox\FactualCommandOutbox;
use Billium\Platform\Data\EventContracts\PreparedFactualCommand;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Platform\Internal\CommandOutbox\CommandOutboxRelay;
use Billium\Platform\Internal\EventContracts\DatabaseEventContractRegistrar;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\DurableJournal;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class EvidenceReducerTest extends ExecutionTestCase
{
    public function test_shared_capacity_and_stable_effect_identity_separate_each_child_actual_from_advancing_root_totals(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            $cap = SimulatedCapabilities::snapshot(evidenceMode: ProviderEvidenceMode::StableEffectId);
            Fixture::seedMerchant($app, $cap);
            Fixture::host($app, $path, cap: $cap);
            $contracts = new DatabaseEventContractRegistrar($this->owner(), $app->make(ModuleRegistry::class));
            $descriptor = $app->make(ModuleRegistry::class)->descriptor('execution');
            self::assertNotNull($descriptor);
            $contracts->register($contracts->plan($descriptor, true));
            $app->make(RootPaymentCommands::class)->admit($scope, Fixture::wire(['schema' => 'payment.authorize.v1']))->orThrow();
            $worker = $app->make(AttemptWorker::class);
            $provider = $app->make(PaymentDrivers::class)->resolve($cap->driverId, $cap->driverContractVersion);
            $worker->dispatch($scope, UnknownResultTest::attemptRef(), 'auth', new DateTimeImmutable('+1 minute'))->orThrow();
            $reducer = $app->make(EvidenceReducer::class);
            $auth = Inputs::providerInput($app);
            $result = $reducer->reduce(Inputs::work($auth));
            self::assertSame(ReductionDisposition::Applied, $result->disposition);
            $expected = [1 => ['root-a', 1, 100, 0, 0, $auth->evidenceRef, $result->observationCommandRefs[0]]];
            self::assertSame(1, $this->rows('billium_execution_authorization', "authorized_minor=100 AND state='authorized'"));
            $store = $app->make(ExecutionStore::class);
            $participants = $app->make(CapacityParticipants::class);
            $reserve = function (string $op, int $amount, EffectKind $kind) use ($scope, $store, $participants): StoredOutcome {
                $locks = $store->plan($scope, 'root-a', true);

                return $this->runner()->run($this->spec(kind: 'execution.fixture_child', modules: ['execution', 'platform']), static function (TransactionContext $ctx) use ($op, $kind, $amount, $scope, $store, $participants, $locks): PlatformReceipt {
                    $store->lockNamespace($ctx, $scope);
                    $store->lock($ctx, $locks);
                    $root = $store->operation($scope, 'root-a', $ctx);
                    self::assertNotNull($root);
                    $money = new Money($amount, Currency::of('RUB'));
                    $plan = new AdmissionPlan($scope, $root->binding, $op, $kind, $money, 'key-'.$op, 'sha256:'.hash('sha256', 'fixture-'.$op), $root->binding->canonicalizationVersion, ['execution', 'platform'], [], null);
                    $store->claimChildBinding($ctx, $plan, 'fixture-'.$op, $kind === EffectKind::Capture ? null : 'fixture', $kind === EffectKind::Refund ? 'fixture' : null);
                    $child = $store->insertOperation($ctx, $plan);
                    $participants->reserve($ctx, 'root-a', $op, $kind, $money, 'reserve-'.$op);
                    $attempt = $store->insertAttempt($ctx, $child, 'attempt-'.$op, 'provider-'.$op);
                    $store->insertDispatchWork($ctx, $attempt, 'dispatch-'.$op, 'execution.dispatch.v1');

                    return self::receipt();
                });
            };
            $capture = EffectKind::Capture;
            $refund = EffectKind::Refund;
            $reserve('capture-a', 60, $capture)->orThrow();
            $reserve('capture-b', 20, $capture)->orThrow();
            try {
                $reserve('over-capacity', 21, $capture);
                self::fail('Expected capture capacity rejection');
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::CaptureCapacityExceeded, $error->problem);
            }
            self::assertSame(1, $this->rows('billium_execution_capacity', 'held_capture_minor=80 AND confirmed_capture_minor=0'));
            foreach (['capture-a', 'capture-b'] as $child) {
                $worker->dispatch($scope, 'attempt-'.$child, 'worker-'.$child, new DateTimeImmutable('+1 minute'))->orThrow();
                $work = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint=? AND state='due'", [$child]));
                $result = $reducer->reduce($work);
                self::assertSame(ReductionDisposition::Applied, $result->disposition);
                $seq = count($expected) + 1;
                $expected[$seq] = ['root-a', $seq, 100, $child === 'capture-a' ? 60 : 80, 0, $result->evidenceRefs[0], $result->observationCommandRefs[0]];
            }
            self::assertSame(1, $this->rows('billium_execution_capacity', 'held_capture_minor=0 AND confirmed_capture_minor=80'));
            self::assertSame(2, $this->rows('billium_execution_reservation', "state='consumed'"));
            self::assertSame(2, $this->rows('billium_outbox_event', "type='payment.effect.outcome.v1'"));
            $merchant = $app->make(MerchantRegistry::class)->historical($scope, 'root-a');
            $credentials = $app->make(ContinuityMerchantCredentials::class)->resolve($merchant, 'lookup');
            $page = $provider->inventory($merchant, $credentials, new DateTimeImmutable('-1 hour'), null);
            $first = array_find($page->evidence, static fn ($e): bool => is_array($e->sanitizedPayload['material'] ?? null) && ($e->sanitizedPayload['material']['operationRef'] ?? null) === 'capture-a');
            self::assertNotNull($first);
            $app->make(CallbackCustody::class)->receive($scope, 'merchant-a', 'capture-a', $first)->orThrow();
            self::assertSame(ReductionDisposition::Duplicate, $reducer->reduce(Inputs::work($first))->disposition);
            self::assertSame(1, $this->rows('billium_execution_operation_revision', "operation_ref='capture-a'"));
            self::assertSame(3, $this->rows('billium_execution_observation_emission'));
            self::assertSame(3, $this->rows('billium_execution_provider_fact', "evidence_mode='stable_effect_id'"));
            $reserve('refund-a', 50, $refund)->orThrow();
            try {
                $reserve('over-refund', 31, $refund);
                self::fail('Expected refund capacity rejection');
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::RefundCapacityExceeded, $error->problem);
            }
            self::assertSame(1, $this->rows('billium_execution_capacity', 'held_refund_minor=50 AND confirmed_refund_minor=0'));
            $selected = new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, 'remote-billium');
            $account = new BillingAccountScope($scope->sellerRef, $scope->environment, 'billing-a');
            $ledger = $app->make(LedgerParticipants::class);
            $this->runner()->run($this->spec(financialScope: $selected), fn (TransactionContext $ctx) => $ledger->recordObligation($ctx, new RecordObligation('debt', $account, 'debt', 'basis', 'buyer', 'component', new Money(100, Currency::of('RUB')), 'sha256:'.str_repeat('f', 64))))->orThrow();
            $this->runner()->run($this->spec(financialScope: $selected), fn (TransactionContext $ctx) => $ledger->declareSettlementIntent($ctx, new DeclareSettlementIntent('declare', $account, 'intent-a', 'RUB', ['debt'])))->orThrow();
            $worker->dispatch($scope, 'attempt-refund-a', 'worker-refund', new DateTimeImmutable('+1 minute'))->orThrow();
            $work = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint='refund-a' AND state='due'"));
            $result = $reducer->reduce($work);
            self::assertSame(ReductionDisposition::Applied, $result->disposition);
            self::assertCount(2, $result->observationCommandRefs);
            $expected[4] = ['root-a', 4, 100, 80, 50, $result->evidenceRefs[0], $result->observationCommandRefs[0]];
            $expected[5] = ['refund-a', 1, 50, 50, 0, $result->evidenceRefs[0], $result->observationCommandRefs[1]];
            $this->relay($path, $selected, $expected);
            self::assertSame(1, $this->rows('billium_payment', "operation_ref='root-a' AND captured_minor=80 AND refunded_minor=50"));
            self::assertSame(5, $this->rows('billium_observed_payment_revision'));
            $reserve('capture-declined', 10, $capture)->orThrow();
            Fixture::host($app, $path, cap: $cap, mode: ResponseMode::Declined);
            $app->make(AttemptWorker::class)->dispatch($scope, 'attempt-capture-declined', 'worker-declined', new DateTimeImmutable('+1 minute'))->orThrow();
            $work = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint='capture-declined' AND state='due'"));
            $failed = $app->make(EvidenceReducer::class)->reduce($work);
            self::assertSame(ReductionDisposition::Applied, $failed->disposition);
            self::assertSame([], $failed->observationCommandRefs);
            self::assertCount(1, $failed->publicEventIds);
            self::assertSame(1, $this->rows('billium_execution_reservation', "operation_ref='capture-declined' AND state='released' AND consumed_minor=0 AND release_evidence_ref IS NOT NULL"));
            self::assertSame(1, $this->rows('billium_execution_operation', "operation_ref='root-a' AND revision=4 AND state='succeeded'"));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=80 AND confirmed_refund_minor=50 AND held_capture_minor=0 AND NOT financial_hold'));
            self::assertSame(5, $this->rows('billium_execution_observation_emission'));
            self::assertSame(5, self::integer($this->value('SELECT last_sequence FROM billium_execution_observation_partition')));
            self::assertSame(3, $this->rows('billium_outbox_event', "type='payment.effect.outcome.v1'"));
            $current = Inputs::signed($first, ['revision' => '18', 'refunded' => ['amount' => '0.50', 'currency' => 'RUB']]);
            $intake = $app->make(CallbackCustody::class);
            $intake->receive($scope, 'merchant-a', 'capture-a', $current)->orThrow();
            self::assertSame(ReductionDisposition::Duplicate, $reducer->reduce(Inputs::work($current))->disposition);
            $fork = Inputs::signed($current, ['revision' => '19', 'stableEffectId' => 'another-authenticated-external-effect']);
            $intake->receive($scope, 'merchant-a', 'capture-a', $fork)->orThrow();
            self::assertSame(ReductionDisposition::Quarantined, $reducer->reduce(Inputs::work($fork))->disposition);
            self::assertSame(1, $this->rows('billium_execution_discrepancy', "exposure->>'reason'='provider_effect_identity_changed'"));
            self::assertSame(1, $this->rows('billium_execution_provider_evidence', 'evidence_ref=?', [$fork->evidenceRef]));
            self::assertSame(4, $this->rows('billium_execution_provider_fact', "evidence_mode='stable_effect_id'"));
            self::assertSame(6, $this->rows('billium_execution_observation_emission'));
            self::assertSame(1, $this->rows('billium_execution_observation_emission', "source_sequence=6 AND operation_ref='root-a' AND payload#>>'{discrepancy,kind}'='provider_effect_identity_changed' AND payload#>>'{capturedTotal,amount}'='0.80'"));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'financial_hold AND confirmed_capture_minor=80 AND confirmed_refund_minor=50'));
            $changed = Inputs::signed($current, ['revision' => '20', 'operationAmount' => ['amount' => '0.70', 'currency' => 'RUB']]);
            $app->make(CallbackCustody::class)->receive($scope, 'merchant-a', 'capture-a', $changed)->orThrow();
            self::assertSame(ReductionDisposition::Quarantined, $reducer->reduce(Inputs::work($changed))->disposition);
            self::assertSame(1, $this->rows('billium_execution_capacity', 'financial_hold AND confirmed_capture_minor=80 AND confirmed_refund_minor=50 AND held_refund_minor=0'));
            self::assertSame(5, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
            if (is_file($path.'.origins')) {
                unlink($path.'.origins');
            }
        }
    }

    #[Group('PB-02')]
    #[Group('PF-10')]
    public function test_public_admission_provider_dispatch_verified_fact_atomic_outbox_and_qualified_real_ledger_receiver(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            $selected = new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, 'remote-billium');
            $account = new BillingAccountScope($scope->sellerRef, $scope->environment, 'billing-a');
            $ledger = $app->make(LedgerParticipants::class);
            $this->runner()->run($this->spec(financialScope: $selected), fn (TransactionContext $ctx) => $ledger->recordObligation($ctx, new RecordObligation('debt', $account,
                'debt', 'basis', 'buyer', 'component', new Money(100, Currency::of('RUB')), 'sha256:'.str_repeat('f', 64))))->orThrow();
            $this->runner()->run($this->spec(financialScope: $selected), fn (TransactionContext $ctx) => $ledger->declareSettlementIntent($ctx,
                new DeclareSettlementIntent('declare', $account, 'intent-a', 'RUB', ['debt'])))->orThrow();
            $signed = Inputs::dispatched($app, $path);
            $result = $app->make(EvidenceReducer::class)->reduce(Inputs::work($signed));
            self::assertSame(ReductionDisposition::Applied, $result->disposition);
            self::assertCount(1, $result->observationCommandRefs);
            self::assertSame(1, $this->rows('billium_execution_attempt', "state='succeeded'"));
            self::assertSame(1, $this->rows('billium_execution_dispatch_work', "state='processed' AND due_at IS NULL"));
            self::assertSame(1, $this->rows('billium_execution_provider_work', "state='processed' AND attempt_count=1"));
            self::assertSame(1, $this->rows('billium_execution_observation_emission', 'source_sequence=1 AND command_row_id IS NOT NULL'));
            self::assertSame(0, $this->rows('billium_allocation'));
            $this->relay($path, $selected, [1 => ['root-a', 1, 100, 100, 0, $signed->evidenceRef, $result->observationCommandRefs[0]]]);
            self::assertSame(1, $this->rows('billium_observed_payment_revision'));
            self::assertSame(1, $this->rows('billium_payment', 'captured_minor=100 AND refunded_minor=0 AND allocated_minor=100'));
            self::assertSame(1, $this->rows('billium_allocation'));
            self::assertSame(1, $this->rows('billium_observation_owner_binding', "intent_owner_namespace='source-a'"));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
            if (is_file($path.'.origins')) {
                unlink($path.'.origins');
            }
        }
    }

    /** @param array<int,array{string,int,int,int,int,string,string}> $expected */
    private function relay(string $path, FinancialScope $selected, array $expected): void
    {
        $app = $this->application();
        $origins = new DurableJournal($path.'.origins');
        $codec = new Codec;
        $db = $app->make(DatabaseManager::class)->connection();
        $auth = self::createStub(IncomingCommandAuthenticator::class);
        $auth->method('authenticate')->willReturnCallback(static function (string $dest, array $message) use ($origins, $selected, $db): SourceAuthorityGrant {
            self::assertSame(0, $db->transactionLevel());
            /** @var array<string,mixed> $message */
            $binding = FactualCommandBinding::digest($dest, $message, $selected);
            $grant = array_find($origins->records(), static fn (object $entry): bool => $entry instanceof SourceAuthorityGrant && $entry->commandBindingDigest === $binding);
            self::assertInstanceOf(SourceAuthorityGrant::class, $grant);
            self::assertSame('sha256:'.hash_hmac('sha256', $binding, 'simulated-independent-origin-key'), $grant->proofDigest);

            return $grant;
        });
        $app->instance(IncomingCommandAuthenticator::class, $auth);
        $app->instance(ScopedCallerContext::class, new readonly class($this->callers) implements ScopedCallerContext
        {
            public function __construct(private FixedCallerContext $callers) {}

            public function current(): Caller
            {
                return $this->callers->current();
            }

            public function run(Caller $caller, Closure $body): mixed
            {
                $previous = $this->current();
                $this->callers->set($caller);
                try {
                    return $body();
                } finally {
                    $this->callers->set($previous);
                }
            }
        });
        $publisher = $this->createMock(QualifiedFactualCommandPublisher::class);
        $publisher->expects(self::never())->method('publish');
        $publisher->expects(self::exactly(count($expected)))->method('publishQualified')->willReturnCallback(function (string $dest, array $message, FinancialScope $selectedScope) use ($origins, $selected, $db, $app, $codec, $expected): void {
            self::assertSame(0, $db->transactionLevel());
            self::assertEquals($selected, $selectedScope);
            self::assertSame(PreparedFactualCommand::DESTINATION, $dest);
            self::assertCount(6, $message);
            $o = $codec->decode($codec->canonicalize($message['payload']));
            self::assertInstanceOf(PaymentObservation::class, $o);
            $entry = $expected[$o->sourceSequence] ?? self::fail('Unexpected observation sequence');
            self::assertSame(['root-a', $entry[0], 'intent-a', 'account-a', $entry[1], 'succeeded', $entry[2], $entry[3], $entry[4], $entry[5], $entry[6]],
                [$message['businessRef'], $o->operationRef, $o->businessIntentRef, $o->providerAccountRef, $o->operationRevision, $o->state, $o->authorizedTotal->minor, $o->capturedTotal->minor, $o->refundedTotal->minor, $o->evidenceRef, $message['commandRef']]);
            $expectedLineage = 'execution-lineage:'.hash('sha256', $codec->canonicalize(['schema' => 'billium.execution.source-lineage.v1', 'installationRef' => $selected->installationRef, 'ownerSessionRef' => 'owner-1', 'deploymentGeneration' => '1', 'attachmentGeneration' => '1', 'authorityEpoch' => '1', 'localSessionEpoch' => '1']));
            self::assertSame($expectedLineage, $o->sourceLineage);
            self::assertSame('execution-charge:'.hash('sha256', $codec->canonicalize(['schema' => 'billium.execution.observation-partition.v1', 'installationRef' => $selected->installationRef, 'sellerRef' => $selected->sellerRef, 'environment' => $selected->environment, 'sourceAuthority' => 'remote-billium', 'sourceLineage' => $expectedLineage, 'rootOperationRef' => 'root-a'])), $o->partitionRef);
            /** @var array<string,mixed> $message */
            $binding = FactualCommandBinding::digest($dest, $message, $selected);
            $origins->append(new SourceAuthorityGrant($selected->installationRef, self::text($selected->sellerRef), self::text($selected->environment), 'remote-billium', $expectedLineage, $dest, 'simulated-independent-origin', new DateTimeImmutable('+5 minutes'), 'sha256:'.hash_hmac('sha256', $binding, 'simulated-independent-origin-key'), $selected->intentOwnerNamespace, $binding));
            $app->make(PaymentObservationCommandReceiver::class)->receive($dest, $message);
        });
        self::assertSame(count($expected), new CommandOutboxRelay($db, $publisher, budget: 1)->relay()->delivered);
    }

    public function test_transaction_failure_after_factual_outbox_restores_entire_material_and_work_frontier(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $event = Inputs::dispatched($app, $path);
            $partitionBefore = json_encode($this->owner()->select('SELECT id,partition_ref,last_sequence FROM billium_execution_observation_partition ORDER BY id'), JSON_THROW_ON_ERROR);
            self::assertSame(1, $this->rows('billium_execution_observation_partition', 'last_sequence=0'));
            $real = $app->make(FactualCommandOutbox::class);
            $app->instance(FactualCommandOutbox::class, new readonly class($real) implements FactualCommandOutbox
            {
                public function __construct(private FactualCommandOutbox $real) {}

                public function record(TransactionContext $context, PreparedFactualCommand $command): int
                {
                    $this->real->record($context, $command);
                    throw new LogicException('failure after real outbox write');
                }
            });
            try {
                $app->make(EvidenceReducer::class)->reduce(Inputs::work($event));
                self::fail('Expected injected fault');
            } catch (LogicException $failure) {
                self::assertSame('failure after real outbox write', $failure->getMessage());
            }
            foreach (['provider_fact', 'operation_revision', 'observation_emission'] as $table) {
                self::assertSame(0, $this->rows('billium_execution_'.$table));
            }
            self::assertSame($partitionBefore, json_encode($this->owner()->select('SELECT id,partition_ref,last_sequence FROM billium_execution_observation_partition ORDER BY id'), JSON_THROW_ON_ERROR));
            self::assertSame(0, $this->rows('billium_outbox_command'));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'version=0 AND confirmed_capture_minor=0'));
            self::assertSame(1, $this->rows('billium_execution_provider_work', "state='due' AND due_at>clock_timestamp() AND last_error_at IS NOT NULL"));
            self::assertSame(1, $this->rows('billium_execution_provider_evidence', 'evidence_ref=?', [$event->evidenceRef]));
        } finally {
            UnknownResultTest::clean($path);
        }
    }
}

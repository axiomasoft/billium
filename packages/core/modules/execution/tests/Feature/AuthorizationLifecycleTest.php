<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\CapturePayment;
use Billium\Contracts\Data\Envelopes\VoidAuthorization;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\AuthorizationCommands;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\DriverRequest;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\OriginDecision;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Data\ReductionResult;
use Billium\Execution\Enums\CaptureMode;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Internal\Authority\DispatchAuthority;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Merchants\ContinuityMerchantCredentials;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Internal\Storage\DatabaseExecutionStore;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\DispatchCancelTest;
use Billium\Execution\Tests\Concurrency\OutcomeDeliveryTest;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Platform\Internal\EventContracts\DatabaseEventContractRegistrar;
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\DeterministicWriterHost;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
final class AuthorizationLifecycleTest extends ExecutionTestCase
{
    /** @param array<string,mixed> $terms */
    public static function child(string $ref, int $amount, bool $void = false, array $terms = []): CapturePayment|VoidAuthorization
    {
        $wire = (new Codec)->decode(json_encode(array_replace([
            'schema' => $void ? VoidAuthorization::SCHEMA : CapturePayment::SCHEMA, 'commandKey' => Fixture::key(crc32($ref)),
            'installationRef' => self::INSTALLATION, 'sellerRef' => 'seller-a', 'environment' => 'test',
            'operationRef' => $ref, 'originalOperationRef' => 'root-a', 'source' => ['intentRef' => 'basis-'.$ref, 'context' => 'checkout'],
            'money' => new Money($amount, Currency::of('RUB'))->toWire(), 'requestedAt' => '2026-11-17T00:00:00Z',
        ], $void ? ['reasonCode' => 'release_unused'] : [], $terms), JSON_THROW_ON_ERROR));
        self::assertTrue($wire instanceof CapturePayment || $wire instanceof VoidAuthorization);

        return $wire;
    }

    public static function authorize(Application $app, string $path, ?CapabilitySnapshot $cap = null): ReductionResult
    {
        Fixture::seedMerchant($app, $cap);
        Fixture::host($app, $path, cap: $cap);
        $registry = $app->make(ModuleRegistry::class);
        $registrar = new DatabaseEventContractRegistrar($app->make(DatabaseManager::class)->connection('billium_owner'), $registry);
        $descriptor = $registry->descriptor('execution');
        self::assertNotNull($descriptor);
        $registrar->register($registrar->plan($descriptor, true));
        $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire(['schema' => 'payment.authorize.v1']))->orThrow();

        return self::dispatchAndReduce($app, 'root-a');
    }

    public static function dispatch(Application $app, string $ref): void
    {
        $scope = Fixture::scope();
        $attempt = PaymentIdentity::reference($scope, $ref, 'attempt');
        $app->make(AttemptWorker::class)->dispatch($scope, $attempt, 'worker-'.$ref, new DateTimeImmutable('+30 seconds'))->orThrow();
    }

    public static function dispatchAndReduce(Application $app, string $ref): ReductionResult
    {
        self::dispatch($app, $ref);
        $work = $app->make(DatabaseManager::class)->connection()->table('billium_execution_provider_work')
            ->where('operation_hint', $ref)->where('state', 'due')->value('work_ref');
        self::assertIsString($work);
        $result = $app->make(EvidenceReducer::class)->reduce($work);
        self::assertSame(ReductionDisposition::Applied, $result->disposition);

        return $result;
    }

    public static function evidence(Application $app, string $ref): ProviderEvidence
    {
        $merchant = $app->make(MerchantRegistry::class)->historical(Fixture::scope(), 'root-a');
        $driver = $app->make(PaymentDrivers::class)->resolve($merchant->driverId, $merchant->driverContractVersion);
        $page = $driver->inventory($merchant, $app->make(ContinuityMerchantCredentials::class)->resolve($merchant, 'lookup'), new DateTimeImmutable('-1 day'), null);
        $evidence = array_find($page->evidence, static fn (ProviderEvidence $e): bool => is_array($e->sanitizedPayload['material'] ?? null)
            && ($e->sanitizedPayload['material']['operationRef'] ?? null) === $ref);
        self::assertNotNull($evidence);

        return $evidence;
    }

    public static function receive(Application $app, string $ref, ProviderEvidence $evidence): ReductionResult
    {
        $app->make(CallbackCustody::class)->receive(Fixture::scope(), 'merchant-a', $ref, $evidence)->orThrow();

        return $app->make(EvidenceReducer::class)->reduce(CallbackCustodyTest::work($evidence));
    }

    /** @param Closure():mixed $command */
    public static function refuses(ProblemCode $expected, Closure $command): void
    {
        try {
            $command();
            self::fail('Expected refusal '.$expected->value);
        } catch (ProblemException $error) {
            self::assertSame($expected, $error->problem, $error->getMessage());
        }
    }

    public function test_authorization_then_capture_thirty_and_forty_allocates_only_seventy_after_real_receiver_and_reordered_callbacks(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            $ledger = $app->make(LedgerParticipants::class);
            $account = new BillingAccountScope('seller-a', 'test', 'billing-a');
            $spec = $this->spec(financialScope: new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium'));
            $this->runner()->run($spec, static fn (TransactionContext $ctx) => $ledger->recordObligation($ctx, new RecordObligation('debt', $account,
                'debt', 'basis', 'buyer', 'component', new Money(100, Currency::of('RUB')), 'sha256:'.str_repeat('f', 64))))->orThrow();
            $this->runner()->run($spec, static fn (TransactionContext $ctx) => $ledger->declareSettlementIntent($ctx, new DeclareSettlementIntent('declare', $account, 'intent-a', 'RUB', ['debt'])))->orThrow();
            $result = self::authorize($app, $path);
            $children = $app->make(AuthorizationCommands::class);
            $allocated = 0;
            foreach (['root-a' => 0, 'capture-a' => 30, 'capture-b' => 40] as $ref => $amount) {
                if ($amount > 0) {
                    $children->admit(Fixture::scope(), self::child($ref, $amount))->orThrow();
                    $result = self::dispatchAndReduce($app, $ref);
                }
                self::assertCount(1, $result->observationCommandRefs);
                $observation = OutcomeDeliveryTest::attest($app, $path, $result->observationCommandRefs[0]);
                $allocated += $amount;
                self::assertSame($allocated, $observation->capturedTotal->minor);
                self::assertSame(1, OutcomeDeliveryTest::relay($app, $path)->delivered);
                self::assertSame(1, $this->rows('billium_payment', 'operation_ref=? AND captured_minor=? AND allocated_minor=?', ['root-a', $allocated, $allocated]));
            }
            foreach (['capture-b', 'capture-a'] as $ref) {
                $event = self::evidence($app, $ref);
                self::assertSame(ReductionDisposition::Duplicate, self::receive($app, $ref, $event)->disposition);
            }
            $older = CallbackCustodyTest::signed(self::evidence($app, 'capture-a'), ['revision' => '0', 'captured' => ['amount' => '0.30', 'currency' => 'RUB']]);
            self::assertSame(ReductionDisposition::Stale, self::receive($app, 'capture-a', $older)->disposition);
            self::assertSame(1, $this->rows('billium_payment', "operation_ref='root-a' AND allocated_minor=70 AND captured_minor=70"));
            self::assertSame(2, $this->rows('billium_outbox_event', "type='payment.effect.outcome.v1'"));
            self::assertSame(3, $this->rows('billium_observed_payment_revision'));
            self::assertSame(3, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'authorized_limit_minor=100 AND confirmed_capture_minor=70 AND held_capture_minor=0'));
        } finally {
            UnknownResultTest::clean($path);
            if (is_file($path.'.origins')) {
                unlink($path.'.origins');
            }
        }
    }

    /** @return list<array{CaptureMode,bool}> */
    public static function limitedModes(): array
    {
        return [[CaptureMode::Full, false], [CaptureMode::PartialSingle, false], [CaptureMode::None, true]];
    }

    #[DataProvider('limitedModes')]
    public function test_original_capability_blocks_invalid_full_only_single_or_missing_capture_before_reservation(CaptureMode $mode, bool $withoutCapture): void
    {
        $path = Fixture::tempPath();
        $app = $this->application();
        try {
            $effects = $withoutCapture ? [EffectKind::Authorize, EffectKind::VoidAuthorization] : null;
            self::authorize($app, $path, SimulatedCapabilities::snapshot(capture: $mode, effects: $effects, partialVoid: false));
            $children = $app->make(AuthorizationCommands::class);
            $admit = static fn (string $ref, int $amount, bool $void = false) => $children->admit(Fixture::scope(), self::child($ref, $amount, $void))->orThrow();
            self::refuses(ProblemCode::CapabilityUnsupported, static fn () => $admit('partial-void', 10, true));
            if ($mode === CaptureMode::PartialSingle) {
                $admit('first', 30);
                self::refuses(ProblemCode::CapabilityUnsupported, static fn () => $admit('premature-void', 70, true));
                self::refuses(ProblemCode::AuthorizationCapacityExceeded, static fn () => $admit('reserved-void', 100, true));
                self::refuses(ProblemCode::CapabilityUnsupported, static fn () => $admit('concurrent', 40));
                self::dispatchAndReduce($app, 'first');
                self::refuses(ProblemCode::CapabilityUnsupported, static fn () => $admit('second', 40));
                $admit('release', 70, true);
                $release = self::dispatchAndReduce($app, 'release');
                self::assertSame(1, $this->rows('billium_execution_authorization',
                    'released_minor=70 AND provider_expires_at IS NULL AND expiry_evidence_ref IS NULL AND release_evidence_ref=?', [$release->evidenceRefs[0]]));
                self::assertSame(1, $this->rows('billium_execution_capacity', 'released_authorization_minor=70 AND confirmed_capture_minor=30'));
            } else {
                self::refuses(ProblemCode::CapabilityUnsupported, static fn () => $admit('wrong', 30));
                self::assertSame(1, $this->rows('billium_execution_attempt'));
                self::assertSame(0, $this->rows('billium_execution_reservation'));
                self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
                $admit('whole', 100, $withoutCapture);
                self::dispatchAndReduce($app, 'whole');
                self::assertSame(1, $this->rows('billium_execution_reservation', "state='consumed' AND consumed_minor=100"));
            }
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_unqualified_or_substituted_account_snapshot_refuses_before_admission_and_before_a_new_send(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            self::authorize($app, $path);
            $scope = Fixture::scope();
            $commands = $app->make(AuthorizationCommands::class);
            Fixture::host($app, $path, qualified: false);
            self::refuses(ProblemCode::CapabilityUnsupported, static fn () => $commands->admit($scope, self::child('no-qualification', 30))->orThrow());
            Fixture::host($app, $path, cap: SimulatedCapabilities::snapshot('other-account'));
            self::refuses(ProblemCode::CapabilityUnsupported, static fn () => $commands->admit($scope, self::child('substitution', 30, true))->orThrow());
            self::assertSame(0, $this->rows('billium_execution_reservation'));
            self::assertSame(1, $this->rows('billium_execution_attempt'));
            Fixture::host($app, $path);
            $commands->admit($scope, self::child('reserved', 30))->orThrow();
            Fixture::host($app, $path, qualified: false);
            self::refuses(ProblemCode::CapabilityUnsupported, static fn () => self::dispatch($app, 'reserved'));
            self::assertSame(1, $this->rows('billium_execution_attempt', "operation_ref='reserved' AND state='admitted' AND started_at IS NULL"));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'held_capture_minor=30 AND confirmed_capture_minor=0'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_void_replay_after_reduction_validates_original_terms_and_never_repeats_a_send(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            self::authorize($app, $path);
            $caller = $this->callers->current();
            $app->instance(CallerContext::class, new readonly class($caller, $app->make(DatabaseManager::class)) implements CallerContext
            {
                public function __construct(private Caller $caller, private DatabaseManager $db) {}

                public function current(): Caller
                {
                    if ($this->db->connection()->transactionLevel() !== 0) {
                        throw new LogicException('Financial participant called the live caller getter inside Tx');
                    }

                    return $this->caller;
                }
            });
            $app->forgetInstance(DatabaseExecutionStore::class);
            $app->forgetInstance(DatabaseMerchantRegistry::class);
            $commands = $app->make(AuthorizationCommands::class);
            $scope = Fixture::scope();
            $wire = self::child('v', 20, true, ['source' => ['context' => 'checkout', 'intentRef' => 'basis-v', 'orderRef' => 'order-a']]);
            $receipt = $commands->admit($scope, $wire)->orThrow();
            self::assertSame($receipt->receiptId, $commands->admit($scope, $wire)->orThrow()->receiptId);
            // The worker has its own authority probes. The trap above specifically guards child TxA.
            $app->instance(CallerContext::class, $this->callers);
            $app->forgetInstance(DatabaseExecutionStore::class);
            $app->forgetInstance(DatabaseMerchantRegistry::class);
            self::dispatchAndReduce($app, 'v');
            Fixture::host($app, $path, qualified: false);
            $commands = $app->make(AuthorizationCommands::class);
            $replay = $commands->admit($scope, self::child('v', 20, true, ['commandKey' => Fixture::key(88), 'source' => ['context' => 'checkout', 'intentRef' => 'basis-v', 'orderRef' => 'order-a']]))->orThrow();
            self::assertSame($receipt->body['receiptRef'], $replay->body['receiptRef']);
            foreach ([
                ['reasonCode' => 'different'],
                ['source' => ['context' => 'checkout', 'intentRef' => 'different-business']],
                ['operationRef' => 'retarget'],
                ['money' => ['amount' => '0.21', 'currency' => 'RUB']],
            ] as $changes) {
                self::refuses(ProblemCode::IdempotencyConflict, static fn () => $commands->admit($scope, self::child('v', 20, true,
                    ['commandKey' => Fixture::key(89)] + $changes))->orThrow());
            }
            self::refuses(ProblemCode::AuthDenied, static fn () => $commands->admit($scope, self::child('other', 10, false, ['source' => ['context' => 'other', 'intentRef' => 'basis-other']]))->orThrow());
            self::refuses(ProblemCode::AuthDenied, static fn () => $commands->admit($scope, self::child('other', 10, false, ['source' => ['context' => 'checkout', 'intentRef' => 'basis-other', 'orderRef' => 'foreign-order']]))->orThrow());
            self::refuses(ProblemCode::ValidationInvalid, static fn () => $commands->admit($scope, self::child('other', 10, false, ['money' => ['currency' => 'USD', 'amount' => '0.10']]))->orThrow());
            self::refuses(ProblemCode::OperationNotCancellable, static fn () => $app->make(RootPaymentCommands::class)->cancel($scope, DispatchCancelTest::command())->orThrow());
            self::assertSame(1, $this->rows('billium_execution_child_identity'));
            self::assertSame(1, $this->rows('billium_execution_reservation'));
            self::assertSame(2, $this->rows('billium_execution_attempt'));
            self::assertSame(2, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_expiry_blocks_new_admission_and_already_queued_dispatch_without_releasing_held_money(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            self::authorize($app, $path);
            $children = $app->make(AuthorizationCommands::class);
            $scope = Fixture::scope();
            $children->admit($scope, self::child('queued', 40))->orThrow();
            $auth = self::evidence($app, 'root-a');
            $expiry = CallbackCustodyTest::signed($auth, ['revision' => '3', 'authorizationExpiresAt' => '2020-01-01T00:00:00Z']);
            self::assertSame(ReductionDisposition::Applied, self::receive($app, 'root-a', $expiry)->disposition);
            self::assertSame(1, $this->rows('billium_execution_authorization', "provider_expires_at='2020-01-01T00:00:00Z' AND expiry_evidence_ref=?", [$expiry->evidenceRef]));
            self::refuses(ProblemCode::AuthorizationCapacityExceeded, static fn () => self::dispatch($app, 'queued'));
            self::refuses(ProblemCode::AuthorizationCapacityExceeded, static fn () => $children->admit($scope, self::child('new', 10, true))->orThrow());
            self::assertSame(1, $this->rows('billium_execution_capacity', 'held_capture_minor=40 AND confirmed_capture_minor=0 AND released_authorization_minor=0'));
            self::assertSame(1, $this->rows('billium_execution_child_identity'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            $altered = CallbackCustodyTest::signed($auth, ['revision' => '4', 'authorizationExpiresAt' => '2090-01-01T00:00:00Z']);
            self::assertSame(ReductionDisposition::Quarantined, self::receive($app, 'root-a', $altered)->disposition);
            self::assertSame(1, $this->rows('billium_execution_capacity', 'financial_hold AND held_capture_minor=40'));
            self::assertSame(1, $this->rows('billium_execution_authorization', 'expiry_evidence_ref=?', [$expiry->evidenceRef]));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    /** @return list<array{bool}> */
    public static function sendTimes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('sendTimes')]
    public function test_dispatch_permit_and_durable_request_horizon_end_at_the_earlier_signed_authorization_expiry(bool $queued): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            self::authorize($app, $path);
            $at = new DateTimeImmutable('+8 seconds')->format('Y-m-d\TH:i:s.uP');
            $notification = CallbackCustodyTest::signed(self::evidence($app, 'root-a'), ['revision' => '2', 'authorizationExpiresAt' => $at]);
            self::assertSame(ReductionDisposition::Applied, self::receive($app, 'root-a', $notification)->disposition);
            $app->make(AuthorizationCommands::class)->admit(Fixture::scope(), self::child('before-expiry', 50))->orThrow();
            if ($queued) {
                $clock = new FixtureClock(new DateTimeImmutable);
                $host = Fixture::host($app, $path, clock: $clock);
                $app->forgetInstance(DispatchAuthority::class);
                $app->instance(DispatchEgress::class, new readonly class($host, $clock) implements DispatchEgress
                {
                    public function __construct(private DeterministicWriterHost $host, private FixtureClock $clock) {}

                    public function invoke(DispatchTicket $ticket, DriverRequest $request, Closure $send): ProviderLookupResult
                    {
                        // This independent host queue drains after the signed authorization window but before ticket expiry.
                        $this->clock->advance(15);

                        return $this->host->invoke($ticket, $request, $send);
                    }
                });
            }
            self::dispatch($app, 'before-expiry');
            self::assertSame(1, $this->rows('billium_execution_authorization', 'provider_expires_at=?::timestamptz', [$at]));
            self::assertSame(1, $this->rows('billium_execution_attempt', "operation_ref='before-expiry' AND request_deadline_at=?::timestamptz", [$at]));
            $records = new DurableJournal($path)->records();
            $record = array_find($records, static fn (object $e): bool => $e instanceof ProviderRecord && $e->kind === EffectKind::Capture);
            if ($queued) {
                self::assertNull($record);
                self::assertSame(1, $this->rows('billium_execution_capacity', 'held_capture_minor=50 AND confirmed_capture_minor=0'));
                self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            } else {
                self::assertInstanceOf(ProviderRecord::class, $record);
                self::assertEquals(new DateTimeImmutable($at), $record->request->permit->requestDeadlineAt);
                self::assertSame(2, UnknownResultTest::events($path, 'provider.call'));
            }
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_unknown_capture_retains_full_reservation_and_missing_signed_actual_quarantines_without_publishing_requested_amount(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            self::authorize($app, $path);
            Fixture::host($app, $path, mode: ResponseMode::AcceptedThenTimeout);
            $children = $app->make(AuthorizationCommands::class);
            $scope = Fixture::scope();
            $children->admit($scope, self::child('uncertain', 80))->orThrow();
            self::dispatch($app, 'uncertain');
            self::assertSame(1, $this->rows('billium_execution_attempt', "operation_ref='uncertain' AND state='unknown'"));
            self::refuses(ProblemCode::CaptureCapacityExceeded, static fn () => $children->admit($scope, self::child('replacement', 80))->orThrow());
            $signed = self::evidence($app, 'uncertain');
            $missing = CallbackCustodyTest::signed($signed, ['revision' => '3', 'operationAmount' => null]);
            $result = self::receive($app, 'uncertain', $missing);
            self::assertSame(ReductionDisposition::Quarantined, $result->disposition);
            self::assertNotNull($result->discrepancyRef);
            self::assertSame(1, $this->rows('billium_execution_capacity', 'financial_hold AND held_capture_minor=80 AND confirmed_capture_minor=0'));
            self::assertSame(2, $this->rows('billium_execution_observation_emission'));
            self::assertSame(1, $this->rows('billium_execution_observation_emission', "source_sequence=2 AND operation_ref='root-a' AND payload#>>'{capturedTotal,amount}'='0.00' AND payload#>>'{discrepancy,kind}'='unverified_actual_amount'"));
            self::assertSame(0, $this->rows('billium_execution_provider_fact', "operation_ref='uncertain' AND normalized_snapshot->>'normalizedStatus'='succeeded'"));
            self::assertSame(1, $this->rows('billium_execution_provider_evidence', 'evidence_ref=?', [$missing->evidenceRef]));
            self::assertSame(2, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_terminal_decline_permits_explicit_replacement_and_late_old_capture_preserves_observed_overcapture_and_last_applied_totals(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            self::authorize($app, $path);
            $children = $app->make(AuthorizationCommands::class);
            $scope = Fixture::scope();
            Fixture::host($app, $path, mode: ResponseMode::Declined);
            $children->admit($scope, self::child('first', 80))->orThrow();
            self::dispatchAndReduce($app, 'first');
            self::assertSame(1, $this->rows('billium_execution_reservation', "operation_ref='first' AND state='released' AND release_evidence_ref IS NOT NULL"));
            $first = self::evidence($app, 'first');
            Fixture::host($app, $path, mode: ResponseMode::Success);
            $children->admit($scope, self::child('replacement', 80))->orThrow();
            self::dispatchAndReduce($app, 'replacement');
            $late = CallbackCustodyTest::signed($first, ['revision' => '10', 'status' => 'succeeded',
                'operationAmount' => ['amount' => '0.80', 'currency' => 'RUB'], 'captured' => ['amount' => '1.60', 'currency' => 'RUB']]);
            $result = self::receive($app, 'first', $late);
            self::assertSame(ReductionDisposition::HeldDiscrepancy, $result->disposition);
            self::assertNotNull($result->discrepancyRef);
            self::assertSame(1, $this->rows('billium_execution_capacity', 'financial_hold AND confirmed_capture_minor=80 AND held_capture_minor=0'));
            self::assertSame(1, $this->rows('billium_execution_discrepancy', "exposure->'overCaptured'->>'minor'='60' AND observed->'capturedTotal'->>'minor'='160'"));
            self::assertSame(1, $this->rows('billium_execution_reservation', "operation_ref='replacement' AND state='consumed'"));
            self::assertSame(2, $this->rows('billium_execution_operation_revision', "operation_ref='first'"));
            self::assertCount(1, $result->observationCommandRefs);
            self::assertSame(3, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_local_cancel_of_unsent_authorization_and_attempted_capture_of_a_sale_never_create_a_provider_void(): void
    {
        $path = Fixture::tempPath();
        $scope = Fixture::scope();
        $app = $this->application();
        try {
            Fixture::seedMerchant($app);
            $sale = new OriginDecision($scope, 'sale', 'sale-intent', 'checkout', 'remote-billium', 1, null, 'sha256:'.str_repeat('b', 64), 'terms-v1', null);
            Fixture::host($app, $path, [Fixture::origin(), $sale]);
            $roots = $app->make(RootPaymentCommands::class);
            $roots->admit($scope, Fixture::wire(['schema' => 'payment.authorize.v1']))->orThrow();
            $roots->cancel($scope, DispatchCancelTest::command())->orThrow();
            self::assertSame(1, $this->rows('billium_execution_attempt', "operation_ref='root-a' AND state='cancelled'"));
            self::assertSame(0, UnknownResultTest::events($path, 'provider.call'));
            $roots->admit($scope, Fixture::wire(['operationRef' => 'sale', 'source' => ['context' => 'checkout', 'intentRef' => 'sale-intent'],
                'commandKey' => Fixture::key(505)]))->orThrow();
            self::refuses(ProblemCode::ValidationInvalid, static fn () => $app->make(AuthorizationCommands::class)->admit($scope, self::child('void-sale', 100, true, ['originalOperationRef' => 'sale']))->orThrow());
            self::refuses(ProblemCode::RecoveryRequired, static fn () => $app->make(AuthorizationCommands::class)->admit($scope, self::child('void-cancelled', 100, true))->orThrow());
            self::assertSame(0, $this->rows('billium_execution_reservation'));
            self::assertSame(0, $this->rows('billium_execution_child_identity'));
            self::assertSame(0, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_a_second_installation_cannot_retarget_an_existing_capture_intent_with_a_new_root_child_and_key(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            self::authorize($app, $path);
            $app->make(AuthorizationCommands::class)->admit(Fixture::scope(), self::child('retained', 30))->orThrow();
            $scope = new ExecutionScope('capture-reinstall', 'seller-a', 'test', 'source-a');
            $this->callers->set(new Caller($scope->sellerRef, $scope->environment, $scope->installationRef, 'second-owner', ['remote-billium'], ['source-a']));
            $this->installation()->init($scope->installationRef);
            $this->authority->issueTransition('open', 'reinstall-open', ['owner-1', 1, 1, 1, 'reinstall-history']);
            $this->installation()->openSession('reinstall-open', 'owner-1', 1, 1, 1, 'reinstall-history');
            $this->authority->issueTransition('activate', 'reinstall-active', [1, 'reinstall-cert', 'reinstall-admission', 'reinstall-evidence']);
            $this->installation()->activateSession('reinstall-active', 1, 'reinstall-cert', 'reinstall-admission', 'reinstall-evidence');
            $this->tokens->set(FixedSessionAdmission::token($scope->installationRef));
            SimulatedFinancialAuthorityEvidence::admitCurrent($app);
            Fixture::seedMerchant($app, scope: $scope);
            $origin = new OriginDecision($scope, 'different-root', 'different-intent', 'checkout', 'remote-billium', 1, null,
                'sha256:'.str_repeat('a', 64), 'terms-v1', 'period-b');
            Fixture::host($app, $path, [$origin]);
            $app->make(RootPaymentCommands::class)->admit($scope, Fixture::wire(['installationRef' => $scope->installationRef, 'schema' => 'payment.authorize.v1',
                'operationRef' => $origin->operationRef, 'source' => ['context' => 'checkout', 'intentRef' => $origin->businessIntentRef]]))->orThrow();
            $wire = self::child('different-child', 30, terms: ['installationRef' => $scope->installationRef, 'originalOperationRef' => $origin->operationRef,
                'source' => ['context' => 'checkout', 'intentRef' => 'basis-retained']]);
            self::refuses(ProblemCode::IdempotencyConflict, static fn () => $app->make(AuthorizationCommands::class)->admit($scope, $wire)->orThrow());
            self::assertSame(1, $this->rows('billium_execution_child_identity'));
            self::assertSame(0, $this->rows('billium_execution_reservation', 'installation_ref=?', [$scope->installationRef]));
            self::assertSame(0, $this->rows('billium_execution_operation', "operation_ref='different-child'"));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_unavailable_historical_credentials_refuse_capture_and_void_as_recovery_before_any_child_row(): void
    {
        $path = Fixture::tempPath();
        $app = $this->application();
        try {
            self::authorize($app, $path);
            $vault = $this->createStub(MerchantCredentials::class);
            $vault->method('resolve')->willThrowException(new RuntimeException('Simulated vault unavailable'));
            $app->instance(MerchantCredentials::class, $vault);
            self::refuses(ProblemCode::RecoveryRequired, static fn () => $app->make(AuthorizationCommands::class)->admit(Fixture::scope(), self::child('capture-vault', 10))->orThrow());
            $revoked = $this->createStub(MerchantCredentials::class);
            $revoked->method('resolve')->willThrowException(new ProblemException(ProblemCode::ValidationInvalid, 'Simulated revoked permission'));
            $app->instance(MerchantCredentials::class, $revoked);
            self::refuses(ProblemCode::RecoveryRequired, static fn () => $app->make(AuthorizationCommands::class)->admit(Fixture::scope(), self::child('void-revoked', 10, true))->orThrow());
            self::assertSame(1, $this->rows('billium_execution_operation'));
            self::assertSame(0, $this->rows('billium_execution_reservation'));
            self::assertSame(0, $this->rows('billium_execution_child_identity'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }
}

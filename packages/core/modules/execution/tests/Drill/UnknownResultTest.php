<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Drill;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\PaymentDriver;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Data\AbsenceCertificate;
use Billium\Execution\Data\DispatchRequest;
use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\DriverRequest;
use Billium\Execution\Data\LookupRequest;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ProviderLookupState;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Drivers\SimulatedAbsenceVerifier;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DeterministicWriterHost;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessBarrier;
use Billium\Testkit\Fixtures\ProcessIdentity;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
#[Group('ACC-RST-02')]
#[Group('PF-03')]
final class UnknownResultTest extends ExecutionTestCase
{
    public static function attemptRef(): string
    {
        return PaymentIdentity::reference(Fixture::scope(), 'root-a', 'attempt');
    }

    public static function child(string $path, string $point): void
    {
        $app = Fixture::workerApp(800);
        $barrier = new ProcessBarrier(new DurableJournal($path.'.control'));
        $block = static function () use ($barrier, $point, $app, $path): void {
            new DurableJournal($path.'.control')->append(new JournalEvent('boundary', ['point' => $point,
                'backend' => self::integer($app->make(DatabaseManager::class)->connection()->scalar('SELECT pg_backend_pid()'))], ProcessIdentity::current()));
            $barrier->arrive('at-boundary');
            $barrier->await('resume', 1, 25_000);
        };
        $host = Fixture::host($app, $path, checkpoint: static function (string $stage) use ($point, $block): void {
            if ($point === 'after-accept' && $stage === 'after_accept') {
                $block();
            }
        });
        if ($point === 'after-txb') {
            $app->instance(DispatchEgress::class, new readonly class($host, $block) implements DispatchEgress
            {
                /** @param Closure():void $block */
                public function __construct(private DeterministicWriterHost $host, private Closure $block) {}

                public function invoke(DispatchTicket $ticket, DriverRequest $request, Closure $send): ProviderLookupResult
                {
                    ($this->block)();

                    return $this->host->invoke($ticket, $request, $send);
                }
            });
        }
        $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
        if ($point === 'after-txa') {
            $block();

            return;
        }
        $result = $app->make(AttemptWorker::class)->dispatch(Fixture::scope(), self::attemptRef(), 'original-worker', new DateTimeImmutable('+3 seconds'));
        new DurableJournal($path.'.control')->append(new JournalEvent('finish', ['status' => self::text($result->body['status'] ?? null)], ProcessIdentity::current()));
    }

    public static function start(string $path, string $point): ChildProcess
    {
        return new ChildProcess([PHP_BINARY, '-r',
            'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Drill\\UnknownResultTest::child($argv[1], $argv[2]);', $path, $point]);
    }

    public static function awaitLease(ExecutionStore $store): void
    {
        $lease = $store->attempt(Fixture::scope(), self::attemptRef())?->leaseExpiresAt;
        if ($lease !== null) {
            $sleep = (int) ceil(((float) $lease->format('U.u') - microtime(true)) * 1_000_000);
            if ($sleep > 0) {
                usleep(min($sleep + 30_000, 4_000_000));
            }
        }
    }

    public static function events(string $path, string $kind): int
    {
        return count(array_filter(new DurableJournal($path)->records(), static fn (object $row): bool => $row instanceof JournalEvent && $row->kind === $kind));
    }

    public static function clean(string $path): void
    {
        foreach ([$path, $path.'.host', $path.'.control', $path.'.cert'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /** @return list<array{string,int,int}> */
    public static function boundaries(): array
    {
        return [['after-txa', 0, 1], ['after-txb', 0, 0], ['after-accept', 1, 1]];
    }

    #[DataProvider('boundaries')]
    public function test_process_death_recovers_original_attempt_or_looks_up_without_replacement(string $point, int $before, int $after): void
    {
        $app = $this->application();
        Fixture::seedMerchant($app);
        $path = Fixture::tempPath();
        Fixture::host($app, $path);
        $child = self::start($path, $point);
        try {
            $control = new DurableJournal($path.'.control');
            new ProcessBarrier($control)->await('at-boundary', 1, 15_000);
            $boundary = array_find($control->records(), static fn (object $r): bool => $r instanceof JournalEvent && $r->kind === 'boundary');
            self::assertInstanceOf(JournalEvent::class, $boundary);
            self::assertNotEquals($this->value('SELECT pg_backend_pid()'), $boundary->data['backend']);
            self::assertSame($child->pid, $boundary->pid);
            self::assertSame($before, self::events($path, 'provider.call'));
            $operation = $this->row('SELECT operation_ref,binding_digest FROM billium_execution_operation');
            $attempt = $this->row('SELECT attempt_ref,provider_reference,request_digest FROM billium_execution_attempt');
            $child->kill();
            self::assertNotSame(0, $child->wait());
            $store = $app->make(ExecutionStore::class);
            self::awaitLease($store);
            $result = $app->make(AttemptWorker::class)->recover(Fixture::scope(), self::attemptRef(), 'recovery-worker', new DateTimeImmutable('+1 minute'))->orThrow();
            self::assertSame($point === 'after-txb' ? 'unknown' : 'pending', $result->body['status']);
            self::assertSame($operation, $this->row('SELECT operation_ref,binding_digest FROM billium_execution_operation'));
            self::assertSame($attempt, $this->row('SELECT attempt_ref,provider_reference,request_digest FROM billium_execution_attempt'));
            self::assertSame($after, self::events($path, 'provider.call'));
            self::assertSame($point === 'after-txa' ? 0 : 1, self::events($path, 'provider.lookup'));
            self::assertCount($after, array_filter(new DurableJournal($path)->records(), static fn (object $r): bool => $r instanceof ProviderRecord));
            $replay = $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire(['commandKey' => Fixture::key(99)]))->orThrow();
            self::assertSame('root-a', $replay->body['operationRef']);
            self::assertSame(1, $this->rows('billium_execution_attempt'));
            self::assertSame($after, self::events($path, 'provider.call'));
        } finally {
            self::clean($path);
        }
    }

    public function test_expired_provider_ttl_and_incomplete_lookup_never_become_positive_absence_or_a_new_create(): void
    {
        $app = $this->application();
        $cap = SimulatedCapabilities::snapshot(idempotencySeconds: 1, visibility: 'eventual', completeInventory: false, absence: false);
        $clock = new FixtureClock(new DateTimeImmutable);
        $path = Fixture::tempPath();
        try {
            Fixture::seedMerchant($app, $cap);
            Fixture::host($app, $path, cap: $cap, mode: ResponseMode::AcceptedThenTimeout, clock: $clock);
            $admitted = $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
            $worker = $app->make(AttemptWorker::class);
            self::assertSame('unknown', $worker->dispatch(Fixture::scope(), self::attemptRef(), 'original', new DateTimeImmutable('+2 seconds'))->body['status']);
            $clock->advance(2);
            usleep(1_100_000);
            self::assertSame('unknown', $worker->recover(Fixture::scope(), self::attemptRef(), 'lookup', new DateTimeImmutable('+20 seconds'))->body['status']);
            $replay = $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire(['commandKey' => Fixture::key(71)]))->orThrow();
            self::assertSame($admitted->body['receiptRef'], $replay->body['receiptRef']);
            self::assertSame(1, self::events($path, 'provider.call'));
            self::assertSame(1, self::events($path, 'provider.lookup'));
            self::assertSame(1, $this->rows('billium_execution_attempt', "state='unknown'"));
            self::assertSame(0, $this->rows('billium_execution_provider_fact'));
            self::assertSame(0, $this->rows('billium_execution_provider_evidence', "signature_verdict='verified_no_effect'"));
            self::assertSame(1, $this->rows('billium_execution_dispatch_work', "state='due'"));
        } finally {
            self::clean($path);
        }
    }

    /** @return list<array{bool}> */
    public static function foundMaterials(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('foundMaterials')]
    public function test_found_nonterminal_authorization_keeps_lookup_due_and_a_wrong_account_parks_original_work(bool $wrongAccount): void
    {
        $app = $this->application();
        $cap = SimulatedCapabilities::snapshot();
        $path = Fixture::tempPath();
        try {
            Fixture::seedMerchant($app, $cap);
            Fixture::host($app, $path, cap: $cap, mode: ResponseMode::Pending);
            $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire(['schema' => 'payment.authorize.v1']))->orThrow();
            $real = $app->make(PaymentDrivers::class)->resolve($cap->driverId, $cap->driverContractVersion);
            $driver = self::createStub(PaymentDriver::class);
            $driver->method('contractVersion')->willReturn($real->contractVersion());
            $driver->method('authorize')->willReturnCallback(static function (DriverRequest $request) use ($real, $wrongAccount): ProviderLookupResult {
                $result = $real->authorize($request);
                $material = $result->snapshot;
                $evidence = $result->evidence;
                self::assertNotNull($material);
                self::assertNotNull($evidence);
                self::assertSame('pending', $material->normalizedStatus);
                self::assertSame(EffectKind::Authorize, $material->kind);
                if ($wrongAccount) {
                    $material = new MaterialSnapshot($material->operationRef, $material->kind, 'other-account', $material->currency, $material->authorizedTotal,
                        $material->capturedTotal, $material->refundedTotal, $material->voidedTotal, $material->normalizedStatus, $material->providerRevision,
                        $material->stableEffectId, $material->materialDigest, $material->evidenceRefs, $material->observedAt);
                    $evidence = new ProviderEvidence($evidence->evidenceRef, $evidence->scope, $evidence->providerId, 'other-account', $evidence->providerEventId,
                        $evidence->payloadDigest, $evidence->signatureVerdict, $evidence->handlerContractVersion, $evidence->receivedAt, $evidence->sanitizedPayload, $evidence->securePayloadRef);
                }

                return new ProviderLookupResult(ProviderLookupState::Found, $evidence, $material, null, null, null);
            });
            $driver->method('lookup')->willReturnCallback($real->lookup(...));
            $drivers = self::createStub(PaymentDrivers::class);
            $drivers->method('capabilities')->willReturn($cap);
            $drivers->method('resolve')->willReturn($driver);
            $app->instance(PaymentDrivers::class, $drivers);
            $worker = $app->make(AttemptWorker::class);
            $result = $worker->dispatch(Fixture::scope(), self::attemptRef(), 'original', new DateTimeImmutable('+30 seconds'))->orThrow();
            self::assertSame($wrongAccount ? 'unknown' : 'pending', $result->body['status']);
            self::assertSame(1, $this->rows('billium_execution_dispatch_work', $wrongAccount ? "state='parked'" : "state='due'"));
            self::assertSame(0, $this->rows('billium_execution_provider_fact'));
            self::assertSame(0, $this->rows('billium_execution_provider_evidence', "merchant_account_ref='other-account'"));
            if (! $wrongAccount) {
                usleep(1_100_000);
                self::assertSame('pending', $worker->recover(Fixture::scope(), self::attemptRef(), 'poll', new DateTimeImmutable('+1 minute'))->orThrow()->body['status']);
            }
            self::assertSame($wrongAccount ? 0 : 1, self::events($path, 'provider.lookup'));
            self::assertSame(1, self::events($path, 'provider.call'));
            self::assertSame(1, $this->rows('billium_execution_attempt'));
        } finally {
            self::clean($path);
        }
    }

    public function test_committed_stop_new_after_credentials_and_ticket_preflight_blocks_txb_and_provider_send(): void
    {
        $app = $this->application();
        Fixture::seedMerchant($app);
        $path = Fixture::tempPath();
        try {
            $host = Fixture::host($app, $path);
            $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
            $writer = self::createStub(WriterAdmission::class);
            $writer->method('ticket')->willReturnCallback($host->ticket(...));
            $writer->method('verify')->willReturnCallback(function (DispatchTicket $ticket, DispatchRequest $request) use ($host, $app): void {
                $host->verify($ticket, $request);
                $spec = $this->spec(kind: 'execution.stop_after_preflight', mode: FinancialMode::Administrative, class: ModuleOperationClass::HistoricalRead, modules: ['platform', 'execution']);
                $this->runner()->run($spec, static function (TransactionContext $ctx) use ($app): PlatformReceipt {
                    $app->make(DatabaseMerchantRegistry::class)->appendLifecycle($ctx, Fixture::scope(), 'merchant-a', 'stop-preflight',
                        'stop_new', 'new', new DateTimeImmutable('-1 second'), null, 'merchant retirement', 'explicit-retirement-proof');

                    return self::receipt();
                })->orThrow();
            });
            $app->instance(WriterAdmission::class, $writer);
            try {
                $app->make(AttemptWorker::class)->dispatch(Fixture::scope(), self::attemptRef(), 'worker', new DateTimeImmutable('+30 seconds'))->orThrow();
                self::fail('Stopped merchant accepted cached credentials at dispatch-start');
            } catch (ProblemException $exception) {
                self::assertSame(ProblemCode::ValidationInvalid, $exception->problem);
            }
            self::assertSame(1, $this->rows('billium_execution_merchant_lifecycle_event', "action='stop_new'"));
            self::assertSame(1, $this->rows('billium_execution_attempt', "state='admitted' AND started_at IS NULL"));
            self::assertSame(0, self::events($path, 'provider.call'));
        } finally {
            self::clean($path);
        }
    }

    /** @return list<array{string}> */
    public static function certificates(): array
    {
        return [['complete'], ['forged'], ['short-window']];
    }

    #[DataProvider('certificates')]
    public function test_certified_absence_requires_independent_retirement_and_the_entire_persisted_request_window(string $case): void
    {
        $app = $this->application();
        $clock = new FixtureClock(new DateTimeImmutable);
        $cap = SimulatedCapabilities::snapshot();
        $path = Fixture::tempPath();
        try {
            Fixture::seedMerchant($app, $cap);
            $host = Fixture::host($app, $path, cap: $cap, mode: ResponseMode::Unavailable, clock: $clock);
            $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
            $app->make(AttemptWorker::class)->dispatch(Fixture::scope(), self::attemptRef(), 'original', new DateTimeImmutable('+1 second'))->orThrow();
            $ticket = array_find(new DurableJournal($path.'.host')->records(), static fn (object $entry): bool => $entry instanceof DispatchTicket);
            self::assertInstanceOf(DispatchTicket::class, $ticket);
            $store = $app->make(ExecutionStore::class);
            $operation = $store->operation(Fixture::scope(), 'root-a');
            $attempt = $store->attempt(Fixture::scope(), self::attemptRef());
            self::assertNotNull($operation);
            self::assertNotNull($attempt);
            self::assertNotNull($attempt->requestDeadlineAt);
            self::assertNotNull($attempt->startedAt);
            $host->retire($ticket->claims, 'retired-all-fixture-egress');
            usleep(1_100_000);
            $clock->now = $case === 'short-window' ? $attempt->startedAt : new DateTimeImmutable;
            $verifier = new SimulatedAbsenceVerifier(new DurableJournal($path), new DurableJournal($path.'.host'), new DurableJournal($path.'.cert'), $clock, $cap,
                static fn (): int => $app->make(DatabaseManager::class)->connection()->transactionLevel());
            $issued = $verifier->certify($operation->binding, $attempt->providerReference, $operation->createdAt->modify('-1 minute'), $clock->now);
            $certificate = $case === 'forged' ? new AbsenceCertificate($issued->certificateRef, $issued->binding, $issued->providerReference,
                $issued->providerNamespace, $issued->providerCompletenessWatermark, $issued->coverageFrom, $issued->coverageThrough,
                $issued->closedDispatchManifestDigest, $issued->retirementProofDigest, $issued->egressFenceRef, $issued->qualificationEvidenceRef,
                $issued->issuerRef, 'forged-signature', $issued->expiresAt) : $issued;
            $real = $app->make(PaymentDrivers::class)->resolve($cap->driverId, $cap->driverContractVersion);
            $lookups = self::createStub(PaymentDriver::class);
            $lookups->method('contractVersion')->willReturn($real->contractVersion());
            $lookups->method('lookup')->willReturnCallback(static function (LookupRequest $request) use ($real, $certificate): ProviderLookupResult {
                self::assertSame(ProviderLookupState::Ambiguous, $real->lookup($request)->state);

                return new ProviderLookupResult(ProviderLookupState::CertifiedAbsent, null, null, $certificate, null, null);
            });
            $drivers = self::createStub(PaymentDrivers::class);
            $drivers->method('capabilities')->willReturn($cap);
            $drivers->method('resolve')->willReturn($lookups);
            $app->instance(PaymentDrivers::class, $drivers);
            $app->instance(ProviderAbsenceVerifier::class, $verifier);
            $app->make(AttemptWorker::class)->recover(Fixture::scope(), self::attemptRef(), 'recovery', new DateTimeImmutable('+30 seconds'))->orThrow();
            self::assertSame(1, self::events($path, 'provider.call'));
            self::assertSame(1, self::events($path, 'provider.lookup'));
            self::assertSame(2, $this->rows('billium_execution_provider_evidence', "signature_verdict='local_transport'"));
            self::assertSame($case === 'complete' ? 1 : 0, $this->rows('billium_execution_provider_evidence', "signature_verdict='verified_no_effect'"));
            self::assertSame($case === 'complete' ? 1 : 0, $this->rows('billium_execution_provider_work'));
            self::assertSame(1, $this->rows('billium_execution_dispatch_work', $case === 'complete' ? "state='processed'" : "state='due'"));
            self::assertSame(0, $this->rows('billium_execution_provider_fact'));
        } finally {
            self::clean($path);
        }
    }

    public function test_accepted_then_timeout_retains_unknown_until_lookup_and_never_reinitiates(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            Fixture::seedMerchant($app);
            Fixture::host($app, $path, mode: ResponseMode::AcceptedThenTimeout);
            $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
            $worker = $app->make(AttemptWorker::class);
            $outcome = $worker->dispatch(Fixture::scope(), self::attemptRef(), 'worker', new DateTimeImmutable('+1 second'));
            self::assertSame('unknown', $outcome->body['status']);
            self::assertSame(AttemptState::Unknown, $app->make(ExecutionStore::class)->attempt(Fixture::scope(), self::attemptRef())?->state);
            self::assertSame(1, $this->rows('billium_execution_dispatch_work', "state='due'"));
            self::assertSame(1, $this->rows('billium_execution_provider_evidence', "signature_verdict='local_transport'"));
            self::assertSame(0, $this->rows('billium_execution_provider_fact'));
            usleep(1_100_000);
            self::assertSame('pending', $worker->recover(Fixture::scope(), self::attemptRef(), 'takeover', new DateTimeImmutable('+1 minute'))->body['status']);
            self::assertSame(1, self::events($path, 'provider.call'));
            self::assertSame(1, self::events($path, 'provider.lookup'));
            self::assertSame(1, $this->rows('billium_execution_provider_work'));
            self::assertSame(1, $this->rows('billium_execution_dispatch_work', "state='processed'"));
        } finally {
            self::clean($path);
        }
    }
}

<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Drill;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionReadiness;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\OriginalReplayAuthority;
use Billium\Execution\Contracts\PaymentDriver;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\QualifiedReplayDriver;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\DriverRequest;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\LookupRequest;
use Billium\Execution\Data\OriginalProviderRequest;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Data\ReplayAuthorization;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Enums\ProviderLookupState;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DeterministicWriterHost;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessIdentity;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
#[Group('PF-04')]
final class QualifiedOriginalReplayTest extends ExecutionTestCase
{
    /** @return array<string,array{string}> */
    public static function qualifications(): array
    {
        $cases = ['approved', 'lost-replay-response', 'found', 'different-bytes', 'different-key', 'different-account',
            'missing-approval', 'expired-approval', 'missing-permission', 'expired-window', 'retired-writer', 'revoked-at-egress', 'unqualified-driver', 'no-readiness', 'namespace-recovery', 'unverified-process', 'foreign-first-egress', 'financial-hold', 'archive-unavailable', 'no-host', 'missing-egress', 'revoked-approval-at-egress', 'expired-at-egress', 'no-lookup'];

        return array_combine($cases, array_map(static fn (string $case): array => [$case], $cases));
    }

    #[DataProvider('qualifications')]
    public function test_original_request_replay_requires_the_same_archived_bytes_key_account_and_independent_live_permission(string $case): void
    {
        $path = Fixture::tempPath();
        $archiveKey = sodium_crypto_secretbox_keygen();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            $cap = SimulatedCapabilities::snapshot(idempotencySeconds: $case === 'expired-window' ? 1 : 120, visibility: $case === 'no-lookup' ? 'none' : 'complete', absence: $case !== 'no-lookup');
            Fixture::seedMerchant($app, $cap);
            $writer = Fixture::host($app, $path, cap: $cap);
            $delegate = $app->make(PaymentDrivers::class)->resolve($cap->driverId, $cap->driverContractVersion);
            $this->installDriver($app, $path, $cap, $delegate, $case, $archiveKey);
            if ($case !== 'no-host') {
                $this->installAuthority($app, $path, $writer, $case);
            }
            $receipt = $app->make(RootPaymentCommands::class)->admit($scope, Fixture::wire())->orThrow();
            $worker = $app->make(AttemptWorker::class);
            $ref = UnknownResultTest::attemptRef();
            self::assertSame('unknown', $worker->dispatch($scope, $ref, 'first-worker', new DateTimeImmutable('+1 minute'))->orThrow()->body['status']);
            $originalStart = self::text($this->value('SELECT started_at::text FROM billium_execution_attempt'));
            $record = self::single($path.'.psp', 'first-request');
            if ($case !== 'missing-approval') {
                $deadline = $case === 'expired-approval' ? new DateTimeImmutable('-1 second') : new DateTimeImmutable('+1 minute');
                $approved = $record->data;
                if ($case === 'foreign-first-egress') {
                    // Even a matching operator grant cannot issue a receipt for another transport.
                    $approved['egress'] = 'unrelated-ticket';
                }
                new DurableJournal($path.'.operator')->append(new JournalEvent('approve', $approved + ['expires' => $deadline->format(DATE_ATOM), 'approval' => 'operator-7'], ProcessIdentity::current()));
            }
            $caller = $this->callers->current();
            $this->callers->set(new Caller($caller->sellerRef, $caller->environment, $caller->installationRef, $caller->actorRef,
                $caller->sourceAuthorities, $caller->intentOwnerNamespaces, $case === 'missing-permission' ? [] : ['execution.replay']));
            if (in_array($case, ['no-readiness', 'found'], true)) {
                $unready = $this->createMock(ExecutionReadiness::class);
                $unready->expects($case === 'found' ? self::never() : self::once())->method('assertReady')
                    ->willThrowException(new ProblemException(ProblemCode::RecoveryRequired, 'Simulated release dossier has expired'));
                $app->instance(ExecutionReadiness::class, $unready);
            }
            if ($case === 'missing-egress' || $case === 'found') {
                $app->forgetInstance(DispatchEgress::class);
            }
            if ($case === 'unverified-process') {
                $app->instance(FinancialAuthorityEvidence::class, self::createStub(FinancialAuthorityEvidence::class));
            }
            if ($case === 'namespace-recovery') {
                $this->runner()->run($this->spec(kind: 'execution.fixture_recovery', modules: ['execution', 'platform']), static function (TransactionContext $ctx) use ($app, $scope): PlatformReceipt {
                    $app->make(ExecutionStore::class)->transitionNamespace($ctx, $scope, NamespaceState::RecoveryRequired, 1, 'observed-unmatched-fact');

                    return new PlatformReceipt('fixture.namespace', ['recovery' => true]);
                })->orThrow();
            }
            if ($case === 'financial-hold') {
                // Seed only the durable hold bit on the unresolved root.
                self::assertSame(1, $this->owner()->table('billium_execution_capacity')->where('root_operation_ref', 'root-a')->update(['financial_hold' => true]));
            }
            if ($case === 'retired-writer') {
                $issued = array_find(new DurableJournal($path.'.host')->records(), static fn (object $row): bool => $row instanceof DispatchTicket);
                self::assertInstanceOf(DispatchTicket::class, $issued);
                $writer->retire($issued->claims, 'owner retired after original transport');
            }
            $this->owner()->selectOne('SELECT pg_sleep(greatest(0,extract(epoch FROM (due_at-clock_timestamp())))::double precision+0.03) FROM billium_execution_dispatch_work');
            $problem = null;
            $status = null;
            try {
                if ($case === 'approved') {
                    $child = new ChildProcess([PHP_BINARY, '-r', 'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Drill\\QualifiedOriginalReplayTest::coldReplay($argv[1]);', $path], [...getenv(), 'BILLIUM_PF04_ARCHIVE_KEY' => bin2hex($archiveKey)]);
                    self::assertSame(0, $child->wait());
                    $cold = self::single($path.'.operator', 'cold-result');
                    self::assertNotSame(ProcessIdentity::current(), $cold->pid);
                    self::assertNotSame(self::integer($this->value('SELECT pg_backend_pid()')), $cold->data['backend']);
                    $status = $cold->data['status'];
                    fwrite(STDOUT, PHP_EOL.json_encode(['qualifiedReplay' => ['pid' => $cold->pid, 'backend' => $cold->data['backend']]], JSON_THROW_ON_ERROR).PHP_EOL);
                } else {
                    $status = $worker->replayOriginalWithinWindow($scope, $ref, 'recovery-worker', new DateTimeImmutable('+1 minute'), 'operator-7')->orThrow()->body['status'];
                }
            } catch (ProblemException $e) {
                $problem = $e->problem;
            }
            if ($case === 'lost-replay-response') {
                self::assertSame('unknown', $status);
                $this->owner()->selectOne('SELECT pg_sleep(greatest(0,extract(epoch FROM (due_at-clock_timestamp())))::double precision+0.03) FROM billium_execution_dispatch_work');
                try {
                    $worker->replayOriginalWithinWindow($scope, $ref, 'next-worker', new DateTimeImmutable('+1 minute'), 'operator-7')->orThrow();
                    self::fail('Already consumed approval authorized a second replay transport');
                } catch (ProblemException $refusal) {
                    self::assertSame(ProblemCode::RecoveryRequired, $refusal->problem);
                }
            }
            self::assertSame($originalStart, $this->value('SELECT started_at::text FROM billium_execution_attempt'));
            self::assertSame(1, $this->rows('billium_execution_attempt'));
            self::assertSame(1, $this->rows('billium_execution_operation'));
            self::assertSame(1, $this->rows('billium_execution_dispatch_work'));
            self::assertSame(0, $this->rows('billium_execution_reservation'));
            self::assertSame($receipt->body['receiptRef'], $app->make(RootPaymentCommands::class)->admit($scope, Fixture::wire())->orThrow()->body['receiptRef']);
            self::assertSame(in_array($case, ['approved', 'lost-replay-response'], true) ? 1 : 0, UnknownResultTest::events($path.'.psp', 'replay-transport'));
            self::assertSame(in_array($case, ['approved', 'lost-replay-response'], true) ? 1 : 0, UnknownResultTest::events($path.'.operator', 'consumed'));
            $approvalChecks = match ($case) {
                'approved', 'different-bytes', 'different-key', 'foreign-first-egress', 'missing-approval', 'expired-approval',
                'revoked-at-egress', 'revoked-approval-at-egress', 'expired-at-egress', 'namespace-recovery', 'financial-hold' => 1,
                'lost-replay-response' => 2,
                default => 0,
            };
            self::assertSame($approvalChecks, UnknownResultTest::events($path.'.operator', 'approval-check'));
            self::assertSame(1, UnknownResultTest::events($path.'.psp', 'first-request'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(match ($case) {
                'missing-permission', 'unverified-process', 'no-lookup' => 0, 'lost-replay-response' => 2, default => 1
            }, UnknownResultTest::events($path.'.psp', 'lookup'));
            $effects = array_values(array_filter(new DurableJournal($path)->records(), static fn (object $r): bool => $r instanceof ProviderRecord));
            self::assertCount(1, $effects);
            self::assertInstanceOf(ProviderRecord::class, $effects[0]);
            self::assertSame(['authorized' => 100, 'captured' => 100], $effects[0]->effectAmounts);
            self::assertSame(in_array($case, ['approved', 'lost-replay-response', 'revoked-at-egress', 'revoked-approval-at-egress', 'expired-at-egress'], true) ? 1 : 0, $this->rows('billium_execution_original_replay'));
            if ($case === 'approved' || $case === 'found') {
                self::assertNull($problem);
                self::assertSame('pending', $status);
                $work = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE state='due'"));
                self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce($work)->disposition);
                self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND confirmed_refund_minor=0'));
                self::assertSame(1, $this->rows('billium_execution_operation_revision'));
                self::assertSame(1, $this->rows('billium_outbox_command'));
            } else {
                self::assertSame(0, $this->rows('billium_execution_operation_revision'));
                self::assertSame(0, $this->rows('billium_outbox_command'));
                self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=0 AND confirmed_refund_minor=0'));
                if ($case === 'financial-hold') {
                    self::assertSame(ProblemCode::OperationFinancialHold, $problem);
                    self::assertSame(1, $this->rows('billium_execution_capacity', 'financial_hold'));
                } elseif (in_array($case, ['revoked-at-egress', 'lost-replay-response', 'revoked-approval-at-egress', 'expired-at-egress'], true)) {
                    self::assertSame('unknown', $status);
                } elseif ($case === 'unverified-process') {
                    self::assertContains($problem, [ProblemCode::AuthDenied, ProblemCode::InstallationQuarantined]);
                } else {
                    $expected = in_array($case, ['missing-approval', 'missing-permission'], true) ? ProblemCode::AuthDenied : ProblemCode::RecoveryRequired;
                    self::assertSame($expected, $problem);
                }
            }
            if ($case === 'approved') {
                $transportEvents = array_values(array_filter(new DurableJournal($path.'.psp')->records(), static fn (object $r): bool => $r instanceof JournalEvent));
                self::assertSame(['first-request', 'lookup', 'replay-transport'], array_map(static fn (JournalEvent $r): string => $r->kind, $transportEvents));
                $again = self::single($path.'.psp', 'replay-transport');
                self::assertSame($record->data, $again->data);
                self::assertSame(1, $this->rows('billium_execution_original_replay', "approval_ref='operator-7' AND window_ends_at=original_started_at+interval '120 seconds' AND deadline<=window_ends_at"));
            }
        } finally {
            UnknownResultTest::clean($path);
            foreach (['.psp', '.adapter', '.operator'] as $suffix) {
                if (is_file($path.$suffix)) {
                    unlink($path.$suffix);
                }
            }
        }
    }

    public function test_refund_replay_preserves_the_original_reservation_until_ordinary_reduction(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            $scope = Fixture::scope();
            $cap = SimulatedCapabilities::snapshot(idempotencySeconds: 120);
            RefundCapacityTest::captured($app, $path, $cap);
            $writer = Fixture::host($app, $path, cap: $cap);
            $provider = $app->make(PaymentDrivers::class)->resolve($cap->driverId, $cap->driverContractVersion);
            $this->installDriver($app, $path, $cap, $provider, 'refund', sodium_crypto_secretbox_keygen());
            $this->installAuthority($app, $path, $writer, 'refund');
            $wire = RefundCapacityTest::refund('refund-30', 30, 8);
            $receipt = $app->make(RefundCommands::class)->admit($scope, $wire)->orThrow();
            $ref = RefundCapacityTest::attemptRef('refund-30');
            $worker = $app->make(AttemptWorker::class);
            self::assertSame('unknown', $worker->dispatch($scope, $ref, 'initial', new DateTimeImmutable('+1 minute'))->orThrow()->body['status']);
            $reservation = $this->row("SELECT * FROM billium_execution_reservation WHERE operation_ref='refund-30' AND state='held'");
            $grant = self::single($path.'.psp', 'first-request')->data + ['approval' => 'child-approval', 'expires' => new DateTimeImmutable('+1 minute')->format(DATE_ATOM)];
            new DurableJournal($path.'.operator')->append(new JournalEvent('approve', $grant, ProcessIdentity::current()));
            $c = $this->callers->current();
            $this->callers->set(new Caller($c->sellerRef, $c->environment, $c->installationRef, $c->actorRef, $c->sourceAuthorities, $c->intentOwnerNamespaces, ['execution.replay']));
            $this->owner()->selectOne('SELECT pg_sleep(greatest(0,extract(epoch FROM (due_at-clock_timestamp())))::double precision+0.03) FROM billium_execution_dispatch_work WHERE attempt_ref=?', [$ref]);
            self::assertSame('pending', $worker->replayOriginalWithinWindow($scope, $ref, 'recovery', new DateTimeImmutable('+1 minute'), 'child-approval')->orThrow()->body['status']);
            self::assertSame($reservation, $this->row("SELECT * FROM billium_execution_reservation WHERE operation_ref='refund-30'"));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND held_refund_minor=30 AND confirmed_refund_minor=0'));
            self::assertSame($receipt->body['receiptRef'], $app->make(RefundCommands::class)->admit($scope, $wire)->orThrow()->body['receiptRef']);
            self::assertSame(2, $this->rows('billium_execution_attempt'));
            self::assertSame(2, $this->rows('billium_execution_operation'));
            self::assertSame(1, $this->rows('billium_execution_child_identity'));
            self::assertSame(1, $this->rows('billium_execution_original_replay'));
            self::assertSame(1, $this->rows('billium_outbox_command'));
            $work = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint='refund-30' AND state='due'"));
            self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce($work)->disposition);
            self::assertSame(ReductionDisposition::Duplicate, $app->make(EvidenceReducer::class)->reduce($work)->disposition);
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND held_refund_minor=0 AND confirmed_refund_minor=30'));
            self::assertSame(1, $this->rows('billium_execution_reservation', "state='consumed' AND consumed_minor=30"));
            self::assertSame(3, $this->rows('billium_outbox_command'));
            $effects = array_values(array_filter(new DurableJournal($path)->records(), static fn (object $r): bool => $r instanceof ProviderRecord));
            self::assertCount(2, $effects);
            self::assertSame(['authorized' => 100, 'captured' => 100], $effects[0]->effectAmounts);
            self::assertSame(['refunded' => 30], $effects[1]->effectAmounts);
            self::assertSame(1, UnknownResultTest::events($path.'.psp', 'lookup'));
            self::assertSame(1, UnknownResultTest::events($path.'.psp', 'replay-transport'));
            self::assertSame(1, UnknownResultTest::events($path.'.operator', 'consumed'));
        } finally {
            UnknownResultTest::clean($path);
            foreach (['.psp', '.adapter', '.operator'] as $suffix) {
                if (is_file($path.$suffix)) {
                    unlink($path.$suffix);
                }
            }
        }
    }

    public static function coldReplay(string $path): void
    {
        $app = Fixture::workerApp(110);
        $scope = Fixture::scope();
        $cap = SimulatedCapabilities::snapshot(idempotencySeconds: 120);
        $writer = Fixture::host($app, $path, cap: $cap);
        $provider = $app->make(PaymentDrivers::class)->resolve($cap->driverId, $cap->driverContractVersion);
        $fixture = new self('test_original_request_replay_requires_the_same_archived_bytes_key_account_and_independent_live_permission');
        $archiveKey = hex2bin(self::text(getenv('BILLIUM_PF04_ARCHIVE_KEY')));
        self::assertIsString($archiveKey);
        $fixture->installDriver($app, $path, $cap, $provider, 'approved', $archiveKey);
        $fixture->installAuthority($app, $path, $writer, 'approved');
        $callers = $app->make(CallerContext::class);
        self::assertInstanceOf(FixedCallerContext::class, $callers);
        $c = $callers->current();
        self::assertInstanceOf(Caller::class, $c);
        $callers->set(new Caller($c->sellerRef, $c->environment, $c->installationRef, $c->actorRef, $c->sourceAuthorities, $c->intentOwnerNamespaces, ['execution.replay']));
        $outcome = $app->make(AttemptWorker::class)->replayOriginalWithinWindow($scope, UnknownResultTest::attemptRef(), 'cold-worker', new DateTimeImmutable('+1 minute'), 'operator-7')->orThrow();
        $db = $app->make(DatabaseManager::class)->connection();
        new DurableJournal($path.'.operator')->append(new JournalEvent('cold-result', ['backend' => self::integer($db->scalar('SELECT pg_backend_pid()')),
            'status' => self::text($outcome->body['status'])], ProcessIdentity::current()));
    }

    private function installDriver(Application $app, string $path, CapabilitySnapshot $cap, PaymentDriver $provider, string $case, string $archiveKey): void
    {
        $double = $case === 'unqualified-driver' ? self::createStub(PaymentDriver::class) : self::createStubForIntersectionOfInterfaces([PaymentDriver::class, QualifiedReplayDriver::class]);
        $db = $app->make(DatabaseManager::class)->connection();
        $double->method('contractVersion')->willReturn($provider->contractVersion());
        $double->method('normalize')->willReturnCallback($provider->normalize(...));
        $double->method($case === 'refund' ? 'refund' : 'execute')->willReturnCallback(static function (DriverRequest $request) use ($path, $provider, $db, $archiveKey, $case): ProviderLookupResult {
            self::assertSame(0, $db->transactionLevel());
            $body = 'amount='.$request->amount->minor.'&currency='.$request->amount->currency->code.'&memo=a%20b&nonce='.bin2hex(random_bytes(16));
            $key = 'qualified-key/'.bin2hex(random_bytes(16));
            $frame = ['bodySha256' => hash('sha256', $body), 'keySha256' => hash('sha256', $key), 'account' => $request->binding->merchantAccountRef,
                'provider' => 'simulated-provider', 'installation' => $request->binding->scope->installationRef, 'seller' => $request->binding->scope->sellerRef,
                'environment' => $request->binding->scope->environment, 'namespace' => $request->binding->scope->intentOwnerNamespace, 'attempt' => $request->permit->attemptRef, 'operation' => $request->permit->operationRef, 'reference' => $request->providerReference,
                'driver' => $request->binding->driverId, 'contract' => $request->binding->driverContractVersion, 'egress' => $request->permit->ticketRef];
            // The adapter captures the actual outgoing frame. The independent simulated PSP parses and journals its received frame.
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $archive = sodium_crypto_secretbox(json_encode([$body, $key], JSON_THROW_ON_ERROR), $nonce, $archiveKey);
            new DurableJournal($path.'.adapter')->append(new JournalEvent('sent', $frame + ['sealed' => base64_encode($archive), 'nonce' => base64_encode($nonce)], ProcessIdentity::current()));
            self::acceptFirstFrame($path, $body, $key, $request, $provider, $case === 'refund');

            return new ProviderLookupResult(ProviderLookupState::Unavailable, null, null, null, 'response_lost', null);
        });
        $double->method('lookup')->willReturnCallback(static function (LookupRequest $lookup) use ($path, $case, $db): ProviderLookupResult {
            self::assertSame(0, $db->transactionLevel());
            $first = self::single($path.'.psp', 'first-request');
            self::assertSame([$first->data['reference'], $first->data['account']], [$lookup->providerReference, $lookup->binding->merchantAccountRef]);
            new DurableJournal($path.'.psp')->append(new JournalEvent('lookup', ['reference' => $lookup->providerReference], ProcessIdentity::current()));

            return $case === 'found' ? self::originalResult($path) : new ProviderLookupResult(ProviderLookupState::Ambiguous, null, null, null, 'lookup_index_delayed', null);
        });
        if ($double instanceof QualifiedReplayDriver) {
            $double->method('originalRequest')->willReturnCallback(static function (FrozenBinding $binding, AttemptRecord $attempt) use ($path, $case, $archiveKey): ?OriginalProviderRequest {
                if ($case === 'archive-unavailable') {
                    return null;
                }
                $frame = self::single($path.'.adapter', 'sent')->data;
                $sealed = base64_decode(self::text($frame['sealed']), true);
                $nonce = base64_decode(self::text($frame['nonce']), true);
                self::assertIsString($sealed);
                self::assertIsString($nonce);
                $opened = sodium_crypto_secretbox_open($sealed, $nonce, $archiveKey);
                self::assertIsString($opened);
                $transport = json_decode($opened, true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($transport);
                $body = self::text($transport[0]);
                $key = self::text($transport[1]);

                return new OriginalProviderRequest($binding->scope, $attempt->operationRef, $attempt->attemptRef, self::text($frame['reference']), 'simulated-provider',
                    $case === 'different-account' ? 'different-account' : self::text($frame['account']), self::text($frame['driver']), self::text($frame['contract']),
                    $case === 'different-key' ? 'fresh-key' : $key, $case === 'different-bytes' ? str_replace('%20', '+', $body) : $body, $case === 'foreign-first-egress' ? 'unrelated-ticket' : self::text($frame['egress']));
            });
            $double->method('replayOriginalWithinWindow')->willReturnCallback(static function (OriginalProviderRequest $original, ResolvedCredentials $credentials) use ($path, $db, $case): ProviderLookupResult {
                self::assertSame(0, $db->transactionLevel());
                self::assertSame($original->merchantAccountRef, $credentials->merchantAccountRef);
                $received = self::wire($original);
                new DurableJournal($path.'.psp')->append(new JournalEvent('replay-transport', $received, ProcessIdentity::current()));
                self::assertSame(self::single($path.'.psp', 'first-request')->data, $received);

                return $case === 'lost-replay-response'
                    ? new ProviderLookupResult(ProviderLookupState::Unavailable, null, null, null, 'replay_acknowledgement_lost', null)
                    : self::originalResult($path);
            });
        }
        self::assertInstanceOf(PaymentDriver::class, $double);
        $drivers = self::createStub(PaymentDrivers::class);
        $drivers->method('resolve')->willReturn($double);
        $drivers->method('capabilities')->willReturn($cap);
        $app->instance(PaymentDrivers::class, $drivers);
    }

    private static function acceptFirstFrame(string $path, string $body, string $key, DriverRequest $request, PaymentDriver $provider, bool $refund): void
    {
        parse_str($body, $decoded);
        self::assertSame([$refund ? '30' : '100', 'RUB', 'a b'], [$decoded['amount'], $decoded['currency'], $decoded['memo']]);
        $scope = $request->binding->scope;
        $received = ['bodySha256' => hash('sha256', $body), 'keySha256' => hash('sha256', $key), 'account' => $request->credentials->merchantAccountRef,
            'provider' => 'simulated-provider', 'installation' => $scope->installationRef, 'seller' => $scope->sellerRef, 'environment' => $scope->environment,
            'namespace' => $scope->intentOwnerNamespace, 'attempt' => $request->permit->attemptRef, 'operation' => $request->permit->operationRef,
            'reference' => $request->providerReference, 'driver' => $request->capabilities->driverId, 'contract' => $provider->contractVersion(), 'egress' => $request->permit->ticketRef];
        $journal = new DurableJournal($path.'.psp');
        $journal->append(new JournalEvent('first-request', $received, ProcessIdentity::current()));
        $journal->append($refund ? $provider->refund($request) : $provider->execute($request));
    }

    private function installAuthority(Application $app, string $path, DeterministicWriterHost $writer, string $case): void
    {
        $host = self::createStub(OriginalReplayAuthority::class);
        $db = $app->make(DatabaseManager::class)->connection();
        $host->method('authorize')->willReturnCallback(static function (string $id, DispatchTicket $ticket, OriginalProviderRequest $original, DateTimeImmutable $window) use ($path, $writer, $db, $case): ReplayAuthorization {
            self::assertSame(0, $db->transactionLevel());
            $writer->verify($ticket, $ticket->claims);
            new DurableJournal($path.'.operator')->append(new JournalEvent('approval-check', ['approval' => $id], ProcessIdentity::current()));
            $writerSend = array_find($writer->journal->records(), static fn (object $r): bool => $r instanceof JournalEvent && $r->kind === 'egress.send'
                && $r->data['ticket'] === $original->originalEgressRef && $r->data['operation'] === $original->operationRef);
            if ($writerSend === null || self::single($path.'.psp', 'first-request')->data !== self::wire($original)) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Original transport receipt does not match first egress');
            }
            $records = new DurableJournal($path.'.operator')->records();
            $grant = array_find($records, static fn (object $r): bool => $r instanceof JournalEvent && $r->kind === 'approve' && $r->data['approval'] === $id);
            if (! $grant instanceof JournalEvent || self::wire($original) !== array_diff_key($grant->data, ['expires' => true, 'approval' => true])
                || $window <= new DateTimeImmutable) {
                throw new ProblemException(ProblemCode::AuthDenied, 'Independent approval for original bytes/key/account unavailable');
            }

            $expires = $case === 'expired-at-egress' ? new DateTimeImmutable('+1 second') : new DateTimeImmutable(self::text($grant->data['expires']));

            return new ReplayAuthorization($id, $ticket->ticketRef, $original->digest(), $expires);
        });
        $host->method('invoke')->willReturnCallback(static function (ReplayAuthorization $approval, DispatchTicket $ticket, OriginalProviderRequest $original, DriverRequest $current, Closure $send) use ($path, $writer, $case, $db): ProviderLookupResult {
            self::assertSame(0, $db->transactionLevel());
            if ($case === 'revoked-at-egress') {
                $writer->revoke($ticket);
            }
            $writer->verify($ticket, $ticket->claims);
            $deadline = $current->permit->requestDeadlineAt;
            self::assertNotNull($deadline);
            $journal = new DurableJournal($path.'.operator');
            if ($case === 'revoked-approval-at-egress') {
                $journal->append(new JournalEvent('revoked', ['approval' => $approval->approvalRef], ProcessIdentity::current()));
            } elseif ($case === 'expired-at-egress') {
                $db->selectOne('SELECT pg_sleep(greatest(0,extract(epoch FROM (?::timestamptz - clock_timestamp()))) + 0.01)', [$deadline->format('Y-m-d H:i:s.uP')]);
            }

            return $writer->invoke($ticket, $current, static function () use ($journal, $approval, $ticket, $original, $deadline, $send): ProviderLookupResult {
                $result = null;
                // The current writer is fenced through approval consumption and the transport call.
                $journal->transact(static function (array $records) use ($approval, $ticket, $original, $deadline): array {
                    $prior = array_find($records, static fn (object $r): bool => $r instanceof JournalEvent && in_array($r->kind, ['consumed', 'revoked'], true) && $r->data['approval'] === $approval->approvalRef);
                    $grant = array_find($records, static fn (object $r): bool => $r instanceof JournalEvent && $r->kind === 'approve' && $r->data['approval'] === $approval->approvalRef);
                    if ($prior !== null || ! $grant instanceof JournalEvent || self::wire($original) !== array_diff_key($grant->data, ['expires' => true, 'approval' => true])
                        || $approval->requestDigest !== $original->digest() || $approval->writerTicketRef !== $ticket->ticketRef || min($approval->expiresAt, $deadline) <= new DateTimeImmutable) {
                        throw new ProblemException(ProblemCode::AuthDenied, 'External replay egress refused');
                    }

                    return ['value' => true, 'append' => [new JournalEvent('consumed', ['approval' => $approval->approvalRef], ProcessIdentity::current())]];
                }, static function () use ($send, &$result): void {
                    $result = $send();
                });
                self::assertInstanceOf(ProviderLookupResult::class, $result);

                return $result;
            });
        });
        $app->instance(OriginalReplayAuthority::class, $host);
    }

    /** @return array<string,string> */
    private static function wire(OriginalProviderRequest $original): array
    {
        return ['bodySha256' => hash('sha256', $original->body()), 'keySha256' => hash('sha256', $original->providerKey), 'account' => $original->merchantAccountRef,
            'provider' => $original->providerId, 'installation' => $original->scope->installationRef, 'seller' => $original->scope->sellerRef,
            'environment' => $original->scope->environment, 'namespace' => $original->scope->intentOwnerNamespace, 'attempt' => $original->attemptRef, 'operation' => $original->operationRef, 'reference' => $original->providerReference,
            'driver' => $original->driverId, 'contract' => $original->contractVersion, 'egress' => $original->originalEgressRef];
    }

    private static function single(string $file, string $kind): JournalEvent
    {
        $entries = array_values(array_filter(new DurableJournal($file)->records(), static fn (object $r): bool => $r instanceof JournalEvent && $r->kind === $kind));
        self::assertCount(1, $entries);
        self::assertInstanceOf(JournalEvent::class, $entries[0]);

        return $entries[0];
    }

    private static function originalResult(string $path): ProviderLookupResult
    {
        $result = array_find(new DurableJournal($path.'.psp')->records(), static fn (object $r): bool => $r instanceof ProviderLookupResult);
        self::assertInstanceOf(ProviderLookupResult::class, $result);

        return $result;
    }
}

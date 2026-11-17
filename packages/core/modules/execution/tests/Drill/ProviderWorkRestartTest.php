<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Drill;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\PaymentDriver;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\LookupRequest;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Enums\ProviderLookupState;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Feature\CallbackCustodyTest as Inputs;
use Billium\Testkit\Drivers\DeterministicPaymentDriver;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Drivers\SimulatedAbsenceVerifier;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessBarrier;
use Billium\Testkit\Fixtures\ProcessIdentity;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
final class ProviderWorkRestartTest extends ExecutionTestCase
{
    public static function callbackChild(string $path, string $boundary): void
    {
        $app = Fixture::workerApp(902);
        Fixture::host($app, $path);
        $control = new ProcessBarrier(new DurableJournal($path.'.callback'));
        $control->arrive('ready');
        $control->await('accepted', 1, 20000);
        $event = Inputs::providerInput($app);
        $ack = $app->make(CallbackCustody::class)->receive(Fixture::scope(), 'merchant-a', 'root-a', $event)->orThrow();
        $log = new DurableJournal($path.'.callback');
        $log->append(new JournalEvent($boundary, ['work' => self::text($ack->body['commandKey']), 'backend' => self::integer($app->make(DatabaseManager::class)->connection()->scalar('SELECT pg_backend_pid()'))], ProcessIdentity::current()));
        $barrier = new ProcessBarrier($log);
        $barrier->arrive($boundary);
        $barrier->await('release', 1, 20000);
    }

    /** @return list<array{string}> */
    public static function ackBoundaries(): array
    {
        return [['committed_before_ack'], ['transport_ack']];
    }

    #[DataProvider('ackBoundaries')]
    #[Group('ACC-CON-06')]
    #[Group('ACC-CON-23')]
    public function test_process_death_before_or_after_callback_ack_and_delayed_sender_finish_do_not_lose_or_repeat_effect(string $boundary): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        Fixture::seedMerchant($app);
        Fixture::host($app, $path);
        $callback = new ChildProcess([PHP_BINARY, '-r', 'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Drill\\ProviderWorkRestartTest::callbackChild($argv[1],$argv[2]);', $path, $boundary]);
        $log = new DurableJournal($path.'.callback');
        $control = new ProcessBarrier($log);
        $barrier = new ProcessBarrier(new DurableJournal($path.'.control'));
        try {
            $control->await('ready', 1, 15000);
            $sender = UnknownResultTest::start($path, 'after-accept');
            $barrier->await('at-boundary', 1, 15000);
            $control->arrive('accepted');
            try {
                $control->await($boundary, 1, 15000);
            } catch (RuntimeException $timeout) {
                $failed = $callback;
                $callback = null;
                $failed->wait();
                throw $timeout;
            }
            $callback->kill();
            $callback = null;
            $ack = array_find($log->records(), static fn (object $entry): bool => $entry instanceof JournalEvent && $entry->kind === $boundary);
            self::assertInstanceOf(JournalEvent::class, $ack);
            self::assertNotSame(self::integer($this->value('SELECT pg_backend_pid()')), self::integer($ack->data['backend']));
            self::assertSame(0, $this->rows('billium_execution_provider_fact'));
            self::assertSame(1, $this->rows('billium_execution_provider_work', "state='due'"));
            self::assertSame($boundary === 'transport_ack' ? 1 : 0, UnknownResultTest::events($path.'.callback', 'transport_ack'));
            $work = self::text($ack->data['work']);
            if ($boundary === 'committed_before_ack') {
                $retry = $app->make(CallbackCustody::class)->receive(Fixture::scope(), 'merchant-a', 'root-a', Inputs::providerInput($app))->orThrow();
                self::assertSame($work, $retry->body['commandKey']);
                self::assertSame('custody_accepted', $retry->body['status']);
            }
            $result = $app->make(EvidenceReducer::class)->reduce($work);
            self::assertSame(ReductionDisposition::Applied, $result->disposition);
            $before = $this->row('SELECT state,lease_fence,finish_evidence_ref FROM billium_execution_attempt');
            $barrier->arrive('resume');
            self::assertSame(0, $sender->wait());
            self::assertSame($before, $this->row('SELECT state,lease_fence,finish_evidence_ref FROM billium_execution_attempt'));
            $finish = array_find(new DurableJournal($path.'.control')->records(), static fn (object $entry): bool => $entry instanceof JournalEvent && $entry->kind === 'finish');
            self::assertInstanceOf(JournalEvent::class, $finish);
            self::assertSame('retained_stale', $finish->data['status']);
            self::assertSame(1, $this->rows('billium_execution_provider_evidence', "signature_verdict='local_transport'"));
            self::assertSame(1, $this->rows('billium_execution_provider_fact'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            if ($callback !== null) {
                $callback->kill();
            }
            $barrier->arrive('resume');
            UnknownResultTest::clean($path);
            if (is_file($path.'.callback')) {
                unlink($path.'.callback');
            }
        }
    }

    public function test_certified_absence_reverified_outside_transaction_closes_attempt_without_synthesizing_provider_totals(): void
    {
        $app = $this->application();
        $clock = new FixtureClock(new DateTimeImmutable);
        $cap = SimulatedCapabilities::snapshot();
        $path = Fixture::tempPath();
        try {
            Fixture::seedMerchant($app, $cap);
            $host = Fixture::host($app, $path, cap: $cap, mode: ResponseMode::Unavailable, clock: $clock);
            $scope = Fixture::scope();
            $attemptRef = UnknownResultTest::attemptRef();
            $app->make(RootPaymentCommands::class)->admit($scope, Fixture::wire())->orThrow();
            $app->make(AttemptWorker::class)->dispatch($scope, $attemptRef, 'original', new DateTimeImmutable('+1 second'))->orThrow();
            $ticket = array_find(new DurableJournal($path.'.host')->records(), static fn (object $entry): bool => $entry instanceof DispatchTicket);
            self::assertInstanceOf(DispatchTicket::class, $ticket);
            $store = $app->make(ExecutionStore::class);
            $operation = $store->operation($scope, 'root-a');
            $attempt = $store->attempt($scope, $attemptRef);
            self::assertNotNull($operation);
            self::assertNotNull($attempt);
            $host->retire($ticket->claims, 'retired-fixture-egress');
            usleep(1100000);
            $clock->now = new DateTimeImmutable;
            $verifier = new SimulatedAbsenceVerifier(new DurableJournal($path), new DurableJournal($path.'.host'), new DurableJournal($path.'.cert'), $clock, $cap, static fn (): int => $app->make(DatabaseManager::class)->connection()->transactionLevel());
            $certificate = $verifier->certify($operation->binding, $attempt->providerReference, $operation->createdAt->modify('-1 minute'), $clock->now);
            $real = $app->make(PaymentDrivers::class)->resolve($cap->driverId, $cap->driverContractVersion);
            $lookups = self::createStub(PaymentDriver::class);
            $lookups->method('contractVersion')->willReturn($real->contractVersion());
            $lookups->method('lookup')->willReturnCallback(static function (LookupRequest $req) use ($real, $certificate): ProviderLookupResult {
                self::assertSame(ProviderLookupState::Ambiguous, $real->lookup($req)->state);

                return new ProviderLookupResult(ProviderLookupState::CertifiedAbsent, null, null, $certificate, null, null);
            });
            $drivers = self::createStub(PaymentDrivers::class);
            $drivers->method('resolve')->willReturn($lookups);
            $drivers->method('capabilities')->willReturn($cap);
            $app->instance(PaymentDrivers::class, $drivers);
            $app->instance(ProviderAbsenceVerifier::class, $verifier);
            $app->make(AttemptWorker::class)->recover($scope, $attemptRef, 'certified', new DateTimeImmutable('+30 seconds'))->orThrow();
            $work = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE handler_contract_version='execution.transport.v1'"));
            $result = $app->make(EvidenceReducer::class)->reduce($work);
            self::assertSame(ReductionDisposition::Applied, $result->disposition);
            self::assertSame([], $result->observationCommandRefs);
            self::assertSame(1, $this->rows('billium_execution_certified_absence'));
            self::assertSame(1, $this->rows('billium_execution_operation', "state='failed' AND revision=1"));
            self::assertSame(1, $this->rows('billium_execution_provider_work', "state='processed' AND result_ref=?", [$certificate->certificateRef]));
            self::assertSame(0, $this->rows('billium_execution_provider_fact'));
            self::assertSame(0, $this->rows('billium_outbox_command'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_sealed_old_epoch_still_accepts_custody_and_only_independently_admitted_recovery_can_reduce(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            $event = Inputs::dispatched($app, $path);
            $this->authority->issueTransition('seal', 'provider-seal', [1]);
            $this->installation()->seal('provider-seal', 1);
            $late = Inputs::signed($event, ['revision' => '2']);
            $receipt = $app->make(CallbackCustody::class)->receive(Fixture::scope(), 'merchant-a', 'root-a', $late)->orThrow();
            $work = self::text($receipt->body['commandKey']);
            $reducer = $app->make(EvidenceReducer::class);
            try {
                $reducer->reduce($work);
                self::fail('Closed epoch admitted money');
            } catch (ProblemException $failure) {
                self::assertNotSame('', $failure->problem->value);
            }
            self::assertSame(0, $this->rows('billium_execution_operation_revision'));
            self::assertSame(1, $this->rows('billium_execution_provider_evidence', 'evidence_ref=?', [$late->evidenceRef]));
            $this->authority->issueTransition('open', 'provider-open', ['owner-2', 2, 2, 2, 'independent-retirement']);
            $this->installation()->openSession('provider-open', 'owner-2', 2, 2, 2, 'independent-retirement');
            $token = FixedSessionAdmission::token(self::INSTALLATION, ownerSessionRef: 'owner-2', deploymentGeneration: 2, attachmentGeneration: 2, authorityEpoch: 2, modes: [FinancialMode::Recovery]);
            $this->tokens->set($token);
            $this->authority->issueProcess($token, [new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium')]);
            $this->owner()->table('billium_execution_provider_work')->where('work_ref', $work)->update(['due_at' => new DateTimeImmutable('-1 second')]);
            self::assertSame(ReductionDisposition::Applied, $reducer->reduce($work)->disposition);
            self::assertSame(1, $this->rows('billium_execution_observation_emission'));
            self::assertSame(0, $this->rows('billium_allocation'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_verified_pending_reopens_only_parked_lookup_and_cold_id_only_driver_resolves_original_attempt(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $cap = AssignedProviderIdRecoveryTest::capability();
            $pending = Inputs::dispatched($app, $path, ResponseMode::Pending, $cap);
            $store = $app->make(ExecutionStore::class);
            $scope = Fixture::scope();
            $attempt = UnknownResultTest::attemptRef();
            self::assertNull($store->providerOperationId($scope, $attempt));
            $this->owner()->table('billium_execution_dispatch_work')->where('attempt_ref', $attempt)->update(['due_at' => new DateTimeImmutable('-1 second')]);
            $app->make(AttemptWorker::class)->recover($scope, $attempt, 'no-assigned-id', new DateTimeImmutable('+30 seconds'))->orThrow();
            self::assertSame(1, $this->rows('billium_execution_dispatch_work', "state='parked' AND due_at IS NULL"));
            self::assertSame(0, UnknownResultTest::events($path, 'provider.lookup'));
            $reducer = $app->make(EvidenceReducer::class);
            self::assertSame(ReductionDisposition::Applied, $reducer->reduce(Inputs::work($pending))->disposition);
            $material = $pending->sanitizedPayload['material'];
            self::assertIsArray($material);
            self::assertNull($material['operationAmount']);
            self::assertSame(0, $this->rows('billium_execution_operation_revision'));
            self::assertSame(0, $this->rows('billium_execution_observation_emission'));
            $assigned = self::text($material['providerOperationId']);
            self::assertSame($assigned, $store->providerOperationId($scope, $attempt));
            self::assertSame(1, $this->rows('billium_execution_dispatch_work', "state='due' AND due_at>clock_timestamp()"));
            $driver = $app->make(PaymentDrivers::class)->resolve($cap->driverId, $cap->driverContractVersion);
            self::assertInstanceOf(DeterministicPaymentDriver::class, $driver);
            $terminal = $driver->settle(self::text($material['stableEffectId']), 'succeeded')->evidence;
            self::assertNotNull($terminal);
            $this->owner()->table('billium_execution_dispatch_work')->where('attempt_ref', $attempt)->update(['due_at' => new DateTimeImmutable('-1 second')]);
            $cold = new ChildProcess([PHP_BINARY, '-r', 'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Drill\\AssignedProviderIdRecoveryTest::restartedWorker($argv[1],false,true);', $path]);
            self::assertSame(0, $cold->wait());
            $lookup = array_find(new DurableJournal($path)->records(), static fn (object $entry): bool => $entry instanceof JournalEvent && $entry->kind === 'provider.lookup');
            self::assertInstanceOf(JournalEvent::class, $lookup);
            self::assertSame($assigned, $lookup->data['providerOperationId']);
            self::assertSame(ReductionDisposition::Applied, $reducer->reduce(Inputs::work($terminal))->disposition);
            self::assertSame(1, $this->rows('billium_execution_attempt', "state='succeeded'"));
            self::assertSame(1, $this->rows('billium_execution_observation_emission'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.lookup'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_pending_signed_material_has_bounded_lookup_schedule_and_terminal_material_stops_the_same_attempt(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $event = Inputs::dispatched($app, $path, ResponseMode::Pending);
            $reducer = $app->make(EvidenceReducer::class);
            self::assertSame(ReductionDisposition::Applied, $reducer->reduce(Inputs::work($event))->disposition);
            self::assertSame(1, $this->rows('billium_execution_attempt', "state='pending' AND started_at IS NOT NULL"));
            self::assertSame(1, $this->rows('billium_execution_dispatch_work', "state='due' AND due_at>clock_timestamp()"));
            $one = ['amount' => '1.00', 'currency' => 'RUB'];
            $succeeded = Inputs::signed($event, ['revision' => '2', 'status' => 'succeeded', 'operationAmount' => $one, 'authorized' => $one, 'captured' => $one]);
            $app->make(CallbackCustody::class)->receive(Fixture::scope(), 'merchant-a', 'root-a', $succeeded)->orThrow();
            self::assertSame(ReductionDisposition::Applied, $reducer->reduce(Inputs::work($succeeded))->disposition);
            self::assertSame(1, $this->rows('billium_execution_attempt', "state='succeeded'"));
            self::assertSame(1, $this->rows('billium_execution_dispatch_work', "state='processed' AND due_at IS NULL"));
            $app->make(AttemptWorker::class)->recover(Fixture::scope(), UnknownResultTest::attemptRef(), 'cold', new DateTimeImmutable('+1 minute'))->orThrow();
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(0, UnknownResultTest::events($path, 'provider.lookup'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }
}

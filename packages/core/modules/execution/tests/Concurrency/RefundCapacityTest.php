<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Concurrency;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\RequestRefund;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\CallbackCustodyTest as Inputs;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessBarrier;
use Billium\Testkit\Fixtures\ProcessIdentity;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

#[Group('pg')]
#[Group('ACC-CON-03')]
final class RefundCapacityTest extends ExecutionTestCase
{
    /** @param array<string,mixed> $changes */
    public static function refund(string $op, int $minor, int $key, array $changes = []): RequestRefund
    {
        $wire = (new Codec)->decode(json_encode(array_replace([
            'schema' => RequestRefund::SCHEMA, 'commandKey' => AdmissionIdentityTest::key($key), 'installationRef' => self::INSTALLATION,
            'sellerRef' => 'seller-a', 'environment' => 'test', 'operationRef' => $op, 'originalOperationRef' => 'root-a',
            'source' => ['context' => 'checkout', 'intentRef' => 'business-'.$op, 'orderRef' => 'order-a'],
            'money' => ['amount' => intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT), 'currency' => 'RUB'],
            'reasonCode' => 'returned_goods', 'category' => 'customer_return', 'requestedAt' => '2026-11-17T00:00:00Z',
        ], $changes), JSON_THROW_ON_ERROR));
        self::assertInstanceOf(RequestRefund::class, $wire);

        return $wire;
    }

    public static function captured(Application $app, string $path, ?CapabilitySnapshot $cap = null): void
    {
        $event = Inputs::dispatched($app, $path, cap: $cap);
        self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce(Inputs::work($event))->disposition);
    }

    public static function attemptRef(string $operation): string
    {
        return PaymentIdentity::reference(AdmissionIdentityTest::scope(), $operation, 'attempt');
    }

    public static function contender(int $index, string $path, string $kind): void
    {
        $app = AdmissionIdentityTest::workerApp($index);
        $db = $app->make(DatabaseManager::class)->connection();
        $caller = $app->make(CallerContext::class)->current();
        $app->instance(CallerContext::class, new readonly class($caller, $app->make(DatabaseManager::class)) implements CallerContext
        {
            public function __construct(private Caller $caller, private DatabaseManager $databases) {}

            public function current(): Caller
            {
                if ($this->databases->connection()->transactionLevel() !== 0) {
                    throw new LogicException('Live host caller requested inside refund transaction');
                }

                return $this->caller;
            }
        });
        AdmissionIdentityTest::host($app, $path);
        $backend = self::integer($db->scalar('SELECT pg_backend_pid()'));
        $journal = new DurableJournal($path.'.control');
        $barrier = new ProcessBarrier($journal);
        $barrier->arrive('ready');
        $barrier->await('go', 1, 45_000);
        $wire = $kind === 'independent' ? self::refund('refund-'.$index, 10, $index + 10) : self::refund('one-logical-refund', 10, $index % 2 === 0 ? 2 : $index + 10);
        $outcome = $app->make(RefundCommands::class)->admit(AdmissionIdentityTest::scope(), $wire);
        $journal->append(new JournalEvent('admission', ['backend' => $backend, 'index' => $index, 'result' => $outcome->problem->value ?? 'accepted', 'receipt' => self::text($outcome->body['receiptRef'] ?? '')], ProcessIdentity::current()));
    }

    /** @return list<array{string,int,int}> */
    public static function races(): array
    {
        return [['independent', 10, 10], ['same-business', 20, 1]];
    }

    #[DataProvider('races')]
    public function test_twenty_public_refunds_wait_for_the_charge_lock_and_conserve_confirmed_capture(string $kind, int $accepted, int $reservations): void
    {
        $path = AdmissionIdentityTest::tempPath();
        $workers = [];
        try {
            self::captured($this->application(), $path);
            $journal = new DurableJournal($path.'.control');
            $barrier = new ProcessBarrier($journal);
            for ($i = 0; $i < 20; $i++) {
                $workers[] = new ChildProcess([PHP_BINARY, '-r',
                    'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Concurrency\\RefundCapacityTest::contender((int)$argv[1],$argv[2],$argv[3]);', (string) $i, $path, $kind]);
            }
            $barrier->await('ready', 20, 45_000);
            $owner = $this->owner();
            $owner->beginTransaction();
            try {
                $owner->selectOne("SELECT id FROM billium_execution_operation WHERE operation_ref='root-a' FOR UPDATE");
                $barrier->arrive('go');
                $until = microtime(true) + 10;
                do {
                    $blocked = self::integer($owner->scalar('SELECT count(*) FROM pg_stat_activity WHERE pg_backend_pid()=ANY(pg_blocking_pids(pid))'));
                    if ($blocked === 0) {
                        usleep(10_000);
                    }
                } while ($blocked === 0 && microtime(true) < $until);
                self::assertGreaterThan(0, $blocked);
                self::assertSame(0, $this->rows('billium_execution_reservation'));
            } finally {
                $owner->rollBack();
            }
            $failure = null;
            foreach ($workers as $worker) {
                try {
                    self::assertSame(0, $worker->wait());
                } catch (Throwable $error) {
                    $failure ??= $error;
                }
            }
            if ($failure !== null) {
                throw $failure;
            }
            $results = array_values(array_filter($journal->records(), static fn (object $r): bool => $r instanceof JournalEvent && $r->kind === 'admission'));
            self::assertCount(20, $results);
            self::assertCount(20, array_unique(array_map(static fn (JournalEvent $r): int => $r->pid, $results)));
            self::assertCount(20, array_unique(array_map(static fn (JournalEvent $r): mixed => $r->data['backend'], $results)));
            self::assertCount($accepted, array_filter($results, static fn (JournalEvent $r): bool => $r->data['result'] === 'accepted'));
            $receipts = array_filter(array_map(static fn (JournalEvent $r): string => self::text($r->data['receipt']), $results), static fn (string $receipt): bool => $receipt !== '');
            self::assertCount($reservations, array_unique($receipts));
            fwrite(STDOUT, PHP_EOL.json_encode(['refundRace' => $kind, 'contenders' => array_map(static fn (JournalEvent $r): array => [$r->pid, $r->data['backend'], $r->data['result']], $results)], JSON_THROW_ON_ERROR).PHP_EOL);
            self::assertCount(20 - $accepted, array_filter($results, static fn (JournalEvent $r): bool => $r->data['result'] === ProblemCode::RefundCapacityExceeded->value));
            self::assertSame($reservations * 10, self::integer($this->value("SELECT SUM(requested_minor) FROM billium_execution_reservation WHERE state='held'")));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND held_refund_minor=? AND confirmed_refund_minor=0 AND NOT financial_hold', [$reservations * 10]));
            self::assertSame($reservations, $this->rows('billium_execution_child_identity'));
            self::assertSame(1 + $reservations, $this->rows('billium_execution_attempt'));
            self::assertSame(1 + $reservations, $this->rows('billium_execution_dispatch_work'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(0, $this->rows('billium_payment'));
            self::assertSame(0, $this->rows('billium_allocation'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_int64_refundable_basis_keeps_the_last_minor_unit_exact_at_the_wire_boundary(): void
    {
        $path = AdmissionIdentityTest::tempPath();
        try {
            $app = $this->application();
            $scope = AdmissionIdentityTest::scope();
            AdmissionIdentityTest::seedMerchant($app);
            AdmissionIdentityTest::host($app, $path);
            $app->make(RootPaymentCommands::class)->admit($scope, AdmissionIdentityTest::wire(['money' => ['amount' => '92233720368547758.07', 'currency' => 'RUB']]))->orThrow();
            $app->make(AttemptWorker::class)->dispatch($scope, self::attemptRef('root-a'), 'sale', new DateTimeImmutable('+1 minute'))->orThrow();
            $work = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE state='due'"));
            self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce($work)->disposition);
            $commands = $app->make(RefundCommands::class);
            $commands->admit($scope, self::refund('almost-all', PHP_INT_MAX - 1, 12))->orThrow();
            self::assertSame(ProblemCode::RefundCapacityExceeded, $commands->admit($scope, self::refund('two', 2, 13))->problem);
            $commands->admit($scope, self::refund('last-cent', 1, 14))->orThrow();
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=9223372036854775807 AND held_refund_minor=9223372036854775807 AND confirmed_refund_minor=0'));
            self::assertSame(2, $this->rows('billium_execution_reservation'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    #[Group('PB-16')]
    #[Group('ACC-CON-04')]
    public function test_unknown_seventy_retains_capacity_past_provider_key_ttl_and_lookup_miss(): void
    {
        $path = AdmissionIdentityTest::tempPath();
        try {
            $app = $this->application();
            $scope = AdmissionIdentityTest::scope();
            $cap = SimulatedCapabilities::snapshot(idempotencySeconds: 1);
            self::captured($app, $path, $cap);
            $clock = new FixtureClock(new DateTimeImmutable);
            AdmissionIdentityTest::host($app, $path, cap: $cap, mode: ResponseMode::Unavailable, clock: $clock);
            $commands = $app->make(RefundCommands::class);
            $commands->admit($scope, self::refund('first', 70, 2))->orThrow();
            $worker = $app->make(AttemptWorker::class);
            self::assertSame('unknown', $worker->dispatch($scope, self::attemptRef('first'), 'sender', new DateTimeImmutable('+10 seconds'))->body['status']);
            usleep(1_100_000);
            $clock->now = new DateTimeImmutable;
            $worker->recover($scope, self::attemptRef('first'), 'recovery', new DateTimeImmutable('+1 minute'))->orThrow();
            self::assertSame(1, $this->rows('billium_execution_reservation', "state='held' AND requested_minor=70 AND release_evidence_ref IS NULL"));
            self::assertSame(0, $this->rows('billium_execution_certified_absence'));
            self::assertSame(ProblemCode::RefundCapacityExceeded, $commands->admit($scope, self::refund('too-much', 40, 3))->problem);
            $commands->admit($scope, self::refund('remainder', 30, 4))->orThrow();
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND confirmed_refund_minor=0 AND held_refund_minor=100'));
            self::assertSame(2, $this->rows('billium_execution_reservation', "state='held'"));
            self::assertSame(0, $this->rows('billium_execution_operation', "operation_ref='too-much'"));
            self::assertSame(2, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.lookup'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }
}

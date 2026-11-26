<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Concurrency;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\CallbackCustodyTest as Inputs;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Allocations\ReallocatePayment;
use Billium\Ledger\Data\Allocations\ReallocationMode;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessBarrier;
use Billium\Testkit\Fixtures\ProcessIdentity;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

#[Group('pg')]
final class ExecutionAcceptanceRacesTest extends ExecutionTestCase
{
    /** @return list<array{string}> */
    public static function firstContenders(): array
    {
        return [['callback'], ['refund']];
    }

    #[Group('ACC-CON-04')]
    #[DataProvider('firstContenders')]
    public function test_charge_callback_and_three_refund_commands_contend_on_the_pending_charge(string $first): void
    {
        $path = Fixture::tempPath();
        try {
            Inputs::dispatched($this->application(), $path);
            self::assertSame(1, $this->rows('billium_execution_attempt', "state='pending'"));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=0'));
            $results = $this->race($path, 'charge', 4, $first === 'callback' ? 0 : 1);
            self::assertSame('applied', $results[0]->data['result']);
            $admissions = array_slice($results, 1);
            $accepted = 0;
            foreach ($admissions as $row) {
                $index = self::integer($row->data['index']);
                $result = $row->data['result'];
                self::assertContains($result, ['accepted', ProblemCode::RefundCapacityExceeded->value]);
                $stored = $this->application()->make(RefundCommands::class)->admit(Fixture::scope(), RefundCapacityTest::refund('refund-'.$index, 20, 10 + $index));
                self::assertSame($result, $stored->problem->value ?? 'accepted');
                self::assertSame($row->data['receipt'], $stored->body['receiptRef'] ?? '');
                if ($result === 'accepted') {
                    $accepted++;
                }
            }
            if ($first === 'callback') {
                self::assertSame(3, $accepted);
            } else {
                self::assertSame(ProblemCode::RefundCapacityExceeded->value, $results[1]->data['result']);
            }
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND confirmed_refund_minor=0 AND held_refund_minor=?', [$accepted * 20]));
            self::assertSame($accepted, $this->rows('billium_execution_reservation', "state='held' AND requested_minor=20"));
            foreach (['operation', 'attempt', 'dispatch_work'] as $table) {
                self::assertSame(1 + $accepted, $this->rows('billium_execution_'.$table));
            }
            self::assertSame(3, $this->rows('billium_idempotency_receipt', "command_kind='payment.refund.v1'"));
            self::assertSame(1, $this->rows('billium_execution_operation_revision'));
            self::assertSame(1, $this->rows('billium_outbox_command'));
            self::assertSame(0, $this->rows('billium_payment'));
            $this->providerEffects($path, [100]);
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    #[Group('ACC-CON-05')]
    public function test_eight_callback_and_lookup_workers_apply_one_revision_and_quarantine_changed_provider_material(): void
    {
        $path = Fixture::tempPath();
        try {
            Inputs::dispatched($this->application(), $path, ResponseMode::AcceptedThenTimeout);
            self::assertSame(1, $this->rows('billium_execution_attempt', "state='unknown'"));
            self::assertSame(0, $this->rows('billium_execution_operation_revision'));
            $results = $this->race($path, 'evidence', 8);
            $values = array_map(static fn (JournalEvent $row): mixed => $row->data['result'], $results);
            self::assertCount(1, array_keys($values, 'applied', true));
            self::assertCount(6, array_keys($values, 'duplicate', true));
            self::assertSame('quarantined', $values[7]);
            self::assertSame(1, UnknownResultTest::events($path, 'provider.lookup'));
            self::assertSame(1, $this->rows('billium_execution_provider_evidence', "sanitized_payload->>'source'='lookup'"));
            self::assertSame(1, $this->rows('billium_execution_provider_evidence', "signature_verdict='quarantined_conflict'"));
            self::assertSame(1, $this->rows('billium_execution_provider_work', "state='quarantined'"));
            self::assertSame(1, $this->rows('billium_execution_provider_work', "state='processed'"));
            self::assertSame(1, $this->rows('billium_execution_provider_fact'));
            self::assertSame(1, $this->rows('billium_execution_operation_revision'));
            self::assertSame(1, $this->rows('billium_execution_observation_emission'));
            self::assertSame(1, $this->rows('billium_execution_observation_partition', 'last_sequence=1'));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND confirmed_refund_minor=0'));
            self::assertSame(1, $this->rows('billium_outbox_command'));
            self::assertSame(1, $this->rows('billium_execution_attempt'));
            self::assertSame(0, $this->rows('billium_payment'));
            $this->providerEffects($path, [100]);
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    /** @return list<array{bool}> */
    public static function reservationCases(): array
    {
        return [[false], [true]];
    }

    #[Group('ACC-CON-20')]
    #[DataProvider('reservationCases')]
    public function test_refund_observation_allocation_and_advisory_share_a_fully_paid_invoice(bool $reserved): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $this->paidInvoice($app, $path);
            if ($reserved) {
                $app->make(RefundCommands::class)->admit(Fixture::scope(), RefundCapacityTest::refund('prior-held', 8000, 4))->orThrow();
            }
            $results = $this->race($path, 'invoice', 4, waitForProjection: ! $reserved);
            self::assertSame($reserved ? ProblemCode::RefundCapacityExceeded->value : 'accepted', $results[0]->data['result']);
            self::assertSame($reserved ? 'no observation' : 'delivered', $results[1]->data['result']);
            self::assertContains($results[2]->data['result'], [ProblemCode::AllocationExceedsAvailable->value, ProblemCode::AllocationReallocationRequired->value]);
            self::assertSame('advisory', $results[3]->data['result']);
            self::assertSame(0, $results[3]->data['writes']);
            self::assertContains($results[3]->data['capacity'], $reserved ? [10000] : [7000, 10000]);
            self::assertSame(1, $this->rows('billium_allocation'));
            self::assertSame(1, $this->rows('billium_obligation', "obligation_ref='debt' AND open_minor=0"));
            self::assertSame(1, $this->rows('billium_invoice', "invoice_ref='invoice-paid' AND status='paid'"));
            $refund = $reserved ? 0 : 3000;
            self::assertSame(1, $this->rows('billium_payment', 'captured_minor=10000 AND allocated_minor=10000 AND refunded_minor=? AND reallocation_required=?', [$refund, ! $reserved]));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=10000 AND confirmed_refund_minor=? AND held_refund_minor=?', [$refund, $reserved ? 8000 : 0]));
            self::assertSame(2, $this->rows('billium_execution_operation'));
            self::assertSame(2, $this->rows('billium_execution_attempt'));
            self::assertSame(1, $this->rows('billium_execution_reservation'));
            $observations = $reserved ? 1 : 3;
            self::assertSame($observations, $this->rows('billium_outbox_command'));
            self::assertSame($observations, $this->rows('billium_outbox_command_delivery', "status='delivered'"));
            self::assertSame($observations, $this->rows('billium_allocation_receipt', "kind IN ('refund_detail','payment_observation')"));
            self::assertCount($observations, new DurableJournal($path.'.origins')->records());
            self::assertCount($observations, new DurableJournal($path.'.receiver')->records());
            $this->providerEffects($path, $reserved ? [10000] : [10000, -3000]);
            if (! $reserved) {
                $ledger = $app->make(Ledger::class);
                self::assertSame(ProblemCode::AllocationReallocationRequired, $ledger->allocate(self::allocation('after-refund'))->problem);
                self::assertSame(1, $this->rows('billium_allocation'));
                $ledger->reallocatePayment(new ReallocatePayment('release-refund', self::INSTALLATION, 'seller-a', 'test', 'root-a', 'refund/1', ReallocationMode::Reversal))->orThrow();
                self::assertSame(1, $this->rows('billium_obligation', "obligation_ref='debt' AND open_minor=3000"));
                self::assertSame(1, $this->rows('billium_payment', 'allocated_minor=7000 AND refunded_minor=3000 AND NOT reallocation_required'));
            }
        } finally {
            UnknownResultTest::clean($path);
            foreach (['.origins', '.receiver'] as $suffix) {
                if (is_file($path.$suffix)) {
                    unlink($path.$suffix);
                }
            }
        }
    }

    #[Group('ACC-CON-20')]
    public function test_four_workers_see_no_partial_refund_when_the_admission_fails_after_dispatch_work_insert(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $this->paidInvoice($app, $path);
            $owner = $this->owner();
            $owner->unprepared(<<<'SQL'
                CREATE FUNCTION acceptance_refund_abort() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN RAISE EXCEPTION 'injected terminal refund admission checkpoint'; END $$;
                CREATE TRIGGER acceptance_refund_abort AFTER INSERT ON billium_execution_dispatch_work
                FOR EACH ROW WHEN (NEW.operation_ref='refund-30') EXECUTE FUNCTION acceptance_refund_abort()
                SQL);
            try {
                $results = $this->race($path, 'invoice-fault', 4);
                self::assertSame('admission rolled back', $results[0]->data['result']);
                self::assertSame('no observation', $results[1]->data['result']);
                self::assertSame(ProblemCode::AllocationExceedsAvailable->value, $results[2]->data['result']);
                self::assertSame(0, $results[3]->data['writes']);
                foreach (['operation', 'attempt', 'dispatch_work', 'operation_revision', 'observation_emission'] as $table) {
                    self::assertSame(1, $this->rows('billium_execution_'.$table));
                }
                self::assertSame(0, $this->rows('billium_execution_reservation'));
                self::assertSame(0, $this->rows('billium_execution_child_identity'));
                self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind='payment.refund.v1'"));
                self::assertSame(1, $this->rows('billium_outbox_command'));
                self::assertSame(1, $this->rows('billium_payment', 'allocated_minor=10000 AND refunded_minor=0 AND NOT reallocation_required'));
                self::assertSame(1, $this->rows('billium_allocation'));
                self::assertSame(1, $this->rows('billium_obligation', "obligation_ref='debt' AND open_minor=0"));
                self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=10000 AND held_refund_minor=0 AND confirmed_refund_minor=0'));
                $this->providerEffects($path, [10000]);
            } finally {
                $owner->unprepared('DROP TRIGGER acceptance_refund_abort ON billium_execution_dispatch_work; DROP FUNCTION acceptance_refund_abort()');
            }
            $app->make(RefundCommands::class)->admit(Fixture::scope(), RefundCapacityTest::refund('refund-30', 3000, 8))->orThrow();
            self::dispatchAndDeliver($app, $path, 'refund-30');
            self::assertSame(1, $this->rows('billium_payment', 'allocated_minor=10000 AND refunded_minor=3000 AND reallocation_required'));
            $this->providerEffects($path, [10000, -3000]);
        } finally {
            UnknownResultTest::clean($path);
            foreach (['.origins', '.receiver'] as $suffix) {
                if (is_file($path.$suffix)) {
                    unlink($path.$suffix);
                }
            }
        }
    }

    private static function account(): BillingAccountScope
    {
        return new BillingAccountScope('seller-a', 'test', 'billing-a');
    }

    private static function money(int $minor): Money
    {
        return new Money($minor, Currency::of('RUB'));
    }

    private static function allocation(string $key): Allocate
    {
        return new Allocate($key, self::account(), $key, 'other-debt', self::money(100), 'root-a');
    }

    private function paidInvoice(Application $app, string $path): void
    {
        $account = self::account();
        $participants = $app->make(LedgerParticipants::class);
        $spec = $this->spec(financialScope: new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium'));
        foreach (['debt', 'other-debt'] as $ref) {
            $this->runner()->run($spec, static fn (TransactionContext $ctx) => $participants->recordObligation($ctx, new RecordObligation($ref, $account, $ref, 'basis-'.$ref, 'buyer', 'component', self::money(10000), 'sha256:'.str_repeat('f', 64))))->orThrow();
        }
        $ledger = $app->make(Ledger::class);
        $ledger->createInvoice(new CreateInvoice('draft', $account, 'invoice-paid', 'buyer', [new InvoiceLineInput('debt', 0, ['name' => 'debt'])], ['buyer' => 'Buyer']))->orThrow();
        $ledger->issueInvoice(new IssueInvoice('issue', $account, 'invoice-paid'))->orThrow();
        $this->runner()->run($spec, static fn (TransactionContext $ctx) => $participants->declareSettlementIntent($ctx, new DeclareSettlementIntent('declare', $account, 'intent-a', 'RUB', ['debt'])))->orThrow();
        Fixture::seedMerchant($app);
        Fixture::host($app, $path);
        $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire(['money' => self::money(10000)->toWire()]))->orThrow();
        self::dispatchAndDeliver($app, $path, 'root-a');
        self::assertSame(1, $this->rows('billium_payment', 'captured_minor=10000 AND refunded_minor=0 AND allocated_minor=10000 AND NOT reallocation_required'));
        self::assertSame(1, $this->rows('billium_obligation', "obligation_ref='debt' AND open_minor=0"));
    }

    private static function dispatchAndDeliver(Application $app, string $path, string $op): void
    {
        $app->make(AttemptWorker::class)->dispatch(Fixture::scope(), RefundCapacityTest::attemptRef($op), 'sender', new DateTimeImmutable('+1 minute'))->orThrow();
        $db = $app->make(DatabaseManager::class)->connection();
        $work = self::text($db->scalar("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint=? AND state='due'", [$op]));
        $reduction = $app->make(EvidenceReducer::class)->reduce($work);
        self::assertSame(ReductionDisposition::Applied, $reduction->disposition);
        foreach ($reduction->observationCommandRefs as $ref) {
            OutcomeDeliveryTest::attest($app, $path, $ref);
        }
        $delivery = OutcomeDeliveryTest::relay($app, $path);
        self::assertSame(count($reduction->observationCommandRefs), $delivery->delivered);
        foreach ($reduction->observationCommandRefs as $ref) {
            new DurableJournal($path.'.receiver')->append(new JournalEvent('receiver-ack', ['command' => $ref], ProcessIdentity::current()));
        }
    }

    /** @param list<int> $expected */
    private function providerEffects(string $path, array $expected): void
    {
        $actual = [];
        foreach (new DurableJournal($path)->records() as $record) {
            if ($record instanceof ProviderRecord) {
                self::assertSame('succeeded', $record->status);
                $actual[] = ($record->effectAmounts['captured'] ?? 0) - ($record->effectAmounts['refunded'] ?? 0);
            }
        }
        self::assertSame($expected, $actual);
        self::assertSame(count($expected), UnknownResultTest::events($path, 'provider.call'));
        self::assertSame(count($expected), UnknownResultTest::events($path.'.host', 'egress.send'));
    }

    /** @return list<JournalEvent> */
    private function race(string $path, string $scenario, int $count, ?int $first = null, bool $waitForProjection = false): array
    {
        $journal = new DurableJournal($path.'.control');
        $barrier = new ProcessBarrier($journal);
        $children = [];
        for ($i = 0; $i < $count; $i++) {
            $children[] = new ChildProcess([PHP_BINARY, '-r', 'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Concurrency\\ExecutionAcceptanceRacesTest::contender($argv[1],$argv[2],(int)$argv[3]);', $path, $scenario, (string) $i]);
        }
        try {
            $barrier->await('ready', $count, 30_000);
            $backends = [];
            foreach ($journal->records() as $r) {
                if ($r instanceof JournalEvent && $r->kind === 'identity') {
                    $backends[self::integer($r->data['index'])] = self::integer($r->data['backend']);
                }
            }
            self::assertCount($count, array_unique($backends));
            if ($waitForProjection) {
                $owner = $this->owner();
                $owner->beginTransaction();
                try {
                    $owner->selectOne("SELECT id FROM billium_payment WHERE operation_ref='root-a' FOR UPDATE");
                    $barrier->arrive('go');
                    // Refund TxA, provider, reducer and outbox committed independently. The real receiver
                    // and the allocation command must now both wait in PG before either may change the payment.
                    $this->waitForLocks([$backends[1], $backends[2]]);
                    $barrier->await('advisory-read', 1, 10_000);
                    self::assertSame(1, $this->rows('billium_payment', 'refunded_minor=0 AND allocated_minor=10000'));
                    self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_refund_minor=3000'));
                } finally {
                    $owner->rollBack();
                }
            } elseif ($first === null) {
                $barrier->arrive('go');
            } else {
                $owner = $this->owner();
                $owner->beginTransaction();
                try {
                    $owner->selectOne("SELECT id FROM billium_execution_operation WHERE operation_ref='root-a' FOR UPDATE");
                    $barrier->arrive('go-'.$first);
                    $this->waitForLocks([$backends[$first]]);
                    $barrier->arrive('go');
                    $this->waitForLocks(array_values($backends));
                } finally {
                    $owner->rollBack();
                }
            }
            $failure = null;
            foreach ($children as $i => $child) {
                try {
                    self::assertSame(0, $child->wait());
                } catch (Throwable $error) {
                    $failure ??= $error;
                }
                unset($children[$i]);
            }
            if ($failure !== null) {
                throw $failure;
            }
        } finally {
            foreach ($children as $child) {
                $child->kill();
                $child->wait();
            }
        }
        $rows = [];
        foreach ($journal->records() as $r) {
            if ($r instanceof JournalEvent && $r->kind === 'outcome') {
                $rows[self::integer($r->data['index'])] = $r;
            }
        }
        ksort($rows);
        $ordered = array_values($rows);
        self::assertCount($count, $ordered);
        self::assertCount($count, array_unique(array_map(static fn (JournalEvent $r): int => $r->pid, $ordered)));
        fwrite(STDOUT, PHP_EOL.json_encode(['race' => $scenario, 'first' => $first, 'workers' => array_map(static fn (JournalEvent $r): array => [$r->pid, $r->data], $ordered)], JSON_THROW_ON_ERROR).PHP_EOL);

        return $ordered;
    }

    /** @param list<int> $backends */
    private function waitForLocks(array $backends): void
    {
        $until = microtime(true) + 8;
        do {
            $blocked = self::integer($this->owner()->scalar("SELECT count(*) FROM pg_stat_activity WHERE pid=ANY(?::int[]) AND wait_event_type='Lock' AND cardinality(pg_blocking_pids(pid))>0", ['{'.implode(',', $backends).'}']));
            if ($blocked === count($backends)) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $until);
        self::fail('Expected all selected backends to wait on their charge closure. Blocked '.$blocked.' of '.count($backends));
    }

    public static function contender(string $path, string $scenario, int $i): void
    {
        $app = Fixture::workerApp($i + 80);
        Fixture::host($app, $path);
        $db = $app->make(DatabaseManager::class)->connection();
        $backend = self::integer($db->scalar('SELECT pg_backend_pid()'));
        $journal = new DurableJournal($path.'.control');
        $barrier = new ProcessBarrier($journal);
        $identity = ['backend' => $backend, 'index' => $i];
        $journal->append(new JournalEvent('identity', $identity, ProcessIdentity::current()));
        if ($scenario === 'charge') {
            $event = $i === 0 ? Inputs::providerInput($app) : null;
            $barrier->arrive('ready');
            self::chargeStart($journal, $i);
            if ($event !== null) {
                $receipt = $app->make(CallbackCustody::class)->receive(Fixture::scope(), 'merchant-a', 'root-a', $event)->orThrow();
                $result = $app->make(EvidenceReducer::class)->reduce(self::text($receipt->body['commandKey']));
                $data = ['result' => $result->disposition->value];
            } else {
                $r = $app->make(RefundCommands::class)->admit(Fixture::scope(), RefundCapacityTest::refund('refund-'.$i, 20, 10 + $i));
                $data = ['result' => $r->problem->value ?? 'accepted', 'receipt' => self::text($r->body['receiptRef'] ?? '')];
            }
        } elseif ($scenario === 'evidence') {
            $data = self::evidenceWorker($app, $barrier, $i);
        } else {
            $barrier->arrive('ready');
            $barrier->await('go', 1, 30_000);
            $data = self::invoiceWorker($app, $path, $barrier, $i, $scenario);
        }
        $journal->append(new JournalEvent('outcome', $identity + $data, ProcessIdentity::current()));
    }

    private static function chargeStart(DurableJournal $journal, int $i): void
    {
        $until = microtime(true) + 25;
        do {
            foreach ($journal->records() as $row) {
                if ($row instanceof JournalEvent && $row->kind === 'barrier' && in_array($row->data['name'], ['go', 'go-'.$i], true)) {
                    return;
                }
            }
            usleep(2000);
        } while (microtime(true) < $until);
        self::fail('Charge barrier timed out');
    }

    /** @return array<string,string> */
    private static function evidenceWorker(Application $app, ProcessBarrier $barrier, int $i): array
    {
        $event = Inputs::providerInput($app);
        $scope = Fixture::scope();
        if ($i === 0) {
            $db = $app->make(DatabaseManager::class)->connection();
            $db->selectOne("SELECT pg_sleep(greatest(0,extract(epoch FROM (due_at-clock_timestamp())))::double precision+0.01) FROM billium_execution_dispatch_work WHERE state='due'");
            $r = $app->make(AttemptWorker::class)->recover($scope, UnknownResultTest::attemptRef(), 'lookup', new DateTimeImmutable('+1 minute'))->orThrow();
            self::assertSame('pending', $r->body['status']);
            $work = Inputs::work($event);
            $barrier->arrive('original');
        } else {
            if ($i === 7) {
                // Make the original identity durable before presenting the contradictory signed frame.
                $barrier->await('original', 1, 30_000);
                $event = Inputs::signed($event, ['status' => 'declined']);
            }
            $r = $app->make(CallbackCustody::class)->receive($scope, 'merchant-a', 'root-a', $event)->orThrow();
            self::assertSame($i === 7 ? 'custody_quarantined' : 'custody_accepted', $r->body['status']);
            $work = self::text($r->body['commandKey']);
        }
        $barrier->arrive('ready');
        $barrier->await('go', 1, 30_000);
        try {
            $result = $app->make(EvidenceReducer::class)->reduce($work)->disposition;
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::RecoveryRequired, $e->problem);
            $barrier->await('applied', 1, 30_000);
            $result = $app->make(EvidenceReducer::class)->reduce($work)->disposition;
        }
        if ($result === ReductionDisposition::Applied) {
            $barrier->arrive('applied');
        }

        return ['role' => $i === 0 ? 'lookup' : 'callback', 'result' => $result->value];
    }

    /** @return array<string,string|int> */
    private static function invoiceWorker(Application $app, string $path, ProcessBarrier $barrier, int $i, string $scenario): array
    {
        if ($i === 0) {
            try {
                $refund = $app->make(RefundCommands::class)->admit(Fixture::scope(), RefundCapacityTest::refund('refund-30', 3000, 8));

                return ['result' => $refund->problem->value ?? 'accepted'];
            } catch (QueryException $failure) {
                self::assertSame('invoice-fault', $scenario);
                self::assertStringContainsString('injected terminal refund admission checkpoint', $failure->getMessage());

                return ['result' => 'admission rolled back'];
            } finally {
                $barrier->arrive('admission');
            }
        }
        if ($i === 1) {
            $barrier->await('admission', 1, 30_000);
            if ($app->make(DatabaseManager::class)->connection()->table('billium_execution_operation')->where('operation_ref', 'refund-30')->doesntExist()) {
                self::assertSame(0, OutcomeDeliveryTest::relay($app, $path)->claimed);

                return ['result' => 'no observation'];
            }
            self::dispatchAndDeliver($app, $path, 'refund-30');

            return ['result' => 'delivered'];
        }
        if ($i === 2) {
            $allocation = $app->make(Ledger::class)->allocate(self::allocation('racing-allocation'));

            return ['result' => $allocation->problem->value ?? 'accepted'];
        }
        $db = $app->make(DatabaseManager::class)->connection();
        $db->enableQueryLog();
        $read = $app->make(LedgerQueries::class)->refundAuthorization('root-a', self::money(3000));
        $writes = 0;
        foreach ($db->getQueryLog() as $entry) {
            if (preg_match('/\b(insert|update|delete|merge|truncate|nextval|setval)\b/i', self::text($entry['query'])) === 1) {
                $writes++;
            }
        }
        self::assertTrue($read['advisory'] === true);
        $barrier->arrive('advisory-read');

        return ['result' => 'advisory', 'writes' => $writes, 'capacity' => Money::fromWire($read['normalCapacity'])->minor];
    }
}

<?php

declare(strict_types=1);

namespace Billium\Rating\Tests;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Rating\Tests\Support\LedgerFault;
use Billium\Rating\Tests\Support\RunnerSpy;
use Illuminate\Database\QueryException;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
final class RatingBookingParticipantAtomicityTest extends RatingTestCase
{
    public function test_failure_after_real_ledger_participant_rolls_back_basis_obligation_receipt_and_outbox(): void
    {
        $prepared = $this->prepared();
        $port = new LedgerFault($this->application()->make(LedgerParticipants::class));
        $this->application()->instance(LedgerParticipants::class, $port);
        $baseline = $this->ratingCounts();
        $receipts = $this->rows('billium_idempotency_receipt');
        $outbox = $this->rows('billium_outbox_event');
        try {
            $this->book($prepared);
            self::fail('ledger fault did not abort root');
        } catch (RuntimeException $error) {
            self::assertSame('test after successful ledger participant', $error->getMessage());
        }
        self::assertSame(1, $port->calls);
        self::assertSame($baseline, $this->ratingCounts());
        self::assertSame($receipts, $this->rows('billium_idempotency_receipt'));
        self::assertSame($outbox, $this->rows('billium_outbox_event'));
        self::assertSame('planned', $this->value('SELECT state FROM billium_schedule_billing_occurrence'));
        $port->failure = '';
        $this->book($prepared);
        self::assertSame(1, $this->rows('billium_obligation'));
    }

    public function test_occurrence_frontier_and_outbox_faults_leave_all_financial_rows_absent(): void
    {
        $prepared = $this->prepared();
        $before = $this->ratingCounts();
        foreach ([
            ['billium_schedule_billing_occurrence', 'AFTER UPDATE'],
            ['billium_rating_source_frontier', 'AFTER UPDATE'],
            ['billium_outbox_event', 'AFTER INSERT'],
            ['billium_rating_event', 'AFTER INSERT'],
        ] as [$table, $timing]) {
            $this->owner()->unprepared("CREATE FUNCTION public.billium_test_rating_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'test rating fault'; END $$");
            $this->owner()->unprepared("CREATE TRIGGER billium_test_rating_fault {$timing} ON {$table} FOR EACH ROW EXECUTE FUNCTION public.billium_test_rating_fault()");
            try {
                $this->book(prepared: $prepared, key: 'fault-'.$table);
                self::fail('fault did not roll back');
            } catch (QueryException $error) {
                self::assertStringContainsString('test rating fault', $error->getMessage());
            } finally {
                $this->owner()->unprepared("DROP TRIGGER billium_test_rating_fault ON {$table}; DROP FUNCTION public.billium_test_rating_fault()");
            }
            self::assertSame($before, $this->ratingCounts());
            self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind='rating.book.v1'"));
            self::assertSame(0, $this->rows('billium_outbox_event', "type='obligation.created.v1'"));
        }
    }

    public function test_stored_ledger_refusal_keeps_only_the_outer_business_refusal(): void
    {
        $prepared = $this->prepared();
        $port = new LedgerFault(inner: $this->application()->make(LedgerParticipants::class), failure: 'refusal');
        $this->application()->instance(LedgerParticipants::class, $port);
        try {
            $this->book(prepared: $prepared, key: 'refused');
            self::fail('business refusal did not propagate');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::PolicyUnknown, $error->problem);
        }
        self::assertSame(0, array_sum($this->ratingCounts()));
        self::assertSame('refused', $this->value("SELECT outcome FROM billium_idempotency_receipt WHERE command_key='refused'"));
        self::assertSame(0, $this->rows('billium_outbox_event', "type='obligation.created.v1'"));
    }

    public function test_frontier_cas_zero_rows_rolls_back_the_completed_ledger_participant(): void
    {
        $prepared = $this->prepared();
        $this->owner()->unprepared('CREATE FUNCTION public.billium_test_rating_cas() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NULL; END $$');
        $this->owner()->unprepared('CREATE TRIGGER billium_test_rating_cas BEFORE UPDATE ON billium_rating_source_frontier FOR EACH ROW EXECUTE FUNCTION public.billium_test_rating_cas()');
        try {
            $this->book($prepared);
            self::fail('lost CAS accepted');
        } catch (ProblemException $error) {
            self::assertSame('billium.rating.frozen_input_stale', $error->detail);
        } finally {
            $this->owner()->unprepared('DROP TRIGGER billium_test_rating_cas ON billium_rating_source_frontier; DROP FUNCTION public.billium_test_rating_cas()');
        }
        self::assertSame(0, array_sum($this->ratingCounts()));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind='rating.book.v1'"));
    }

    public function test_retry_uses_a_fresh_context_same_basis_and_exactly_one_root_call(): void
    {
        $prepared = $this->prepared();
        $spy = new RunnerSpy($this->runner());
        $this->application()->instance(OperationRunner::class, $spy);
        $port = new LedgerFault(inner: $this->application()->make(LedgerParticipants::class), failure: 'retry');
        $this->application()->instance(LedgerParticipants::class, $port);
        $receipt = $this->book($prepared);
        self::assertSame(1, $spy->roots);
        self::assertCount(2, $port->contexts);
        self::assertNotSame($port->contexts[0], $port->contexts[1]);
        self::assertSame([1, 2], array_map(static fn ($ctx): int => $ctx->attempt(), $port->contexts));
        self::assertSame($prepared->draft->basisRef, $receipt->basisRef);
        self::assertSame(1, $this->rows('billium_obligation'));
        self::assertFalse($port->contexts[0]->isActive());
        self::assertFalse($port->contexts[1]->isActive());
    }

    public function test_forged_stale_and_foreign_contexts_refuse_before_the_first_write(): void
    {
        $prepared = $this->prepared();
        $real = $this->runner();
        $spy = new RunnerSpy($real);
        $this->application()->instance(OperationRunner::class, $spy);
        $this->book($prepared);
        $stale = $spy->contexts[0];
        $forged = $this->createStub(TransactionContext::class);
        foreach ([$forged, $stale] as $context) {
            $spy->replacement = $context;
            $spy->assertions = [];
            $before = $this->ratingCounts();
            try {
                $this->book(prepared: $prepared, key: 'invalid-context');
                self::fail('invalid context accepted');
            } catch (LogicException) {
                self::assertSame(['rating'], $spy->assertions);
            }
            self::assertSame($before, $this->ratingCounts());
        }
        // A genuinely live capability is foreign to another runner, before any module write.
        $spy->replacement = null;
        $spy->assertions = [];
        $spy->assertionAuthority = clone $real;
        $before = $this->ratingCounts();
        try {
            $this->book(prepared: $prepared, key: 'foreign-context');
            self::fail('foreign runner accepted context');
        } catch (LogicException) {
            self::assertSame(['rating'], $spy->assertions);
        }
        self::assertSame($before, $this->ratingCounts());
    }

    public function test_every_successful_effect_and_outer_commit_share_one_database_transaction(): void
    {
        $prepared = $this->prepared();
        $tables = ['billium_rating_run', 'billium_rating_rated_basis', 'billium_rating_rated_line', 'billium_rating_booking',
            'billium_rating_source_frontier', 'billium_rating_event', 'billium_obligation', 'billium_outbox_event', 'billium_idempotency_receipt', 'billium_money_commit_log'];
        $this->owner()->unprepared(<<<'SQL'
            CREATE FUNCTION public.billium_test_rating_tx() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN INSERT INTO public.billium_test_effect(seller_ref,environment,ref,note)
                VALUES('seller-a','test',TG_TABLE_NAME,txid_current()::text); RETURN NEW; END $$;
            SQL);
        foreach ($tables as $table) {
            $this->owner()->unprepared("CREATE TRIGGER billium_test_rating_tx AFTER INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION public.billium_test_rating_tx()");
        }
        try {
            $this->book($prepared);
            self::assertSame(1, $this->value('SELECT count(DISTINCT note) FROM billium_test_effect'));
            foreach ($tables as $table) {
                self::assertGreaterThan(0, $this->rows('billium_test_effect', 'ref=?', [$table]), $table);
            }
            self::assertSame(1, $this->rows('billium_money_commit_log', "kind='rating.book.v1'"));
        } finally {
            foreach ($tables as $table) {
                $this->owner()->unprepared("DROP TRIGGER billium_test_rating_tx ON {$table}");
            }
            $this->owner()->unprepared('DROP FUNCTION public.billium_test_rating_tx()');
        }
    }
}

<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Commands;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\Receipt;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Data\Obligations\AdjustObligation;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
final class ObligationCommandsTest extends LedgerTestCase
{
    public function test_record_and_business_basis_replay_freeze_terms_and_emit_one_event(): void
    {
        $make = fn (string $key, int $amount = 1000) => new RecordObligation($key, $this->account(), 'o', 'basis', 'customer', 'component', Money::parse(($amount === 1000 ? '10.00' : '11.00'), 'RUB'), self::digest());
        $first = $this->ledger()->recordObligation($make('one'));
        $replay = $this->ledger()->recordObligation($make('one'));
        $basis = $this->ledger()->recordObligation($make('another-key'));
        self::assertSame($first->body, $replay->body);
        self::assertTrue($replay->replayed);
        self::assertSame($first->body, $basis->body);
        self::assertSame(1, $this->rows('billium_obligation'));
        self::assertSame(1, $this->rows('billium_outbox_event', "type='obligation.created.v1'"));
        foreach (['one', 'different-key'] as $key) {
            try {
                $this->ledger()->recordObligation($make($key, 1100));
                self::fail('changed payload conflicts');
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
            }
        }
        self::assertSame(1000, self::integer($this->value('SELECT amount_due_minor FROM billium_obligation')));
    }

    public function test_signed_adjustment_journal_replay_and_decrease_budget(): void
    {
        $o = $this->obligation();
        $c = $this->credit(amount: 800);
        $this->allocate($o, null, $c, 800);
        $command = new AdjustObligation('adjust-key', $this->account(), 'adjust-1', 'obligation-a', Money::parse('-2.00', 'RUB'), 'correction');
        $first = $this->ledger()->adjustObligation($command);
        self::assertTrue($first->accepted);
        self::assertSame($first->body, $this->ledger()->adjustObligation($command)->body);
        self::assertSame(200, self::integer($this->value('SELECT amount_minor FROM billium_adjustment')));
        self::assertSame('decrease', $this->value('SELECT kind FROM billium_adjustment'));
        $refused = $this->ledger()->adjustObligation(new AdjustObligation('exceeds-key', $this->account(), 'adjust-2', 'obligation-a', Money::parse('-0.01', 'RUB'), 'correction'));
        self::assertSame(ProblemCode::AllocationExceedsOpen, $refused->problem);
        self::assertTrue($this->ledger()->adjustObligation(new AdjustObligation('exceeds-key', $this->account(), 'adjust-2', 'obligation-a', Money::parse('-0.01', 'RUB'), 'correction'))->replayed);
        self::assertSame(1, $this->rows('billium_adjustment'));
        self::assertSame(1, $this->rows('billium_outbox_event', "type='obligation.adjusted.v1'"));
    }

    public function test_billed_non_draft_invoice_refuses_adjustment_with_saved_receipt(): void
    {
        $o = $this->obligation();
        $this->invoice($o);
        $cmd = new AdjustObligation('frozen-key', $this->account(), 'adjust', 'obligation-a', Money::parse('1.00', 'RUB'), 'correction');
        self::assertSame(ProblemCode::InvoiceFrozen, $this->ledger()->adjustObligation($cmd)->problem);
        self::assertTrue($this->ledger()->adjustObligation($cmd)->replayed);
        self::assertSame(0, $this->rows('billium_adjustment'));
    }

    public function test_problem_code_in_a_binding_cannot_change_a_stored_database_refusal(): void
    {
        $obligation = $this->obligation();
        $credit = $this->credit(amount: 800);
        $this->allocate($obligation, null, $credit, 800);
        $command = new AdjustObligation('bound-problem-key', $this->account(), 'bound-problem-adjustment', 'obligation-a', Money::parse('-3.00', 'RUB'), ProblemCode::ValidationInvalid->value);

        $refused = $this->ledger()->adjustObligation($command);
        self::assertFalse($refused->accepted);
        self::assertFalse($refused->replayed);
        self::assertSame(ProblemCode::AllocationExceedsOpen, $refused->problem);
        self::assertSame(0, $this->rows('billium_adjustment'));
        self::assertSame(0, $this->rows('billium_outbox_event', "type='obligation.adjusted.v1'"));

        $allocation = self::integer($this->value('SELECT id FROM billium_allocation'));
        $this->allocate($obligation, null, $credit, -800, 'reverse-bound-problem', $allocation);
        self::assertSame(1000, self::integer($this->value('SELECT open_minor FROM billium_obligation')));

        $replayed = $this->ledger()->adjustObligation($command);
        self::assertFalse($replayed->accepted);
        self::assertTrue($replayed->replayed);
        self::assertSame(ProblemCode::AllocationExceedsOpen, $replayed->problem);
        self::assertSame($refused->body, $replayed->body);
        self::assertSame(0, $this->rows('billium_adjustment'));

        // The changed budget permits a new command, proving the original key replays a saved refusal.
        $accepted = $this->ledger()->adjustObligation(new AdjustObligation('fresh-bound-problem-key', $this->account(), 'bound-problem-adjustment', 'obligation-a', Money::parse('-3.00', 'RUB'), ProblemCode::ValidationInvalid->value));
        self::assertTrue($accepted->accepted);
        self::assertSame(1, $this->rows('billium_adjustment'));
        self::assertSame(1, $this->rows('billium_outbox_event', "type='obligation.adjusted.v1'"));
    }

    public function test_participant_is_atomic_and_refuses_stale_context_and_nested_public_root(): void
    {
        $port = $this->application()->make(LedgerParticipants::class);
        $cmd = new RecordObligation('participant', $this->account(), 'p', 'p-basis', 'customer', 'component', Money::parse('1.00', 'RUB'), self::digest());
        $saved = null;
        try {
            $this->runner()->run($this->spec(), function (TransactionContext $ctx) use ($port, $cmd, &$saved): Receipt {
                $saved = $ctx;
                $port->recordObligation($ctx, $cmd);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $e) {
            self::assertSame('rollback', $e->getMessage());
        }
        self::assertSame(0, $this->rows('billium_obligation'));
        self::assertSame(0, $this->rows('billium_outbox_event'));
        self::assertNotNull($saved);
        try {
            $port->recordObligation($saved, $cmd);
            self::fail('stale context');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(LogicException::class);
        $this->runner()->run($this->spec(), function () use ($cmd): Receipt {
            $this->ledger()->recordObligation($cmd);

            return self::receipt();
        });
    }
}

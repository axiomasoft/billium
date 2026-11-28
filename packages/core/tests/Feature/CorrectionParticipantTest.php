<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Feature;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Exceptions\Runner\LockOrderViolation;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Data\Invoices\CreditNoteLine;
use Billium\Ledger\Data\Invoices\CreditNoteMode;
use Billium\Ledger\Data\Invoices\IssueCreditNote;
use Billium\Ledger\Data\Obligations\AdjustObligation;
use Billium\Ledger\Data\Obligations\CorrectionBasis;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Tests\Commands\InvoiceFixtures;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Corrections compose through typed ledger participants in ONE root context and LockSet.
 * A draft obligation is adjusted; a finalized one is never overwritten: a positive late delta is a separate linked
 * obligation with a basis derived from the original basis and the correction identity/revision, a negative one is a
 * credit note on the issued invoice.
 */
#[Group('D63')]
#[Group('F01')]
#[Group('pg')]
final class CorrectionParticipantTest extends LedgerTestCase
{
    use InvoiceFixtures;

    private function port(): LedgerParticipants
    {
        return $this->application()->make(LedgerParticipants::class);
    }

    /** Finalized o-final on issued invoice-a; o-draft on draft invoice-b. */
    private function fixture(): void
    {
        $this->obligation('o-final', 10000);
        $this->draft(['o-final' => 0]);
        $this->issue();
        $this->obligation('o-draft', 5000);
        $this->draft(['o-draft' => 0], 'invoice-b');
    }

    private function positive(int $revision, string $key = 'corr-positive'): RecordObligation
    {
        return new RecordObligation($key, $this->account(), 'o-final+corr-1@'.$revision, CorrectionBasis::derive('basis-o-final', 'corr-1', $revision),
            'customer-a', 'component-a', new Money(1500, Currency::of('RUB')), self::digest('e'));
    }

    private function negative(): IssueCreditNote
    {
        return new IssueCreditNote('corr-negative', $this->account(), 'corr-1-negative', 'invoice-a', CreditNoteMode::OpenOnly,
            [new CreditNoteLine('o-final', Money::parse('25.00', 'RUB'))], 'late negative delta');
    }

    private function adjust(): AdjustObligation
    {
        return new AdjustObligation('corr-draft', $this->account(), 'corr-1-draft', 'o-draft', Money::parse('-10.00', 'RUB'), 'late negative delta');
    }

    /** Root plan of the correction: every obligation (70) and invoice (80) it touches, ids ascending. */
    private function prelock(TransactionContext $context): void
    {
        $obligations = array_map(static fn (array $r): int => self::integer($r['id']), $this->table("SELECT id FROM billium_obligation WHERE obligation_ref IN ('o-final','o-draft')"));
        $invoices = array_map(static fn (array $r): int => self::integer($r['id']), $this->table('SELECT id FROM billium_invoice'));
        $context->lock(
            new LockRequest(LockRank::Obligation, 'billium_obligation', $obligations, guardedRoutine: 'billium_lock_obligation'),
            new LockRequest(LockRank::Invoice, 'billium_invoice', $invoices, guardedRoutine: 'billium_lock_invoice'),
        );
    }

    public function test_one_root_composes_draft_adjustment_positive_correction_and_credit_note_without_overwriting(): void
    {
        $this->fixture();
        $original = $this->row("SELECT * FROM billium_obligation WHERE obligation_ref='o-final'");
        $outcome = $this->runner()->run($this->spec(key: 'k-correction'), function (TransactionContext $context): Receipt {
            $this->prelock($context);
            $adjusted = $this->port()->adjustObligation($context, $this->adjust());
            $created = $this->port()->recordObligation($context, $this->positive(1));
            $credited = $this->port()->issueCreditNote($context, $this->negative());

            return self::receipt(['adjusted' => $adjusted->body(), 'created' => $created->body(), 'credited' => $credited->body()]);
        });
        self::assertTrue($outcome->accepted);
        self::assertSame(4000, self::integer($this->value("SELECT amount_due_minor + adjusted_minor FROM billium_obligation WHERE obligation_ref='o-draft'")));
        $correction = $this->row("SELECT basis_ref,amount_due_minor,billed_invoice_id FROM billium_obligation WHERE obligation_ref='o-final+corr-1@1'");
        self::assertSame([CorrectionBasis::derive('basis-o-final', 'corr-1', 1), 1500, null], [$correction['basis_ref'], self::integer($correction['amount_due_minor']), $correction['billed_invoice_id']]);
        $after = $this->row("SELECT * FROM billium_obligation WHERE obligation_ref='o-final'");
        foreach (['amount_due_minor', 'adjusted_minor', 'basis_ref', 'terms_digest', 'semantic_digest', 'allocated_minor', 'billed_invoice_id'] as $column) {
            self::assertSame($original[$column], $after[$column], $column.' of the finalized obligation is never overwritten');
        }
        self::assertSame(2500, self::integer($after['credited_minor']));
        self::assertSame('partially_paid', $this->invoiceStatus());
        self::assertSame(1, $this->rows('billium_idempotency_receipt', "command_key='k-correction'"));

        // The same correction in a later root replays; another revision of it is another linked obligation.
        $replay = $this->runner()->run($this->spec(key: 'k-correction-replay'), fn (TransactionContext $context): Receipt => $this->port()->recordObligation($context, $this->positive(1, 'corr-positive-2')));
        self::assertSame($outcome->body['created'] ?? null, $replay->body);
        self::assertSame(1, $this->rows('billium_obligation', "obligation_ref LIKE 'o-final+%'"));
        $this->runner()->run($this->spec(key: 'k-correction-r2'), fn (TransactionContext $context): Receipt => $this->port()->recordObligation($context, $this->positive(2, 'corr-positive-r2')));
        self::assertSame(2, $this->rows('billium_obligation', "obligation_ref LIKE 'o-final+%'"));
        self::assertNotSame(CorrectionBasis::derive('basis-o-final', 'corr-1', 1), CorrectionBasis::derive('basis-o-final', 'corr-1', 2));
    }

    public function test_a_finalized_obligation_refuses_adjustment_and_a_failing_root_rolls_every_participant_back(): void
    {
        $this->fixture();
        $frozen = $this->runner()->run($this->spec(key: 'k-frozen'), fn (TransactionContext $context): Receipt => $this->port()->adjustObligation($context,
            new AdjustObligation('corr-final', $this->account(), 'corr-final', 'o-final', Money::parse('-1.00', 'RUB'), 'late delta')));
        self::assertSame(ProblemCode::InvoiceFrozen, $frozen->problem);
        try {
            $this->runner()->run($this->spec(key: 'k-fail'), function (TransactionContext $context): Receipt {
                $this->prelock($context);
                $this->port()->adjustObligation($context, $this->adjust());
                $this->port()->recordObligation($context, $this->positive(1));
                $this->port()->issueCreditNote($context, $this->negative());
                throw new RuntimeException('tax snapshot refused');
            });
            self::fail('root fails');
        } catch (RuntimeException $e) {
            self::assertSame('tax snapshot refused', $e->getMessage());
        }
        self::assertSame(0, $this->rows('billium_adjustment'));
        self::assertSame(0, $this->rows('billium_credit_note'));
        self::assertSame(0, $this->rows('billium_obligation', "obligation_ref LIKE 'o-final+%'"));
        self::assertSame('issued', $this->invoiceStatus());
    }

    public function test_participants_share_one_lockset_and_refuse_stale_contexts(): void
    {
        $this->fixture();
        // Without the root plan the credit note (obligation rank 70) comes after the adjustment already took invoice-b (80).
        try {
            $this->runner()->run($this->spec(key: 'k-unplanned'), function (TransactionContext $context): Receipt {
                $this->port()->adjustObligation($context, $this->adjust());
                $this->port()->issueCreditNote($context, $this->negative());

                return self::receipt();
            });
            self::fail('lock order');
        } catch (LockOrderViolation) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(0, $this->rows('billium_adjustment'));
        $saved = null;
        $this->runner()->run($this->spec(key: 'k-saved'), static function (TransactionContext $context) use (&$saved): Receipt {
            $saved = $context;

            return self::receipt();
        });
        self::assertInstanceOf(TransactionContext::class, $saved);
        foreach (['credit note' => fn () => $this->port()->issueCreditNote($saved, $this->negative()), 'record' => fn () => $this->port()->recordObligation($saved, $this->positive(1))] as $case => $call) {
            try {
                $call();
                self::fail($case);
            } catch (LogicException $e) {
                self::assertStringContainsString('Stale or foreign TransactionContext', $e->getMessage(), $case);
            }
        }
        self::assertSame(0, $this->rows('billium_credit_note'));
    }
}

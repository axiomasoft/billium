<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\LockRequest;
use Billium\Core\Data\Receipt;
use Billium\Core\Enums\FinancialMode;
use Billium\Core\Enums\LockRank;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Exceptions\LockOrderViolation;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Data\AdjustObligation;
use Billium\Ledger\Data\BillingAccountScope;
use Billium\Ledger\Data\CloseSettlementIntentNoEffect;
use Billium\Ledger\Data\CreditNoteLine;
use Billium\Ledger\Data\CreditNoteMode;
use Billium\Ledger\Data\DeclareSettlementIntent;
use Billium\Ledger\Data\IssueCreditNote;
use Billium\Ledger\Data\IssueInvoice;
use Billium\Ledger\Data\NoEffectProof;
use Billium\Ledger\Data\RecordObligation;
use Billium\Ledger\Tests\Commands\InvoiceFixtures;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/** D49/D60: the composed issue (P6 supplies the legal reference) is the same guarded routine in the caller's root Tx. */
final class InvoiceParticipantTest extends LedgerTestCase
{
    use InvoiceFixtures;

    private function command(string $key = 'participant'): IssueInvoice
    {
        return new IssueInvoice($key, $this->account(), 'invoice-a');
    }

    private function port(): LedgerParticipants
    {
        return $this->application()->make(LedgerParticipants::class);
    }

    /** @return iterable<string, array{string, FinancialMode}> */
    public static function nonMonetaryRoots(): iterable
    {
        foreach (['recordObligation', 'adjustObligation', 'declareSettlementIntent', 'issueInvoice', 'issueCreditNote', 'closeSettlementIntentNoEffect'] as $participant) {
            yield $participant.' in sealed raw custody' => [$participant, FinancialMode::RawCustody];
            yield $participant.' without commit accounting' => [$participant, FinancialMode::Active];
        }
    }

    #[DataProvider('nonMonetaryRoots')]
    public function test_monetary_participants_require_an_admitted_financial_session_and_commit_accounting(string $participant, FinancialMode $mode): void
    {
        $this->obligation('obligation-a', 1000);
        $this->draft();
        if ($participant === 'issueCreditNote') {
            $this->issue();
        }
        if ($participant === 'closeSettlementIntentNoEffect') {
            $this->ledger()->declareSettlementIntent(new DeclareSettlementIntent('intent', $this->account(), 'intent-a', 'RUB', ['obligation-a']));
        }
        if ($mode === FinancialMode::RawCustody) {
            $this->installation()->seal('seal-1', 1);
        }
        $tables = ['billium_obligation', 'billium_allocation_receipt', 'billium_outbox_event', 'billium_money_commit_log'];
        $before = array_map($this->rows(...), $tables);

        try {
            $this->runner()->run($this->spec(mode: $mode, class: ModuleOperationClass::NewIntent, modules: ['ledger', 'platform'], commit: null),
                fn (TransactionContext $context): Receipt => $this->monetaryParticipant($context, $participant));
            self::fail('a nonmonetary root must not invoke '.$participant);
        } catch (LogicException $exception) {
            self::assertSame('Monetary ledger operations require an admitted financial session and commit accounting (D54, D60, D62)', $exception->getMessage());
        }

        self::assertSame($before, array_map($this->rows(...), $tables));
    }

    private function monetaryParticipant(TransactionContext $context, string $participant): Receipt
    {
        $scope = $this->account();
        $money = new Money(100, Currency::of('RUB'));

        return match ($participant) {
            'recordObligation' => $this->port()->recordObligation($context, new RecordObligation('record', $scope, 'obligation-b', 'basis-b', 'customer-a', 'component-a', $money, self::digest())),
            'adjustObligation' => $this->port()->adjustObligation($context, new AdjustObligation('adjust', $scope, 'adjustment-a', 'obligation-a', $money, 'cause-a')),
            'declareSettlementIntent' => $this->port()->declareSettlementIntent($context, new DeclareSettlementIntent('declare', $scope, 'intent-a', 'RUB', ['obligation-a'])),
            'issueInvoice' => $this->port()->issueInvoice($context, $this->command(), 'LEGAL-1'),
            'issueCreditNote' => $this->port()->issueCreditNote($context, new IssueCreditNote('credit', $scope, 'credit-note-a', 'invoice-a', CreditNoteMode::OpenOnly, [new CreditNoteLine('obligation-a', $money)], 'reason-a')),
            'closeSettlementIntentNoEffect' => $this->port()->closeSettlementIntentNoEffect($context, new CloseSettlementIntentNoEffect($scope, 'intent-a', 1, 'step-a', 'closure-a', 'RUB', 'cause-a',
                new NoEffectProof($context->rootRef(), 'intent-a', 1, 'step-a', 'not_admitted', 'evidence-a'))),
            default => throw new RuntimeException('Unknown participant '.$participant),
        };
    }

    public function test_composed_issue_sets_the_legal_reference_once_in_the_root_tx_and_replays(): void
    {
        $this->obligation('obligation-a', 1000);
        $this->draft();
        $outcome = $this->runner()->run($this->spec(key: 'k-compose'), fn (TransactionContext $context): Receipt => $this->port()->issueInvoice($context, $this->command(), 'LEGAL-1'));
        self::assertTrue($outcome->accepted);
        self::assertSame('issued', $this->invoiceStatus());
        self::assertSame('LEGAL-1', $this->invoiceRow()['legal_document_ref']);
        self::assertSame('LEGAL-1', $outcome->body['legal_document_ref'] ?? null);
        $payload = json_decode(self::text($this->value("SELECT payload FROM billium_outbox_event WHERE type='invoice.issued.v1'")), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('LEGAL-1', $payload['legal_document_ref'] ?? null);

        // A lost reply: the same composed command in a new root returns the same receipt and the same single event.
        $again = $this->runner()->run($this->spec(key: 'k-compose-2'), fn (TransactionContext $context): Receipt => $this->port()->issueInvoice($context, $this->command('participant-2'), 'LEGAL-1'));
        self::assertSame($outcome->body, $again->body);
        self::assertSame(1, $this->rows('billium_outbox_event', "type='invoice.issued.v1'"));
        // A different number for the same issued invoice is a different command and conflicts; the number never changes.
        try {
            $this->runner()->run($this->spec(key: 'k-compose-3'), fn (TransactionContext $context): Receipt => $this->port()->issueInvoice($context, $this->command('participant-3'), 'LEGAL-2'));
            self::fail('a changed legal reference conflicts');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
        }
        self::assertSame('LEGAL-1', $this->invoiceRow()['legal_document_ref']);
    }

    public function test_the_public_root_issue_leaves_the_number_to_p6_and_its_receipt_differs_from_a_composed_one(): void
    {
        $this->obligation('obligation-a', 1000);
        $this->draft();
        self::assertTrue($this->issue()->accepted);
        self::assertNull($this->invoiceRow()['legal_document_ref']);
        try {
            $this->runner()->run($this->spec(key: 'k-late-number'), fn (TransactionContext $context): Receipt => $this->port()->issueInvoice($context, $this->command(), 'LEGAL-1'));
            self::fail('a number cannot be added to an issued invoice');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
        }
        self::assertNull($this->invoiceRow()['legal_document_ref']);
    }

    public function test_a_later_failure_of_the_root_rolls_the_issue_back_completely(): void
    {
        $this->obligation('obligation-a', 1000);
        $this->draft();
        try {
            $this->runner()->run($this->spec(key: 'k-rollback'), function (TransactionContext $context): Receipt {
                $this->port()->issueInvoice($context, $this->command(), 'LEGAL-1');
                throw new RuntimeException('numbering failed after the issue');
            });
            self::fail('root must fail');
        } catch (RuntimeException $e) {
            self::assertSame('numbering failed after the issue', $e->getMessage());
        }
        $row = $this->invoiceRow();
        self::assertSame(['draft', null, null], [$row['status'], $row['issued_at'], $row['legal_document_ref']]);
        self::assertSame(0, $this->rows('billium_outbox_event', "type='invoice.issued.v1'"));
        self::assertSame(0, $this->rows('billium_allocation_receipt', "kind='invoice_issue'"));
        self::assertSame(1, $this->rows('billium_obligation', 'billed_invoice_id='.self::integer($row['id'])));
    }

    public function test_context_must_be_current_nested_public_roots_throw_and_locks_follow_the_global_order(): void
    {
        $this->obligation('obligation-a', 1000);
        $this->draft();
        $saved = null;
        $this->runner()->run($this->spec(key: 'k-saved'), function (TransactionContext $context) use (&$saved): Receipt {
            $saved = $context;

            return self::receipt();
        });
        self::assertInstanceOf(TransactionContext::class, $saved);
        try {
            $this->port()->issueInvoice($saved, $this->command(), 'LEGAL-1');
            self::fail('stale context');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->runner()->run($this->spec(key: 'k-nested'), function (): Receipt {
                $this->ledger()->issueInvoice($this->command());

                return self::receipt();
            });
            self::fail('a public root command cannot nest');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        // The invoice (rank 80) is locked before the obligations (rank 70) it needs: refused, nothing issued.
        $invoice = self::integer($this->invoiceRow()['id']);
        try {
            $this->runner()->run($this->spec(key: 'k-order'), function (TransactionContext $context) use ($invoice): Receipt {
                $context->lock(new LockRequest(LockRank::Invoice, 'billium_invoice', [$invoice], guardedRoutine: 'billium_lock_invoice'));
                $this->port()->issueInvoice($context, $this->command(), 'LEGAL-1');

                return self::receipt();
            });
            self::fail('lock order');
        } catch (LockOrderViolation) {
            $this->addToAssertionCount(1);
        }
        self::assertSame('draft', $this->invoiceStatus());
        self::assertSame(0, $this->rows('billium_outbox_event', "type='invoice.issued.v1'"));
    }

    public function test_participant_scope_must_match_the_caller(): void
    {
        $this->obligation('obligation-a', 1000);
        $this->draft();
        $foreign = new IssueInvoice('foreign', new BillingAccountScope('seller-b', 'test', 'account-a'), 'invoice-a');
        try {
            $this->runner()->run($this->spec(key: 'k-foreign'), fn (TransactionContext $context): Receipt => $this->port()->issueInvoice($context, $foreign, 'LEGAL-1'));
            self::fail('foreign seller');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::AuthDenied, $e->problem);
        }
        try {
            $this->runner()->run($this->spec(key: 'k-empty-ref'), fn (TransactionContext $context): Receipt => $this->port()->issueInvoice($context, $this->command(), ''));
            self::fail('empty legal reference');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }
        self::assertSame('draft', $this->invoiceStatus());
    }
}

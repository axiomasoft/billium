<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Runner\LockOrderViolation;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Data\Invoices\CreditNoteLine;
use Billium\Ledger\Data\Invoices\CreditNoteMode;
use Billium\Ledger\Data\Invoices\IssueCreditNote;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Obligations\AdjustObligation;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\CloseSettlementIntentNoEffect;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Ledger\Data\Settlements\NoEffectProof;
use Billium\Ledger\Internal\Observation\ObservationHandler;
use Billium\Ledger\Internal\Observation\PaymentProjection;
use Billium\Ledger\Internal\Observation\RefundReconciler;
use Billium\Ledger\Tests\Commands\InvoiceFixtures;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use Billium\Platform\Contracts\Outbox\CommandOutbox;
use Billium\Platform\Contracts\Outbox\Outbox;
use Billium\Platform\Data\EventContracts\PreparedInternalCommand;
use Billium\Platform\Data\Outbox\OutboxEvent;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/** D49/D60: the composed issue (P6 supplies the legal reference) is the same guarded routine in the caller's root Tx. */
#[Group('pg')]
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

    public function test_ledger_participants_reject_new_intent_roots_when_financial_session_is_in_recovery(): void
    {
        $this->obligation('obligation-a', 1000);
        $this->draft();
        $this->ledger()->declareSettlementIntent(new DeclareSettlementIntent('intent', $this->account(), 'intent-a', 'RUB', ['obligation-a']));
        SimulatedFinancialAuthorityEvidence::install($this->application())->issueTransition('seal', 'seal-recovery', [1]);
        $this->installation()->seal('seal-recovery', 1);
        SimulatedFinancialAuthorityEvidence::install($this->application())->issueTransition('open', 'sess-recovery-only', ['owner-1', 1, 1, 1, 'evidence:recovery']);
        $this->installation()->openSession('sess-recovery-only', 'owner-1', 1, 1, 1, 'evidence:recovery');
        $this->tokens->set(FixedSessionAdmission::token(self::INSTALLATION, modes: [FinancialMode::Recovery]));
        SimulatedFinancialAuthorityEvidence::admitCurrent($this->application());
        $tables = ['billium_obligation', 'billium_invoice', 'billium_allocation_receipt', 'billium_outbox_event', 'billium_money_commit_log'];
        $before = array_map($this->rows(...), $tables);

        foreach (['recordObligation', 'closeSettlementIntentNoEffect', 'issueInvoice'] as $participant) {
            try {
                $this->runner()->run($this->spec(mode: FinancialMode::Recovery, class: ModuleOperationClass::NewIntent,
                    modules: ['ledger', 'platform'], commit: 'ledger.test_effect'),
                    fn (TransactionContext $context): Receipt => $this->monetaryParticipant($context, $participant));
                self::fail('a recovery root must not invoke '.$participant);
            } catch (LogicException $exception) {
                self::assertSame('Monetary ledger operations require an admitted financial session and commit accounting (D54, D60, D62)', $exception->getMessage());
            }
            self::assertSame($before, array_map($this->rows(...), $tables));
        }
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
            SimulatedFinancialAuthorityEvidence::install($this->application())->issueTransition('seal', 'seal-1', [1]);
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

    /** @return iterable<string, array{string, ModuleOperationClass}> */
    public static function nonMonetaryOperationClasses(): iterable
    {
        foreach (['recordObligation', 'adjustObligation', 'declareSettlementIntent', 'issueInvoice', 'issueCreditNote', 'closeSettlementIntentNoEffect'] as $participant) {
            foreach ([ModuleOperationClass::HistoricalRead, ModuleOperationClass::ReceiptReplay, ModuleOperationClass::CallbackCustody,
                ModuleOperationClass::ObservedFactRecording, ModuleOperationClass::UnknownResolution] as $class) {
                yield $participant.' in '.$class->value => [$participant, $class];
            }
        }
    }

    #[DataProvider('nonMonetaryOperationClasses')]
    public function test_monetary_participants_require_an_admitted_monetary_operation_class(string $participant, ModuleOperationClass $class): void
    {
        $this->obligation('obligation-a', 1000);
        $this->draft();
        if ($participant === 'issueCreditNote') {
            $this->issue();
        }
        if ($participant === 'closeSettlementIntentNoEffect') {
            $this->ledger()->declareSettlementIntent(new DeclareSettlementIntent('intent', $this->account(), 'intent-a', 'RUB', ['obligation-a']));
        }
        // Retired modules admit history and replay roots; neither capability permits a new write.
        if (in_array($class, [ModuleOperationClass::HistoricalRead, ModuleOperationClass::ReceiptReplay], true)) {
            $this->retireLedger();
        }
        $tables = ['billium_obligation', 'billium_allocation_receipt', 'billium_outbox_event', 'billium_money_commit_log'];
        $before = array_map($this->rows(...), $tables);

        try {
            $this->runner()->run($this->spec(mode: FinancialMode::Active, class: $class, modules: ['ledger', 'platform'], commit: 'ledger.test_effect'),
                function (TransactionContext $context) use ($participant): Receipt {
                    self::assertSame(1, $context->sessionEpoch());

                    return $this->monetaryParticipant($context, $participant);
                });
            self::fail('operation class '.$class->value.' must not invoke '.$participant);
        } catch (LogicException $exception) {
            self::assertSame('Monetary ledger operation is not permitted for operation class '.$class->value.' (D55, D60)', $exception->getMessage());
        }

        self::assertSame($before, array_map($this->rows(...), $tables));
    }

    /** @return iterable<string, array{bool, FinancialMode, ModuleOperationClass}> */
    public static function nonWritingOutboxRoots(): iterable
    {
        foreach ([false, true] as $command) {
            $recorder = $command ? 'command' : 'event';
            foreach ([ModuleOperationClass::HistoricalRead, ModuleOperationClass::ReceiptReplay, ModuleOperationClass::CallbackCustody] as $class) {
                yield $recorder.' in '.$class->value => [$command, FinancialMode::Active, $class];
            }
            yield $recorder.' in raw custody' => [$command, FinancialMode::RawCustody, ModuleOperationClass::CallbackCustody];
        }
    }

    #[DataProvider('nonWritingOutboxRoots')]
    public function test_outbox_recording_requires_a_financial_write_class_and_session(bool $command, FinancialMode $mode, ModuleOperationClass $class): void
    {
        if (in_array($class, [ModuleOperationClass::HistoricalRead, ModuleOperationClass::ReceiptReplay], true)) {
            $this->retireLedger();
        }
        if ($mode === FinancialMode::RawCustody) {
            SimulatedFinancialAuthorityEvidence::install($this->application())->issueTransition('seal', 'seal-outbox', [1]);
            $this->installation()->seal('seal-outbox', 1);
        }
        $tables = ['billium_outbox_event', 'billium_outbox_delivery', 'billium_outbox_command', 'billium_outbox_command_delivery', 'billium_money_commit_log'];
        $before = array_map($this->rows(...), $tables);

        try {
            $this->runner()->run($this->spec(mode: $mode, class: $class, modules: ['ledger', 'platform'], commit: 'ledger.test_effect'),
                function (TransactionContext $context) use ($command): Receipt {
                    if ($command) {
                        $this->application()->make(CommandOutbox::class)->record($context,
                            new PreparedInternalCommand('command-a', 'business-a', 'ledger', 'ledger.test', 'billium.command.v1', [], ['default']));
                    } else {
                        $this->application()->make(Outbox::class)->record($context,
                            new OutboxEvent('event-a', 'obligation.created.v1', 'billium.event.v1', 'obligation-a', 1, new DateTimeImmutable('2026-10-28T10:00:00Z'), ['ref' => 'obligation-a']));
                    }

                    return self::receipt();
                });
            self::fail('a nonwriting root must not record outbox work');
        } catch (LogicException $exception) {
            self::assertSame('Outbox recording requires an admitted financial write operation class and session (D54, D55, D60)', $exception->getMessage());
        }

        self::assertSame($before, array_map($this->rows(...), $tables));
    }

    /** @return iterable<string, array{bool, ModuleOperationClass}> */
    public static function nonObservationRoots(): iterable
    {
        foreach (ModuleOperationClass::cases() as $class) {
            if ($class !== ModuleOperationClass::ObservedFactRecording) {
                yield 'projection in '.$class->value => [false, $class];
            }
            if (! in_array($class, [ModuleOperationClass::ObservedFactRecording, ModuleOperationClass::UnknownResolution], true)) {
                yield 'reconciliation in '.$class->value => [true, $class];
            }
        }
    }

    #[DataProvider('nonObservationRoots')]
    public function test_observation_writers_require_their_own_operation_class(bool $reconcile, ModuleOperationClass $class): void
    {
        $tables = ['billium_obligation', 'billium_allocation_receipt', 'billium_outbox_event', 'billium_money_commit_log', 'billium_observed_payment', 'billium_payment'];
        $before = array_map($this->rows(...), $tables);

        try {
            $this->runner()->run($this->spec(class: $class), function (TransactionContext $context) use ($reconcile): Receipt {
                if ($reconcile) {
                    $this->application()->make(RefundReconciler::class)->compare($context, 0);
                } else {
                    $observation = ObservationHandler::decode(ObservationFactory::charge('op-1', 1, 1000)) ?? self::fail('valid observation');
                    $this->application()->make(PaymentProjection::class)->apply($context, $observation);
                }

                return self::receipt();
            });
            self::fail('operation class '.$class->value.' must not invoke an observation writer');
        } catch (LogicException $exception) {
            $prefix = $reconcile ? 'Ledger operation' : 'Monetary ledger operation';
            self::assertSame($prefix.' is not permitted for operation class '.$class->value.' (D55, D60)', $exception->getMessage());
        }

        self::assertSame($before, array_map($this->rows(...), $tables));
    }

    private function retireLedger(): void
    {
        foreach (['draining', 'recovery_only', 'retired'] as $state) {
            self::assertSame(1, $this->owner()->update(
                "UPDATE billium_module_state SET state=?, generation=generation+1, last_transition_ref=? WHERE installation_ref=? AND module_id='ledger'",
                [$state, 'retire-fixture-'.$state, self::INSTALLATION],
            ));
        }
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

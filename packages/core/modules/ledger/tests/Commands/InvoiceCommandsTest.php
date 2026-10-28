<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Commands;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Data\AdjustObligation;
use Billium\Ledger\Data\BillingAccountScope;
use Billium\Ledger\Data\CreateInvoice;
use Billium\Ledger\Data\DiscardInvoice;
use Billium\Ledger\Data\InvoiceLineInput;
use Billium\Ledger\Data\IssueInvoice;
use Billium\Ledger\Data\MarkUncollectible;
use Billium\Ledger\Data\RecordObligation;
use Billium\Ledger\Data\VoidInvoice;
use Billium\Ledger\Internal\InvoiceStatus;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use PDOException;

final class InvoiceCommandsTest extends LedgerTestCase
{
    use InvoiceFixtures;

    public function test_draft_attaches_obligations_numbers_nothing_and_replays_by_business_identity(): void
    {
        $o1 = $this->obligation('o1', 700);
        $o2 = $this->obligation('o2', 300);
        $created = $this->draft(['o1' => 100, 'o2' => 0]);
        self::assertTrue($created->accepted);
        self::assertSame('draft', $created->body['status'] ?? null);
        $row = $this->invoiceRow();
        self::assertSame([1000, 900, 100, 'draft', null, null], [self::integer($row['total_minor']), self::integer($row['subtotal_minor']), self::integer($row['tax_minor']), $row['status'], $row['issued_at'], $row['legal_document_ref']]);
        self::assertSame([[0, $o1, 700, 100], [1, $o2, 300, 0]], array_map(static fn (array $l): array => [self::integer($l['position']), self::integer($l['obligation_id']), self::integer($l['amount_minor']), self::integer($l['tax_minor'])], $this->table('SELECT * FROM billium_invoice_line ORDER BY position')));
        self::assertSame(2, $this->rows('billium_obligation', 'billed_invoice_id='.self::integer($row['id'])));
        self::assertSame(0, $this->rows('billium_outbox_event', "type='invoice.issued.v1'"));

        self::assertSame($created->body, $this->draft(['o1' => 100, 'o2' => 0])->body, 'same key and payload');
        $twin = $this->draft(['o1' => 100, 'o2' => 0], key: 'another-key');
        self::assertSame($created->body, $twin->body, 'same business identity under another key');
        self::assertSame(1, $this->rows('billium_invoice'));
        foreach (['create-invoice-a', 'a-third-key'] as $key) {
            try {
                $this->draft(['o1' => 99, 'o2' => 0], key: $key);
                self::fail('changed lines conflict');
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
            }
        }
        self::assertSame(1, $this->rows('billium_invoice_line', 'position=0'));
    }

    public function test_draft_refusals_leave_nothing_behind(): void
    {
        $this->obligation('o1', 700);
        $this->draft(['o1' => 0]);
        // An obligation that already belongs to an invoice cannot be billed twice (stored business refusal).
        $second = $this->draft(['o1' => 0], 'invoice-b');
        self::assertSame(ProblemCode::InvoiceFrozen, $second->problem);
        self::assertSame(1, $this->rows('billium_invoice'));
        $this->obligation('o2', 100);
        $other = $this->obligation('foreign', 100, 'account-b');
        foreach ([
            'unknown obligation' => [['missing' => 0], ProblemCode::ReferenceUnknown],
            'other account' => [['foreign' => 0], ProblemCode::ReferenceUnknown],
            'tax above the line' => [['o2' => 101], ProblemCode::ValidationInvalid],
        ] as $name => [$lines, $problem]) {
            try {
                $outcome = $this->draft($lines, 'invoice-x', 'x-'.$name);
                self::assertSame($problem, $outcome->problem, $name);
            } catch (ProblemException $e) {
                self::assertSame($problem, $e->problem, $name);
            }
        }
        self::assertGreaterThan(0, $other);
        self::assertSame(1, $this->rows('billium_invoice'));
        self::assertSame(1, $this->rows('billium_invoice_line'));
        foreach ([
            'no lines' => new CreateInvoice('k-empty', $this->account(), 'invoice-y', 'customer-a', []),
            'duplicate line' => new CreateInvoice('k-dup', $this->account(), 'invoice-y', 'customer-a', [new InvoiceLineInput('o2'), new InvoiceLineInput('o2')]),
            'negative tax' => new CreateInvoice('k-neg', $this->account(), 'invoice-y', 'customer-a', [new InvoiceLineInput('o2', -1)]),
            'float in snapshot' => new CreateInvoice('k-float', $this->account(), 'invoice-y', 'customer-a', [new InvoiceLineInput('o2')], ['rate' => 1.5]),
        ] as $name => $command) {
            try {
                $this->ledger()->createInvoice($command);
                self::fail($name);
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::ValidationInvalid, $e->problem, $name);
            }
        }
        self::assertSame(1, $this->rows('billium_invoice'));
    }

    public function test_lines_must_share_one_currency(): void
    {
        $this->obligation('rub', 100);
        $this->ledger()->recordObligation(new RecordObligation('usd-key', $this->account(), 'usd', 'basis-usd', 'customer-a', 'component-a', new Money(100, Currency::of('USD')), self::digest()));
        try {
            $this->draft(['rub' => 0, 'usd' => 0]);
            self::fail('mixed currencies');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::MoneyCurrencyMismatch, $e->problem);
        }
        self::assertSame(0, $this->rows('billium_invoice'));
    }

    public function test_issue_freezes_with_current_amounts_no_legal_number_and_exactly_one_event(): void
    {
        $o = $this->obligation('obligation-a', 1000);
        $this->draft(['obligation-a' => 200]);
        // A draft's obligation may still be adjusted; the issued snapshot takes the amount at issue (Σ lines = total).
        self::assertTrue($this->ledger()->adjustObligation(new AdjustObligation('adj', $this->account(), 'adj-1', 'obligation-a', Money::parse('2.00', 'RUB'), 'correction'))->accepted);
        $issued = $this->issue();
        self::assertTrue($issued->accepted);
        self::assertSame(['issued', '1200'], [$issued->body['status'] ?? null, $issued->body['total_minor'] ?? null]);
        self::assertArrayHasKey('legal_document_ref', $issued->body);
        self::assertNull($issued->body['legal_document_ref']);
        $row = $this->invoiceRow();
        self::assertSame(['issued', 1200, 1000, 200], [$row['status'], self::integer($row['total_minor']), self::integer($row['subtotal_minor']), self::integer($row['tax_minor'])]);
        self::assertNotNull($row['issued_at']);
        self::assertNull($row['legal_document_ref'], 'D49: no legal number in P3');
        self::assertSame(1200, self::integer($this->value('SELECT sum(amount_minor) FROM billium_invoice_line')));
        self::assertSame($o, self::integer($this->value('SELECT obligation_id FROM billium_invoice_line')));

        $events = $this->table("SELECT * FROM billium_outbox_event WHERE type='invoice.issued.v1'");
        self::assertCount(1, $events);
        self::assertSame('invoice-a', $events[0]['aggregate_ref']);
        self::assertSame(self::integer($row['revision']), self::integer($events[0]['aggregate_revision']));
        $payload = json_decode(self::text($events[0]['payload']), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        ksort($payload);
        self::assertSame(['billing_account_ref' => 'account-a', 'currency' => 'RUB', 'invoice_ref' => 'invoice-a', 'legal_document_ref' => null, 'total_minor' => '1200'], $payload);

        self::assertSame($issued->body, $this->issue()->body, 'same key');
        self::assertSame($issued->body, $this->issue(key: 'issue-again')->body, 'same business identity, other key');
        self::assertSame(1, $this->rows('billium_outbox_event', "type='invoice.issued.v1'"));
        self::assertSame(1, $this->rows('billium_allocation_receipt', "kind='invoice_issue'"));
    }

    public function test_issue_derives_the_payment_status_of_already_settled_lines(): void
    {
        $paid = $this->obligation('paid-line', 1000);
        $this->allocate($paid, null, $this->credit('credit-1', 1000), 1000, 'pay-all');
        $part = $this->obligation('part-line', 1000);
        $this->allocate($part, null, $this->credit('credit-2', 1000), 400, 'pay-part', null);
        $this->draft(['paid-line' => 0], 'invoice-paid');
        $this->draft(['part-line' => 0], 'invoice-part');
        $this->issue('invoice-paid');
        $this->issue('invoice-part');
        foreach (['invoice-paid' => [1000, 1000], 'invoice-part' => [1000, 400]] as $ref => [$total, $settled]) {
            $row = $this->invoiceRow($ref);
            self::assertSame(InvoiceStatus::derive(InvoiceStatus::Issued, $total, $settled)->value, $row['status'], $ref);
            self::assertSame($settled, self::integer($row['allocated_minor']) + self::integer($row['credited_minor']));
        }
        self::assertSame(['paid', 'partially_paid'], [$this->invoiceStatus('invoice-paid'), $this->invoiceStatus('invoice-part')]);
    }

    public function test_status_follows_settled_in_the_allocation_tx_and_matches_the_php_oracle(): void
    {
        $o = $this->obligation('obligation-a', 1000);
        $c = $this->credit('credit-a', 1000);
        $this->draft();
        $this->issue();
        $settled = 0;
        foreach (['a1' => 400, 'a2' => 600] as $receipt => $amount) {
            $this->allocate($o, null, $c, $amount, $receipt);
            $settled += $amount;
            self::assertSame(InvoiceStatus::derive(InvoiceStatus::Issued, 1000, $settled)->value, $this->invoiceStatus(), "after {$receipt}");
        }
        self::assertSame('paid', $this->invoiceStatus());
        foreach (['a2' => 600, 'a1' => 400] as $receipt => $amount) {
            $this->allocate($o, null, $c, -$amount, 'rev-'.$receipt, self::integer($this->value('SELECT id FROM billium_allocation WHERE receipt_ref=?', [$receipt])));
            $settled -= $amount;
            self::assertSame(InvoiceStatus::derive(InvoiceStatus::Issued, 1000, $settled)->value, $this->invoiceStatus(), "after reversal of {$receipt}");
        }
        self::assertSame('issued', $this->invoiceStatus());
    }

    public function test_discard_releases_the_obligations_and_only_a_draft_can_be_discarded(): void
    {
        $this->obligation('obligation-a', 1000);
        $this->draft();
        $discard = new DiscardInvoice('discard', $this->account(), 'invoice-a', 'cause-1');
        $first = $this->ledger()->discardInvoice($discard);
        self::assertTrue($first->accepted);
        self::assertSame('discarded', $first->body['status'] ?? null);
        self::assertSame('discarded', $this->invoiceStatus());
        self::assertSame(0, $this->rows('billium_obligation', 'billed_invoice_id IS NOT NULL'));
        self::assertSame(1, $this->rows('billium_invoice_line'), 'lines remain as history');
        self::assertSame($first->body, $this->ledger()->discardInvoice($discard)->body);
        self::assertSame($first->body, $this->ledger()->discardInvoice(new DiscardInvoice('discard-2', $this->account(), 'invoice-a', 'cause-1'))->body);
        self::assertSame(ProblemCode::InvoiceTransitionInvalid, $this->issue()->problem, 'a discarded invoice is never issued');
        // The released obligation goes into a new invoice, and the old number stays reserved.
        self::assertTrue($this->draft(['obligation-a' => 0], 'invoice-b')->accepted);
        $this->issue('invoice-b');
        $refused = $this->ledger()->discardInvoice(new DiscardInvoice('discard-b', $this->account(), 'invoice-b', 'late'));
        self::assertSame(ProblemCode::InvoiceTransitionInvalid, $refused->problem);
        self::assertTrue($this->ledger()->discardInvoice(new DiscardInvoice('discard-b', $this->account(), 'invoice-b', 'late'))->replayed, 'the refusal is a stored receipt');
        self::assertSame('issued', $this->invoiceStatus('invoice-b'));
    }

    public function test_void_needs_issued_and_nothing_settled_and_keeps_history(): void
    {
        $o = $this->obligation('obligation-a', 1000);
        $draftVoid = new VoidInvoice('void-draft', $this->account(), 'invoice-a', 'cause');
        $this->draft();
        self::assertSame(ProblemCode::InvoiceTransitionInvalid, $this->ledger()->voidInvoice($draftVoid)->problem);
        $this->issue();
        $c = $this->credit('credit-a', 1000);
        $this->allocate($o, null, $c, 100, 'a1');
        self::assertSame(ProblemCode::InvoiceTransitionInvalid, $this->ledger()->voidInvoice(new VoidInvoice('void-settled', $this->account(), 'invoice-a', 'cause'))->problem);
        self::assertSame('partially_paid', $this->invoiceStatus());
        $this->allocate($o, null, $c, -100, 'rev', self::integer($this->value('SELECT id FROM billium_allocation')));
        self::assertSame('issued', $this->invoiceStatus());

        $void = new VoidInvoice('void', $this->account(), 'invoice-a', 'cause');
        $done = $this->ledger()->voidInvoice($void);
        self::assertTrue($done->accepted);
        self::assertSame(['void', 'cause'], [$done->body['status'] ?? null, $done->body['cause_ref'] ?? null]);
        self::assertSame($done->body, $this->ledger()->voidInvoice($void)->body);
        self::assertSame($done->body, $this->ledger()->voidInvoice(new VoidInvoice('void-2', $this->account(), 'invoice-a', 'cause'))->body);
        self::assertSame(1, $this->rows('billium_invoice_line'), 'history stays');
        self::assertSame(0, $this->rows('billium_obligation', 'billed_invoice_id IS NOT NULL'));
        self::assertNotNull($this->invoiceRow()['issued_at']);
        // Number reuse is impossible and a void is final.
        self::assertSame(ProblemCode::InvoiceTransitionInvalid, $this->ledger()->markUncollectible(new MarkUncollectible('unc', $this->account(), 'invoice-a', 'reason', 'auth'))->problem);
        self::assertSame('draft', $this->draft(['obligation-a' => 0], 'invoice-a', 'same-again')->body['status'] ?? null, 'the identical command replays its receipt and attaches nothing');
        self::assertSame(0, $this->rows('billium_obligation', 'billed_invoice_id IS NOT NULL'));
        try {
            $this->draft(['obligation-a' => 5], 'invoice-a', 'reuse');
            self::fail('invoice ref reuse');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
        }
        // Released, the obligation can be reissued under a new invoice.
        $this->draft(['obligation-a' => 0], 'invoice-b');
        self::assertSame('issued', $this->issue('invoice-b')->body['status'] ?? null);
    }

    public function test_uncollectible_records_reason_and_authorization_and_a_late_payment_derives_again(): void
    {
        $o = $this->obligation('obligation-a', 1000);
        $c = $this->credit('credit-a', 1000);
        $this->draft();
        $command = new MarkUncollectible('unc', $this->account(), 'invoice-a', 'bad-debt', 'operator-7');
        self::assertSame(ProblemCode::InvoiceTransitionInvalid, $this->ledger()->markUncollectible($command)->problem, 'a draft cannot be written off');
        $this->issue();
        $done = $this->ledger()->markUncollectible(new MarkUncollectible('unc-2', $this->account(), 'invoice-a', 'bad-debt', 'operator-7'));
        self::assertTrue($done->accepted);
        self::assertSame('uncollectible', $this->invoiceStatus());
        $receipt = json_decode(self::text($this->value("SELECT body FROM billium_allocation_receipt WHERE kind='invoice_uncollectible'")), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($receipt);
        self::assertSame(['bad-debt', 'operator-7'], [$receipt['reason_ref'] ?? null, $receipt['authorization_ref'] ?? null]);
        self::assertSame($done->body, $this->ledger()->markUncollectible(new MarkUncollectible('unc-3', $this->account(), 'invoice-a', 'bad-debt', 'operator-7'))->body);
        try {
            $this->ledger()->markUncollectible(new MarkUncollectible('unc-4', $this->account(), 'invoice-a', 'bad-debt', 'operator-8'));
            self::fail('a different authorization is a different command');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
        }
        self::assertSame(ProblemCode::InvoiceTransitionInvalid, $this->ledger()->voidInvoice(new VoidInvoice('void', $this->account(), 'invoice-a', 'cause'))->problem);

        $this->allocate($o, null, $c, 300, 'late');
        self::assertSame(InvoiceStatus::derive(InvoiceStatus::Uncollectible, 1000, 300)->value, $this->invoiceStatus());
        self::assertSame('partially_paid', $this->invoiceStatus());
    }

    public function test_metadata_allow_list_before_and_after_issue(): void
    {
        $this->obligation('obligation-a', 1000);
        $this->draft();
        self::assertTrue($this->updateMetadata('u1', ['note' => 'draft note'])->accepted);
        try {
            $this->updateMetadata('u2', ['foo' => 'bar']);
            self::fail('a key outside the allow-list');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }
        $this->issue();
        $frozen = $this->updateMetadata('u3', ['note' => 'after issue', 'foo' => 'bar']);
        self::assertSame(ProblemCode::InvoiceFrozen, $frozen->problem);
        self::assertSame(['note' => 'draft note'], json_decode(self::text($this->invoiceRow()['metadata']), true, 8, JSON_THROW_ON_ERROR), 'the refusal changes nothing');
        self::assertTrue($this->updateMetadata('u3', ['note' => 'after issue', 'foo' => 'bar'])->replayed, 'the refusal is a stored receipt');
        $ok = $this->updateMetadata('u4', ['note' => 'after issue', 'labels' => ['vip'], 'external_ref' => 'crm-1']);
        self::assertTrue($ok->accepted);
        self::assertSame(['note' => 'after issue', 'labels' => ['vip'], 'external_ref' => 'crm-1'], json_decode(self::text($this->invoiceRow()['metadata']), true, 8, JSON_THROW_ON_ERROR));
        self::assertSame($ok->body, $this->updateMetadata('u4', ['note' => 'after issue', 'labels' => ['vip'], 'external_ref' => 'crm-1'])->body);
        self::assertSame('issued', $this->invoiceStatus(), 'metadata never changes the status');
        try {
            $this->updateMetadata('u5', []);
            self::fail('metadata is a nonempty object');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }
        // A JSON list is not an object: the guarded routine itself refuses it.
        try {
            $this->routine('update_invoice_metadata', [...$this->scopeArguments(), self::integer($this->invoiceRow()['id']), 'u6', '["a","b"]', self::digest()]);
            self::fail('a list is not an object');
        } catch (PDOException $e) {
            self::assertStringContainsString(ProblemCode::ValidationInvalid->value, $e->getMessage());
        }
    }

    public function test_raw_sql_cannot_change_an_issued_invoice_but_the_runtime_role_cannot_write_at_all(): void
    {
        $this->obligation('obligation-a', 1000);
        $this->draft();
        $owner = TestEnvironment::pdo(owner: true);
        // A draft is not frozen yet, an issued invoice is, for every economic column.
        $this->issue();
        foreach (['total_minor=1001,subtotal_minor=1001', 'tax_minor=1,subtotal_minor=999', "snapshot='{\"buyer\":\"x\"}'", 'issued_at=now()', "legal_document_ref='L-1'",
            "customer_ref='other'", "invoice_ref='renamed'", "currency='USD'", "billing_account_ref='other'"] as $change) {
            [$state, $message] = self::refusal($owner, 'UPDATE billium_invoice SET '.$change);
            self::assertSame('55000', $state, $change);
            self::assertStringContainsString(ProblemCode::InvoiceFrozen->value, $message, $change);
        }
        self::assertSame(['55000', '55000', '55000', '55000'], [
            self::refusal($owner, 'DELETE FROM billium_invoice')[0],
            self::refusal($owner, 'DELETE FROM billium_invoice_line')[0],
            self::refusal($owner, 'UPDATE billium_invoice_line SET amount_minor=amount_minor+1,subtotal_minor=subtotal_minor+1')[0],
            self::refusal($owner, 'INSERT INTO billium_invoice_line(installation_ref,seller_ref,environment,billing_account_ref,currency,invoice_id,obligation_id,position,subtotal_minor,tax_minor,amount_minor) SELECT installation_ref,seller_ref,environment,billing_account_ref,currency,invoice_id,obligation_id,9,0,0,0 FROM billium_invoice_line')[0],
        ]);
        self::assertSame(1000, self::integer($this->invoiceRow()['total_minor']));
        $runtime = TestEnvironment::pdo();
        foreach (['UPDATE billium_invoice SET metadata=metadata', 'INSERT INTO billium_invoice DEFAULT VALUES', 'DELETE FROM billium_invoice', 'UPDATE billium_invoice_line SET position=position', 'UPDATE billium_obligation SET billed_invoice_id=NULL'] as $sql) {
            self::assertSame('42501', self::refusal($runtime, $sql)[0], $sql);
        }
        // Every invoice routine is callable by the runtime role only through EXECUTE, and the replay helper is not.
        foreach (['billium_create_invoice', 'billium_update_invoice_metadata', 'billium_issue_invoice', 'billium_close_invoice', 'billium_mark_invoice_uncollectible'] as $name) {
            self::assertSame(1, $this->rows('pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace', "n.nspname='public' AND p.proname='{$name}' AND p.prosecdef AND has_function_privilege('billium_runtime', p.oid, 'EXECUTE') AND NOT has_function_privilege('public', p.oid, 'EXECUTE')"), $name);
        }
        self::assertSame(0, $this->rows('pg_proc', "proname='billium_invoice_replay' AND has_function_privilege('billium_runtime', oid, 'EXECUTE')"));
    }

    public function test_caller_scope_and_unknown_invoice_are_refused_without_a_receipt(): void
    {
        $this->obligation('obligation-a', 1000);
        $this->draft();
        $foreign = new BillingAccountScope('seller-b', 'test', 'account-a');
        try {
            $this->ledger()->issueInvoice(new IssueInvoice('foreign', $foreign, 'invoice-a'));
            self::fail('foreign seller');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::AuthDenied, $e->problem);
        }
        try {
            $this->ledger()->issueInvoice(new IssueInvoice('unknown', $this->account(), 'nope'));
            self::fail('unknown invoice');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ReferenceUnknown, $e->problem);
        }
        try {
            $this->ledger()->issueInvoice(new IssueInvoice('other-account', $this->account('account-b'), 'invoice-a'));
            self::fail('invoice of another account');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ReferenceUnknown, $e->problem);
        }
        self::assertSame('draft', $this->invoiceStatus());
    }
}

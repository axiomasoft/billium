<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Schema;

use PDOException;
use PHPUnit\Framework\Attributes\Group;

/** Edge cases of the guarded money routines, called directly as the runtime role. */
#[Group('pg')]
final class LedgerRoutineEdgeTest extends LedgerTestCase
{
    public function test_a_missing_detail_total_never_resolves_an_open_refund_basis_mismatch(): void
    {
        $payment = $this->payment();
        $this->observedRevision($this->observedCharge());
        $this->owner()->update('UPDATE billium_payment SET applied_revision=1');
        $recorded = $this->reconcile($payment, 0, 100);
        self::assertTrue(self::arr($recorded['body'] ?? null)['recorded'] ?? null);
        self::assertSame('billium.validation.invalid', $this->refusal(fn (): array => $this->reconcile($payment, 0, null)));
        self::assertSame('billium.validation.invalid', $this->refusal(fn (): array => $this->reconcile($payment, null, 0)));
        self::assertSame(0, $this->rows('billium_discrepancy_event', "kind='resolved'"));
    }

    public function test_a_credit_note_keeps_a_cancelled_obligation_cancelled(): void
    {
        $o = $this->obligation();
        $invoice = $this->invoice($o);
        $this->owner()->update("UPDATE billium_obligation SET status='cancelled',revision=revision+1");
        $this->creditNote($invoice, $o, 1000, 'open_only');
        self::assertSame(1000, self::integer($this->value('SELECT credited_minor FROM billium_obligation')));
        self::assertSame(0, self::integer($this->value('SELECT open_minor FROM billium_obligation')));
        self::assertSame('cancelled', $this->value('SELECT status FROM billium_obligation'));
        self::assertSame('paid', $this->value('SELECT status FROM billium_invoice'));
    }

    public function test_an_adjustment_replay_with_the_most_negative_delta_is_a_conflict_not_an_overflow(): void
    {
        $o = $this->obligation();
        $this->adjust($o, 'adj-a', 100);
        self::assertSame('billium.idempotency.conflict', $this->refusal(fn (): array => $this->adjust($o, 'adj-a', PHP_INT_MIN)));
        self::assertSame('billium.validation.invalid', $this->refusal(fn (): array => $this->adjust($o, 'adj-b', PHP_INT_MIN)));
        self::assertSame(100, self::integer($this->value('SELECT adjusted_minor FROM billium_obligation')));
    }

    public function test_a_reverse_allocations_credit_note_releases_lifo_and_replaces_only_the_overshoot(): void
    {
        $o = $this->obligation();
        $invoice = $this->invoice($o);
        $p = $this->payment();
        $this->allocate($o, $p, null, 300, 'alloc-1');
        $this->allocate($o, $p, null, 500, 'alloc-2');
        // Open is 200; a credit of 800 releases 600: alloc-2 (500) in full, then alloc-1 (300) with 200 placed again.
        $this->creditNote($invoice, $o, 800, 'reverse_allocations');
        self::assertSame(200, self::integer($this->value('SELECT allocated_minor FROM billium_obligation')));
        self::assertSame(800, self::integer($this->value('SELECT credited_minor FROM billium_obligation')));
        self::assertSame(0, self::integer($this->value('SELECT open_minor FROM billium_obligation')));
        self::assertSame('settled', $this->value('SELECT status FROM billium_obligation'));
        self::assertSame(200, self::integer($this->value('SELECT allocated_minor FROM billium_payment')));
        self::assertSame(200, self::integer($this->value('SELECT allocated_minor FROM billium_invoice')));
        self::assertSame('paid', $this->value('SELECT status FROM billium_invoice'));
        self::assertSame(200, self::integer($this->value('SELECT sum(amount_minor) FROM billium_allocation')));
        self::assertSame([-500, -300, 200], array_map(self::integer(...), array_column(
            $this->owner()->select("SELECT amount_minor FROM billium_allocation WHERE receipt_ref='credit-note:cn-a' ORDER BY id"), 'amount_minor')));
    }

    private function observedCharge(): int
    {
        return self::integer($this->value(<<<'SQL'
            INSERT INTO billium_observed_payment(installation_ref,seller_ref,environment,source_authority,source_lineage,operation_ref,kind,revision,material_digest,state,currency,requested_minor,authorized_minor,captured_minor,refunded_minor,voided_minor,provider_account_ref,evidence_ref)
            VALUES('inst-test','seller-a','test','authority-a','lineage-a','operation-a','charge',1,?,'succeeded','RUB',1000,1000,1000,0,0,'merchant','evidence') RETURNING id
            SQL, [self::digest('b')]));
    }

    private function observedRevision(int $observed): void
    {
        $this->owner()->insert(<<<'SQL'
            INSERT INTO billium_observed_payment_revision(installation_ref,seller_ref,environment,observed_payment_id,revision,material_digest,state,
                requested_minor,authorized_minor,captured_minor,refunded_minor,voided_minor,currency,provider_account_ref,evidence_ref,payload)
            VALUES('inst-test','seller-a','test',?,1,?,'succeeded',1000,1000,1000,0,0,'RUB','merchant','evidence','{}')
            SQL, [$observed, self::digest('b')]);
    }

    /** @return array<string,mixed> */
    private function reconcile(int $payment, ?int $chargeRefunded, ?int $detailRefunded): array
    {
        return $this->routine('reconcile_refund_basis', ['inst-test', 'seller-a', 'test', $payment, 1, self::digest('c'), 1, $chargeRefunded, $detailRefunded, 'evidence']);
    }

    /** @return array<string,mixed> */
    private function creditNote(int $invoice, int $obligation, int $amount, string $mode): array
    {
        return $this->routine('issue_credit_note', [...$this->scopeArguments(), $invoice, 'cn-a', $mode,
            json_encode([['obligation_id' => $obligation, 'amount_minor' => $amount]], JSON_THROW_ON_ERROR), 'reason', null, self::digest('d')]);
    }

    /** @return array<string,mixed> */
    private function adjust(int $obligation, string $ref, int $delta): array
    {
        return $this->routine('adjust_obligation', [...$this->scopeArguments(), $ref, $obligation, $delta, 'cause', self::digest('e')]);
    }

    /** @param callable(): array<string,mixed> $call */
    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (PDOException $e) {
            return preg_match('/billium\.[a-z_.]+/', $e->getMessage(), $m) === 1 ? $m[0] : $e->getMessage();
        }
        self::fail('the routine accepted the call');
    }
}

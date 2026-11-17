<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Commands;

use Billium\Contracts\Data\Money;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Allocations\ReallocatePayment;
use Billium\Ledger\Data\Allocations\ReallocationMode;
use Billium\Ledger\Data\Allocations\ReverseAllocation;
use Billium\Ledger\Data\Invoices\CreditNoteLine;
use Billium\Ledger\Data\Invoices\CreditNoteMode;
use Billium\Ledger\Data\Invoices\IssueCreditNote;

/**
 * Allocation-family helpers over the public Ledger root. Payments are owner fixtures: their applied projection belongs to
 * the observation path (P3.9); refunded_minor and the derived flag are assigned exactly as that path assigns them.
 */
trait AllocationFixtures
{
    protected function payWith(string $ref = 'payment-a', int $captured = 10000, string $account = 'account-a', ?int $intent = null, string $seller = 'seller-a'): int
    {
        return self::integer($this->value(<<<'SQL'
            INSERT INTO billium_payment(installation_ref,seller_ref,environment,billing_account_ref,currency,payment_ref,source_authority,source_lineage,operation_ref,kind,captured_minor,settlement_intent_id)
            VALUES('inst-test',?,'test',?,'RUB',?,'authority-a','lineage-a',?,'charge',?,?) RETURNING id
            SQL, [$seller, $account, $ref, 'operation-'.$ref, $captured, $intent]));
    }

    /** A confirmed refund as applied by the observation path: assigned total, flag derived (CHECK). */
    protected function refunded(string $payment, int $refunded): void
    {
        $this->owner()->update('UPDATE billium_payment SET refunded_minor=?,reallocation_required=(allocated_minor::numeric > captured_minor::numeric - ?) WHERE payment_ref=?', [$refunded, $refunded, $payment]);
    }

    protected function allocateFrom(string $allocationRef, string $obligation, string $amount, ?string $payment = 'payment-a', ?string $credit = null, ?string $key = null, string $account = 'account-a'): StoredOutcome
    {
        return $this->ledger()->allocate(new Allocate($key ?? 'alloc-'.$allocationRef, $this->account($account), $allocationRef, $obligation,
            Money::parse($amount, 'RUB'), $credit === null ? $payment : null, $credit));
    }

    protected function reverse(string $reversalRef, string|int $allocationRef, ?string $remainder = null, ?string $key = null): StoredOutcome
    {
        return $this->ledger()->reverseAllocation(new ReverseAllocation($key ?? 'rev-'.$reversalRef, $this->account(), $reversalRef, $allocationRef, 'correction',
            $remainder === null ? null : Money::parse($remainder, 'RUB')));
    }

    /** @param array<string, string> $lines obligationRef => amount */
    protected function creditNote(string $ref, array $lines, CreditNoteMode $mode = CreditNoteMode::OpenOnly, string $invoice = 'invoice-a', ?string $key = null): StoredOutcome
    {
        $inputs = [];
        foreach ($lines as $obligation => $amount) {
            $inputs[] = new CreditNoteLine($obligation, Money::parse($amount, 'RUB'));
        }

        return $this->ledger()->issueCreditNote(new IssueCreditNote($key ?? 'cn-'.$ref, $this->account(), $ref, $invoice, $mode, $inputs, 'goodwill'));
    }

    protected function reallocate(ReallocationMode $mode, string $payment = 'payment-a', ?string $key = null, string $policy = 'refund/1'): StoredOutcome
    {
        return $this->ledger()->reallocatePayment(new ReallocatePayment($key ?? 'realloc-'.$mode->value, 'inst-test', 'seller-a', 'test', $payment, $policy, $mode));
    }

    /** @return array{allocated: int, credited: int, open: int, status: string} */
    protected function obligationState(string $ref): array
    {
        $row = $this->row('SELECT allocated_minor,credited_minor,open_minor,status FROM billium_obligation WHERE obligation_ref=?', [$ref]);

        return ['allocated' => self::integer($row['allocated_minor']), 'credited' => self::integer($row['credited_minor']), 'open' => self::integer($row['open_minor']), 'status' => self::text($row['status'])];
    }

    /** @return array{allocated: int, flag: bool} */
    protected function paymentState(string $ref = 'payment-a'): array
    {
        $row = $this->row('SELECT allocated_minor,reallocation_required FROM billium_payment WHERE payment_ref=?', [$ref]);

        return ['allocated' => self::integer($row['allocated_minor']), 'flag' => $row['reallocation_required'] === true];
    }
}

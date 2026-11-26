<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Receipts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Ledger\Data\Runtime\LedgerReceipt;
use Billium\Ledger\Internal\InvoiceStatus;
use Billium\Ledger\Internal\Repositories\LedgerRepository;
use Billium\Platform\Contracts\Outbox\Outbox;
use Billium\Platform\Data\Outbox\OutboxEvent;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use LogicException;
use Packman\Attributes\Internal;

/**
 * AllocationReceipt boundary (BC-EVT-08, INV-09): the guarded routine writes the immutable receipt together with the
 * journal rows and counters in one statement; PHP never writes a counter, a status or a receipt row. After every change
 * of settled this writer re-reads the locked invoices and checks the DB-derived status against InvoiceStatus::derive,
 * then records the public event of a first (not replayed) application in the same root Tx.
 */
#[Internal]
final readonly class AllocationReceiptWriter
{
    public function __construct(private LedgerRepository $routines, private Outbox $outbox) {}

    public function assertMonetary(TransactionContext $context): void
    {
        $this->routines->assertMonetary($context);
    }

    /**
     * @param  list<mixed>  $bindings
     * @param  list<int>  $invoiceIds  invoices already locked by the caller's plan
     * @return array{0: LedgerReceipt, 1: array<int, array{ref: string, status: InvoiceStatus, total: int, settled: int, revision: int}>}
     */
    public function apply(TransactionContext $context, Connection $connection, string $routine, array $bindings, string $kind, array $invoiceIds): array
    {
        $before = $this->invoices($context, $connection, $invoiceIds);
        $receipt = $this->routines->call($connection, $routine, $bindings, $kind);
        $after = $this->invoices($context, $connection, $invoiceIds);
        if (! $receipt->replayed) {
            foreach ($after as $id => $state) {
                $previous = $before[$id] ?? throw new LogicException('Invoice left the locked plan');
                $expected = InvoiceStatus::derive($previous['status'], $state['total'], $state['settled']);
                // A reversal alone does not undo a write-off: uncollectible stays until a late payment settles again.
                $kept = $previous['status'] === InvoiceStatus::Uncollectible && $state['status'] === InvoiceStatus::Uncollectible;
                if ($state['status'] !== $expected && ! $kept) {
                    throw new LogicException("Invoice {$state['ref']} status {$state['status']->value} differs from the derived {$expected->value}");
                }
            }
        }

        return [$receipt, $after];
    }

    /** @param array<string, mixed> $payload */
    public function event(TransactionContext $context, string $type, string $aggregateRef, int $revision, array $payload): void
    {
        $this->outbox->record($context, new OutboxEvent((string) Str::ulid(), $type, 'billium.event.v1', $aggregateRef, $revision, new DateTimeImmutable, $payload));
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{ref: string, status: InvoiceStatus, total: int, settled: int, revision: int}>
     */
    public function invoices(TransactionContext $context, Connection $connection, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $caller = $context->caller();
        $states = [];
        foreach ($connection->table('billium_invoice')->where('seller_ref', $caller->sellerRef)->where('environment', $caller->environment)
            ->whereIn('id', $ids)->orderBy('id')->get(['id', 'invoice_ref', 'status', 'total_minor', 'allocated_minor', 'credited_minor', 'revision']) as $row) {
            $states[self::int($row->id)] = [
                'ref' => is_string($row->invoice_ref) ? $row->invoice_ref : throw new LogicException('Expected an invoice reference'),
                'status' => InvoiceStatus::from(is_string($row->status) ? $row->status : ''),
                'total' => self::int($row->total_minor),
                'settled' => self::int($row->allocated_minor) + self::int($row->credited_minor),
                'revision' => self::int($row->revision),
            ];
        }

        return $states;
    }

    public static function int(mixed $value): int
    {
        return is_int($value) ? $value : throw new LogicException('Expected a PostgreSQL bigint');
    }
}

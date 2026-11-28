<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Snapshot;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Ledger\Data\Views\LedgerView as View;
use Billium\Ledger\Internal\Observation\ParkedDrainer;
use Billium\Ledger\Internal\Queries\LedgerReader;
use Billium\Platform\Contracts\Feed\FeedRecoveryQueries;
use Billium\Platform\Contracts\Feed\SnapshotSource;
use Billium\Platform\Data\Feed\FeedSelection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;

/** Registered ledger snapshot streams share the feed's pinned read-only snapshot and source scope. */
#[Internal]
readonly class LedgerSnapshotSource implements SnapshotSource
{
    public const array KEYS = ['ledger.obligations', 'ledger.invoices', 'ledger.allocations', 'ledger.payments', 'ledger.discrepancies'];

    public function __construct(private DatabaseManager $databases, private CallerContext $callers, private string $sourceKey, private FeedRecoveryQueries $feedRecovery)
    {
        if (! in_array($sourceKey, self::KEYS, true)) {
            throw new LogicException('Unknown ledger snapshot source');
        }
    }

    public function key(): string
    {
        return $this->sourceKey;
    }

    public function snapshot(FeedSelection $selection, string $connectionName): array
    {
        $caller = $this->callers->current();
        $scope = $selection->scope;
        if ($scope->installationRef !== $caller->installationRef || $scope->sellerRef !== $caller->sellerRef || $scope->environment !== $caller->environment
            || $scope->channel !== 'events' || $scope->sourceAuthority !== 'billium') {
            throw new ProblemException(ProblemCode::AuthDenied, 'Snapshot scope differs from authenticated caller');
        }
        $db = $this->databases->connection($connectionName);
        $settings = $db->selectOne("SELECT current_setting('transaction_isolation') AS isolation,current_setting('transaction_read_only') AS read_only");
        if ($db->transactionLevel() !== 1 || ! $db->getPdo()->inTransaction() || ! is_object($settings) || ($settings->isolation ?? null) !== 'repeatable read' || ($settings->read_only ?? null) !== 'on') {
            throw new LogicException('Ledger snapshot requires the feed REPEATABLE READ READ ONLY transaction');
        }
        $reader = new LedgerReader($db, $caller, $this->feedRecovery);
        $records = match ($this->sourceKey) {
            'ledger.obligations' => $this->obligations($reader),
            'ledger.invoices' => $this->invoices($reader),
            'ledger.allocations' => $this->allocations($reader),
            'ledger.payments' => $this->payments($reader, $connectionName),
            'ledger.discrepancies' => $this->discrepancies($reader),
            default => throw new LogicException('Unknown ledger snapshot source'),
        };
        usort($records, static fn (array $a, array $b): int => strcmp(View::text($a['identity']), View::text($b['identity'])));

        return $records;
    }

    /** @return list<array<string, mixed>> */
    private function obligations(LedgerReader $reader): array
    {
        $adjustments = $this->grouped($reader, 'billium_adjustment', 'obligation_id');
        $creditNotes = $this->grouped($reader, 'billium_credit_note', 'obligation_id');
        $records = [];
        foreach ($reader->scoped('billium_obligation')->orderBy('id')->get() as $row) {
            $payload = View::obligation($row);
            $payload['adjustments'] = $adjustments[View::integer($row->id)] ?? [];
            $payload['creditNotes'] = $creditNotes[View::integer($row->id)] ?? [];
            $records[] = self::record('obligation:'.View::text($row->obligation_ref), View::integer($row->revision), $payload);
        }
        foreach ($reader->scoped('billium_customer_credit')->orderBy('id')->get() as $row) {
            $records[] = self::record('customer-credit:'.View::text($row->credit_ref), View::integer($row->revision), self::row($row));
        }

        return $records;
    }

    /** @return list<array<string, mixed>> */
    private function invoices(LedgerReader $reader): array
    {
        $lines = [];
        foreach ($reader->scoped('billium_invoice_line')->orderBy('invoice_id')->orderBy('position')->get() as $line) {
            $lines[View::integer($line->invoice_id)][] = $line;
        }
        $obligations = $reader->byId('billium_obligation');
        $creditNotes = $this->grouped($reader, 'billium_credit_note', 'invoice_id');
        $records = [];
        foreach ($reader->scoped('billium_invoice')->orderBy('id')->get() as $row) {
            $payload = LedgerReader::invoiceView($row, $lines[View::integer($row->id)] ?? [], $obligations);
            $payload['creditNotes'] = $creditNotes[View::integer($row->id)] ?? [];
            $records[] = self::record('invoice:'.View::text($row->invoice_ref), View::integer($row->revision), $payload);
        }

        return $records;
    }

    /** @return list<array<string, mixed>> */
    private function allocations(LedgerReader $reader): array
    {
        $records = [];
        foreach ($reader->scoped('billium_allocation_receipt')->orderBy('id')->get() as $row) {
            $records[] = self::record('receipt:'.View::text($row->receipt_ref), 1,
                ['receiptRef' => $row->receipt_ref, 'kind' => $row->kind, 'semanticDigest' => $row->semantic_digest, 'body' => View::json($row->body)]);
        }
        $obligations = $this->refs($reader, 'billium_obligation', 'obligation_ref');
        $payments = $this->refs($reader, 'billium_payment', 'payment_ref');
        $credits = $this->refs($reader, 'billium_customer_credit', 'credit_ref');
        $allocations = $reader->scoped('billium_allocation')->orderBy('id')->get();
        $receipts = [];
        foreach ($allocations as $row) {
            $receipts[View::integer($row->id)] = $row->receipt_ref;
        }
        foreach ($allocations as $row) {
            $payload = self::row($row);
            $payload['obligationRef'] = self::ref($obligations, $row->obligation_id);
            $payload['paymentRef'] = self::ref($payments, $row->payment_id);
            $payload['customerCreditRef'] = self::ref($credits, $row->customer_credit_id);
            $payload['reversesReceiptRef'] = self::ref($receipts, $row->reverses_id);
            $records[] = self::record('allocation:'.View::text($row->receipt_ref).':'.View::integer($row->id), 1, $payload);
        }

        return $records;
    }

    /** @return list<array<string, mixed>> */
    private function payments(LedgerReader $reader, string $connectionName): array
    {
        $observedRevisions = $this->grouped($reader, 'billium_observed_payment_revision', 'observed_payment_id');
        $applied = [];
        foreach ($reader->scoped('billium_payment')->orderBy('id')->get() as $payment) {
            $applied[View::text($payment->source_authority)][View::text($payment->operation_ref)] ??= View::integer($payment->id);
        }
        $appliedRevisions = $this->grouped($reader, 'billium_payment_revision', 'payment_id');
        $allocations = $this->grouped($reader, 'billium_allocation', 'payment_id');
        $records = [];
        foreach ($reader->scoped('billium_observed_payment')->orderBy('id')->get() as $row) {
            if ($row->kind === 'refund') {
                // Refund children are immutable detail, never a second applied charge/refund basis.
                $payload = self::row($row);
                $payload['observedRevisions'] = $observedRevisions[View::integer($row->id)] ?? [];
                $records[] = self::record('refund:'.View::text($row->source_authority).':'.View::text($row->operation_ref), View::integer($row->revision), $payload);

                continue;
            }
            $payload = $reader->payment(View::text($row->operation_ref), View::text($row->source_authority));
            $payload['observedRevisions'] = $observedRevisions[View::integer($row->id)] ?? [];
            $paymentId = $applied[View::text($row->source_authority)][View::text($row->operation_ref)] ?? null;
            $payload['appliedRevisions'] = $paymentId === null ? [] : $appliedRevisions[$paymentId] ?? [];
            $payload['allocations'] = $paymentId === null ? [] : $allocations[$paymentId] ?? [];
            $records[] = self::record('payment:'.View::text($row->source_authority).':'.View::text($row->operation_ref), View::integer($row->revision), $payload);
        }
        $obligations = $this->refs($reader, 'billium_obligation', 'obligation_ref');
        $targets = [];
        foreach ($reader->scoped('billium_settlement_target')->orderBy('settlement_intent_id')->orderBy('position')->get() as $target) {
            $targets[View::integer($target->settlement_intent_id)][] = ['position' => $target->position,
                'obligationRef' => self::ref($obligations, $target->obligation_id)];
        }
        $closures = $this->grouped($reader, 'billium_settlement_intent_closure', 'settlement_intent_id');
        foreach ($reader->scoped('billium_settlement_intent')->orderBy('id')->get() as $row) {
            $payload = self::row($row);
            $payload['targets'] = $targets[View::integer($row->id)] ?? [];
            $payload['closures'] = $closures[View::integer($row->id)] ?? [];
            $records[] = self::record('intent:'.View::text($row->business_intent_ref), View::integer($row->revision), $payload);
        }
        // Include source recovery flags even for empty partitions / before the first observed operation.
        foreach ($this->feedRecovery->state($connectionName, ParkedDrainer::CHANNEL)['snapshots'] as $snapshot) {
            $records[] = $snapshot;
        }

        return $records;
    }

    /** @return list<array<string, mixed>> */
    private function discrepancies(LedgerReader $reader): array
    {
        $observedPayments = $reader->byId('billium_observed_payment');
        $events = $this->grouped($reader, 'billium_discrepancy_event', 'discrepancy_id');
        $records = [];
        foreach ($reader->scoped('billium_ledger_discrepancy')->orderBy('id')->get() as $row) {
            $payload = self::row($row);
            $observed = $observedPayments[View::integer($row->observed_payment_id)] ?? throw LedgerReader::unknown();
            $payload['operationRef'] = $observed->operation_ref;
            $payload['sourceAuthority'] = $observed->source_authority;
            $payload['expected'] = View::money($row->expected_minor, $observed->currency);
            $payload['actual'] = View::money($row->actual_minor, $observed->currency);
            unset($payload['expected_minor'], $payload['actual_minor']);
            $payload['events'] = $events[View::integer($row->id)] ?? [];
            $records[] = self::record('discrepancy:'.View::text($row->fingerprint), View::integer($row->observed_revision), $payload);
        }

        return $records;
    }

    /**
     * The scoped rows of a child table grouped by their parent id, in id order: one query per child table instead
     * of one per parent row.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function grouped(LedgerReader $reader, string $table, string $column): array
    {
        $groups = [];
        foreach ($reader->scoped($table)->whereNotNull($column)->orderBy('id')->get() as $row) {
            $groups[View::integer($row->{$column})][] = self::row($row);
        }

        return $groups;
    }

    /** @return array<int, mixed> the scoped rows' reference column keyed by id */
    private function refs(LedgerReader $reader, string $table, string $column): array
    {
        $refs = [];
        foreach ($reader->scoped($table)->get(['id', $column]) as $row) {
            $refs[View::integer($row->id)] = $row->{$column};
        }

        return $refs;
    }

    /** @param array<int, mixed> $refs */
    private static function ref(array $refs, mixed $id): mixed
    {
        return $id === null ? null : $refs[View::integer($id)] ?? null;
    }

    /** @return array<string, mixed> */
    private static function row(object $row): array
    {
        $result = [];
        foreach (get_object_vars($row) as $key => $value) {
            $result[(string) $key] = $value;
        }
        foreach ($result as $key => $value) {
            if (str_ends_with($key, '_minor') && isset($result['currency'])) {
                unset($result[$key]);
                $result[substr($key, 0, -6)] = View::money($value, $result['currency']);
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function record(string $identity, int $revision, array $payload): array
    {
        return ['identity' => $identity, 'revision' => $revision, 'digest' => (new Codec)->digest($payload), 'payload' => $payload];
    }
}

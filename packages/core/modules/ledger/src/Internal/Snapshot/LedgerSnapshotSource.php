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

/** D60/D63: registered ledger snapshot streams share the feed's pinned read-only snapshot and source scope. */
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
        $records = [];
        foreach ($reader->scoped('billium_obligation')->orderBy('id')->get() as $row) {
            $payload = View::obligation($row);
            $payload['adjustments'] = $this->rows($reader, 'billium_adjustment', 'obligation_id', $row->id);
            $payload['creditNotes'] = $this->rows($reader, 'billium_credit_note', 'obligation_id', $row->id);
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
        $records = [];
        foreach ($reader->scoped('billium_invoice')->orderBy('id')->get() as $row) {
            $payload = $reader->invoice(View::text($row->invoice_ref));
            $payload['creditNotes'] = $this->rows($reader, 'billium_credit_note', 'invoice_id', $row->id);
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
        foreach ($reader->scoped('billium_allocation')->orderBy('id')->get() as $row) {
            $payload = self::row($row);
            $payload['obligationRef'] = $reader->scoped('billium_obligation')->where('id', $row->obligation_id)->value('obligation_ref');
            $payload['paymentRef'] = $reader->scoped('billium_payment')->where('id', $row->payment_id)->value('payment_ref');
            $payload['customerCreditRef'] = $reader->scoped('billium_customer_credit')->where('id', $row->customer_credit_id)->value('credit_ref');
            $payload['reversesReceiptRef'] = $reader->scoped('billium_allocation')->where('id', $row->reverses_id)->value('receipt_ref');
            $records[] = self::record('allocation:'.View::text($row->receipt_ref).':'.View::integer($row->id), 1, $payload);
        }

        return $records;
    }

    /** @return list<array<string, mixed>> */
    private function payments(LedgerReader $reader, string $connectionName): array
    {
        $records = [];
        foreach ($reader->scoped('billium_observed_payment')->orderBy('id')->get() as $row) {
            if ($row->kind === 'refund') {
                // D41: refund children are immutable detail, never a second applied charge/refund basis.
                $payload = self::row($row);
                $payload['observedRevisions'] = $this->rows($reader, 'billium_observed_payment_revision', 'observed_payment_id', $row->id);
                $records[] = self::record('refund:'.View::text($row->source_authority).':'.View::text($row->operation_ref), View::integer($row->revision), $payload);

                continue;
            }
            $payload = $reader->payment(View::text($row->operation_ref), View::text($row->source_authority));
            $payload['observedRevisions'] = $this->rows($reader, 'billium_observed_payment_revision', 'observed_payment_id', $row->id);
            $payment = $reader->scoped('billium_payment')->where('source_authority', $row->source_authority)->where('operation_ref', $row->operation_ref)->first();
            $payload['appliedRevisions'] = $payment === null ? [] : $this->rows($reader, 'billium_payment_revision', 'payment_id', $payment->id);
            $payload['allocations'] = $payment === null ? [] : $this->rows($reader, 'billium_allocation', 'payment_id', $payment->id);
            $records[] = self::record('payment:'.View::text($row->source_authority).':'.View::text($row->operation_ref), View::integer($row->revision), $payload);
        }
        foreach ($reader->scoped('billium_settlement_intent')->orderBy('id')->get() as $row) {
            $payload = self::row($row);
            $payload['targets'] = [];
            foreach ($reader->scoped('billium_settlement_target')->where('settlement_intent_id', $row->id)->orderBy('position')->get() as $target) {
                $payload['targets'][] = ['position' => $target->position,
                    'obligationRef' => $reader->scoped('billium_obligation')->where('id', $target->obligation_id)->value('obligation_ref')];
            }
            $payload['closures'] = $this->rows($reader, 'billium_settlement_intent_closure', 'settlement_intent_id', $row->id);
            $records[] = self::record('intent:'.View::text($row->business_intent_ref), View::integer($row->revision), $payload);
        }
        // D60: include source recovery flags even for empty partitions / before the first observed operation.
        foreach ($this->feedRecovery->state($connectionName, ParkedDrainer::CHANNEL)['snapshots'] as $snapshot) {
            $records[] = $snapshot;
        }

        return $records;
    }

    /** @return list<array<string, mixed>> */
    private function discrepancies(LedgerReader $reader): array
    {
        $records = [];
        foreach ($reader->scoped('billium_ledger_discrepancy')->orderBy('id')->get() as $row) {
            $payload = self::row($row);
            $observed = $reader->scoped('billium_observed_payment')->where('id', $row->observed_payment_id)->first() ?? throw LedgerReader::unknown();
            $payload['operationRef'] = $observed->operation_ref;
            $payload['sourceAuthority'] = $observed->source_authority;
            $payload['expected'] = View::money($row->expected_minor, $observed->currency);
            $payload['actual'] = View::money($row->actual_minor, $observed->currency);
            unset($payload['expected_minor'], $payload['actual_minor']);
            $payload['events'] = $this->rows($reader, 'billium_discrepancy_event', 'discrepancy_id', $row->id);
            $records[] = self::record('discrepancy:'.View::text($row->fingerprint), View::integer($row->observed_revision), $payload);
        }

        return $records;
    }

    /** @return list<array<string, mixed>> */
    private function rows(LedgerReader $reader, string $table, string $column, mixed $value): array
    {
        $rows = [];
        foreach ($reader->scoped($table)->where($column, $value)->orderBy('id')->get() as $row) {
            $rows[] = self::row($row);
        }

        return $rows;
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

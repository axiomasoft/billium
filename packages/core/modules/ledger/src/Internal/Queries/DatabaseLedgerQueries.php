<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Queries;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\BilliumQuery;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Data\Allocations\AllocationReceipt;
use Billium\Ledger\Data\Views\LedgerView as View;
use Billium\Ledger\Internal\Commands\CreditNoteGroupRef;
use Billium\Platform\Contracts\Feed\FeedRecoveryQueries;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use JsonException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseLedgerQueries implements LedgerQueries
{
    /** One due page is read and encoded in memory; the same ceiling as the event feed keeps a single request bounded. */
    private const int DUE_PAGE_MAX = 1000;

    public function __construct(private ReadSession $reads, private CallerContext $callers, private FeedRecoveryQueries $feedRecovery) {}

    public function paymentStatus(string $paymentRef, ?string $sourceAuthority = null): array
    {
        return $this->read(static fn (LedgerReader $reader): array => $reader->payment($paymentRef, $sourceAuthority));
    }

    public function obligation(string $obligationRef): array
    {
        return $this->read(static fn (LedgerReader $reader): array => $reader->obligation($obligationRef));
    }

    public function invoice(string $invoiceRef): array
    {
        return $this->read(static fn (LedgerReader $reader): array => $reader->invoice($invoiceRef));
    }

    public function allocationByOperation(string $operationRef, ?string $sourceAuthority = null): array
    {
        return $this->read(static fn (LedgerReader $reader): array => $reader->allocations($operationRef, $sourceAuthority));
    }

    public function receipt(string $receiptRef): array
    {
        return $this->read(static function (LedgerReader $reader) use ($receiptRef): array {
            $row = $reader->scoped('billium_allocation_receipt')->where('receipt_ref', $receiptRef)->first();
            if ($row !== null) {
                return View::json($row->body);
            }
            $refs = CreditNoteGroupRef::partReceipts($receiptRef) ?? throw LedgerReader::unknown();
            $parts = [];
            $scope = null;
            foreach ($reader->scoped('billium_allocation_receipt')->whereIn('receipt_ref', $refs)->orderBy('receipt_ref')->get() as $part) {
                $ref = View::text($part->receipt_ref);
                if ($part->kind !== 'credit_note') {
                    throw LedgerReader::unknown();
                }
                $partScope = [$part->billing_account_ref, $part->currency];
                if ($scope !== null && $scope !== $partScope) {
                    throw LedgerReader::unknown();
                }
                $scope = $partScope;
                $parts[] = ['receiptRef' => $ref, 'receipt' => View::json($part->body)];
            }
            if (count($parts) !== count($refs)) {
                throw LedgerReader::unknown();
            }

            return ['receiptRef' => $receiptRef, 'parts' => $parts];
        });
    }

    public function allocationReceipt(string $receiptRef): ?AllocationReceipt
    {
        return $this->read(static function (LedgerReader $reader) use ($receiptRef): ?AllocationReceipt {
            $row = $reader->scoped('billium_allocation_receipt')->where('receipt_ref', $receiptRef)->first(['kind', 'body']);

            return $row === null ? null : new AllocationReceipt($receiptRef, View::text($row->kind), View::json($row->body));
        });
    }

    public function refundAuthorization(string $paymentRef, ?Money $amount = null): array
    {
        return $this->read(static fn (LedgerReader $reader): array => self::refund($reader->payment($paymentRef), $amount));
    }

    public function dueObligations(string $billingAccountRef, DateTimeImmutable $asOf, int $limit = 100, ?string $cursor = null): array
    {
        if ($billingAccountRef === '' || $limit < 1 || $limit > self::DUE_PAGE_MAX) {
            throw self::invalid();
        }
        $time = View::timestamp($asOf->format('Y-m-d\TH:i:s.uP'));
        $feedRecovery = $this->feedRecovery;

        return $this->reads->run(static function (Connection $db, Caller $caller) use ($billingAccountRef, $time, $limit, $cursor, $feedRecovery): array {
            $reader = new LedgerReader($db, $caller, $feedRecovery);
            if (! $reader->scoped('billium_obligation')->where('billing_account_ref', $billingAccountRef)->exists()) {
                throw LedgerReader::unknown();
            }
            $binding = (new Codec)->digest([$caller->installationRef, $caller->sellerRef, $caller->environment, $billingAccountRef, $time]);
            $query = $reader->scoped('billium_obligation')->where('billing_account_ref', $billingAccountRef)->where('status', 'open')
                ->where('open_minor', '>', 0)->whereNotNull('due_at')->where('due_at', '<=', $time)
                ->where(static fn (Builder $q): Builder => $q->whereNull('collection_eligible_at')->orWhere('collection_eligible_at', '<=', $time));
            if ($cursor !== null) {
                $after = self::cursor($cursor, $binding);
                $query->whereRaw('(due_at,id) > (?::timestamptz,?::bigint)', [$after['dueAt'], $after['id']]);
            }
            $rows = $query->orderBy('due_at')->orderBy('id')->limit($limit + 1)->get();
            $more = $rows->count() > $limit;
            $rows = $rows->take($limit);
            $items = [];
            $last = null;
            foreach ($rows as $row) {
                $item = View::obligation($row);
                // Optional timestamps are omitted in the wire response (query/due-obligations.v1).
                if ($item['collectionEligibleAt'] === null) {
                    unset($item['collectionEligibleAt']);
                }
                $items[] = $item;
                $last = $row;
            }
            $result = ['billingAccountRef' => $billingAccountRef, 'asOf' => $time, 'obligations' => $items];
            if ($more && $last !== null) {
                $result['nextCursor'] = rtrim(strtr(base64_encode(json_encode(['binding' => $binding, 'dueAt' => View::timestamp($last->due_at), 'id' => $last->id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            }

            return $result;
        });
    }

    public function dispatch(string $json): array
    {
        $query = (new Codec)->decode($json);
        if (! $query instanceof BilliumQuery) {
            throw self::invalid();
        }
        $caller = $this->callers->current();
        if ($query->installationRef !== $caller->installationRef || $query->sellerRef !== $caller->sellerRef || $query->environment !== $caller->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Query scope differs from authenticated caller');
        }
        $selector = get_object_vars($query->selector);
        $ref = static fn (string $key): string => is_string($selector[$key] ?? null) && $selector[$key] !== '' ? $selector[$key] : throw self::invalid();
        $authority = isset($selector['sourceAuthority']) ? $ref('sourceAuthority') : null;
        $result = match ($query->query) {
            'paymentStatus' => $this->paymentStatus($ref('paymentRef'), $authority),
            'obligation' => $this->obligation($ref('obligationRef')),
            'invoice' => $this->invoice($ref('invoiceRef')),
            'allocationByOperation' => $this->allocationByOperation($ref('operationRef'), $authority),
            'refundAuthorization' => $this->read(static function (LedgerReader $reader) use ($ref, $selector, $authority): array {
                $payment = $reader->payment($ref('paymentRef'), $authority);
                $exposure = is_array($payment['exposure']) ? $payment['exposure'] : throw self::invalid();
                $amount = isset($selector['amount']) ? Money::parse($ref('amount'), View::text($exposure['currency'])) : null;

                return self::refund($payment, $amount);
            }),
            'dueObligations' => $this->dueObligations($ref('billingAccountRef'), new DateTimeImmutable($query->asOf ?? throw self::invalid()), $query->limit ?? throw self::invalid(), $query->cursor),
            default => throw self::invalid(),
        };
        $schema = match ($query->query) {
            'paymentStatus' => 'ledger.payment_status.v1', 'dueObligations' => 'ledger.due_obligations.v1', default => null,
        };

        return array_filter(['schema' => $schema, 'installationRef' => $caller->installationRef, 'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment, 'result' => $result], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @template T
     *
     * @param  Closure(LedgerReader): T  $read
     * @return T
     */
    private function read(Closure $read): mixed
    {
        return $this->reads->run(fn (Connection $db, Caller $caller): mixed => $read(new LedgerReader($db, $caller, $this->feedRecovery)));
    }

    /**
     * @param  array<string, mixed>  $payment
     * @return array<string, mixed>
     */
    private static function refund(array $payment, ?Money $amount): array
    {
        $exposure = is_array($payment['exposure']) ? $payment['exposure'] : throw self::invalid();
        $currency = View::text($exposure['currency']);
        $applied = is_array($payment['applied'] ?? null) ? $payment['applied'] : null;
        $capacity = $applied === null ? 0 : Money::fromWire($applied['capturedTotal'])->minor - Money::fromWire($applied['refundedTotal'])->minor;
        $allocated = $applied === null ? 0 : Money::fromWire($applied['allocated'])->minor;
        $requested = $amount ?? Money::fromWire(View::money($capacity, $currency));
        if ($requested->currency->code !== $currency) {
            throw new ProblemException(ProblemCode::MoneyCurrencyMismatch, 'Refund currency differs from original charge');
        }
        if ($requested->minor < 0) {
            throw self::invalid();
        }
        // No reservation. Required release after this refund = max(0, allocated - (captured - refunded - amount)).
        // Keep intermediates in int64 even for a request above capacity.
        $release = min($allocated, max(0, $allocated - ($capacity - min($capacity, $requested->minor))));
        $held = is_array($payment['financialHold']) && ($payment['financialHold']['active'] ?? false) === true;
        $available = ! $held && $payment['projectionStatus'] === 'current';

        return ['paymentRef' => $payment['paymentRef'], 'normalCapacity' => View::money($capacity, $currency),
            'requested' => $requested->toWire(), 'requiredReallocation' => View::money($release, $currency),
            'financialHold' => $payment['financialHold'], 'projectionStatus' => $payment['projectionStatus'],
            'allowed' => $available && $requested->minor <= $capacity, 'advisory' => true];
    }

    /** @return array{dueAt: string, id: int} */
    private static function cursor(string $token, string $binding): array
    {
        $json = base64_decode(strtr($token, '-_', '+/'), true);
        try {
            $value = $json === false ? null : json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::invalid();
        }
        if (! is_array($value) || ($value['binding'] ?? null) !== $binding || ! is_string($value['dueAt'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $value['dueAt']) !== 1 || ! is_int($value['id'] ?? null) || $value['id'] < 1) {
            throw self::invalid();
        }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value['dueAt']);
        if ($time === false || $time->format('Y-m-d\TH:i:s.u\Z') !== $value['dueAt']) {
            throw self::invalid();
        }

        return ['dueAt' => $value['dueAt'], 'id' => $value['id']];
    }

    private static function invalid(): ProblemException
    {
        return new ProblemException(ProblemCode::ValidationInvalid, 'Invalid ledger query');
    }
}

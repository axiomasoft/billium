<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Views;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Packman\Attributes\Api;
use stdClass;

/** Lossless DB-to-query rendering; never float or a current pricing lookup. */
#[Api(since: '1.0')]
final class LedgerView
{
    public static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new LogicException('Expected a PostgreSQL text value');
    }

    public static function integer(mixed $value): int
    {
        return is_int($value) ? $value : throw new LogicException('Expected a PostgreSQL bigint');
    }

    /** @return array{amount: string, currency: string} */
    public static function money(mixed $minor, mixed $currency): array
    {
        return new Money(self::integer($minor), Currency::of(self::text($currency)))->toWire();
    }

    public static function timestamp(mixed $value): ?string
    {
        return $value === null ? null : new DateTimeImmutable(self::text($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /** @return array<string, mixed> */
    public static function json(mixed $value): array
    {
        $decoded = json_decode(self::text($value), false, 512, JSON_THROW_ON_ERROR);
        if (! $decoded instanceof stdClass) {
            throw new LogicException('Expected a stored JSON object');
        }
        $result = [];
        foreach (get_object_vars($decoded) as $key => $item) {
            $result[(string) $key] = self::jsonValue($item);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    private static function jsonValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $members = get_object_vars($value);
            ksort($members, SORT_STRING);
            $members = array_map(self::jsonValue(...), $members);

            return array_is_list($members) ? (object) $members : $members;
        }

        return is_array($value) ? array_map(self::jsonValue(...), $value) : $value;
    }

    /** @return array<string, mixed> */
    public static function obligation(stdClass $row): array
    {
        return [
            'obligationRef' => $row->obligation_ref, 'billingAccountRef' => $row->billing_account_ref,
            'basisRef' => $row->basis_ref, 'customerRef' => $row->customer_ref, 'componentRef' => $row->component_ref,
            'originalDue' => self::money($row->amount_due_minor, $row->currency), 'adjusted' => self::money($row->adjusted_minor, $row->currency),
            'allocated' => self::money($row->allocated_minor, $row->currency), 'credited' => self::money($row->credited_minor, $row->currency),
            'open' => self::money($row->open_minor, $row->currency), 'termsDigest' => $row->terms_digest,
            'priceVersionRef' => $row->price_version_ref, 'taxSnapshotRef' => $row->tax_snapshot_ref,
            'status' => $row->status, 'revision' => $row->revision, 'dueAt' => self::timestamp($row->due_at),
            'duePolicyVersion' => $row->due_policy_version, 'sourcePolicyRef' => $row->source_policy_ref,
            'sourcePolicySnapshotDigest' => $row->source_policy_snapshot_digest, 'collectionEligibleAt' => self::timestamp($row->collection_eligible_at),
        ];
    }
}

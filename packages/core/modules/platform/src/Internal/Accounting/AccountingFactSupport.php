<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Accounting;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Platform\Data\Accounting\AccountingFactKind;
use Billium\Platform\Data\Accounting\AccountingSourceRecord;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Internal;

/**
 * Shared pure payload parsing and deterministic digest helpers for accounting fact mappers (0 I/O).
 */
#[Internal]
final readonly class AccountingFactSupport
{
    public function __construct(
        public Codec $codec,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $wireKeys
     * @param  list<string>  $minorKeys
     */
    public function extractMoney(
        array $payload,
        array $wireKeys,
        array $minorKeys,
        bool $allowNegativeMagnitude = false,
    ): ?Money {
        foreach ($wireKeys as $key) {
            $money = self::parseMoneyWire($payload[$key] ?? null);
            if ($money !== null) {
                if ($allowNegativeMagnitude && $money->minor < 0) {
                    return $money->minor === PHP_INT_MIN ? null : new Money(-$money->minor, $money->currency);
                }

                return $money;
            }
        }

        $currencyCode = self::nonEmptyString($payload['currency'] ?? null);
        if ($currencyCode === null) {
            return null;
        }

        foreach ($minorKeys as $key) {
            $minor = self::parseIntValue($payload[$key] ?? null);
            if ($minor !== null) {
                if ($allowNegativeMagnitude && $minor === PHP_INT_MIN) {
                    return null;
                }
                $value = $allowNegativeMagnitude ? abs($minor) : $minor;

                return new Money($value, Currency::of($currencyCode));
            }
        }

        return null;
    }

    public function cashFactRef(
        string $sellerRef,
        string $environment,
        AccountingFactKind $factKind,
        string $providerAccountRef,
        string $providerEffectRef,
    ): string {
        $digest = $this->codec->digest([
            'sellerRef' => $sellerRef,
            'environment' => $environment,
            'factKind' => $factKind->value,
            'providerAccountRef' => $providerAccountRef,
            'providerEffectRef' => $providerEffectRef,
        ]);

        return 'fact:'.$factKind->value.':'.substr($digest, 7, 32);
    }

    public function namedFactRef(
        string $sellerRef,
        string $environment,
        AccountingFactKind $factKind,
        string $economicEffectRef,
    ): string {
        $digest = $this->codec->digest([
            'sellerRef' => $sellerRef,
            'environment' => $environment,
            'factKind' => $factKind->value,
            'economicEffectRef' => $economicEffectRef,
        ]);

        return 'fact:'.$factKind->value.':'.substr($digest, 7, 32);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function snapshotDigest(
        AccountingSourceRecord $record,
        string $sourceFactRef,
        Money $money,
        array $metadata,
    ): string {
        if ($record->snapshotDigest !== null) {
            return $record->snapshotDigest;
        }

        return $this->codec->digest([
            'sourceFactRef' => $sourceFactRef,
            'installationRef' => $record->installationRef,
            'sellerRef' => $record->sellerRef,
            'environment' => $record->environment,
            'sourceLineage' => $record->sourceLineage,
            'money' => $money->toWire(),
            'occurredAt' => self::utc($record->occurredAt),
            'metadata' => $metadata,
        ]);
    }

    public static function parseMoneyWire(mixed $value): ?Money
    {
        if (! is_array($value) || ! isset($value['amount'], $value['currency'])) {
            return null;
        }
        if (! is_string($value['amount']) || ! is_string($value['currency'])) {
            return null;
        }

        return Money::parse($value['amount'], $value['currency']);
    }

    public static function parseIntValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)$/', $value) === 1) {
            $minor = (int) $value;
            // An out-of-range numeric string is cast through float and must not become money.
            if ((string) $minor !== $value) {
                return null;
            }

            return $minor;
        }

        return null;
    }

    public static function optionalInt(mixed $value): ?int
    {
        return self::parseIntValue($value);
    }

    public static function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<string>
     */
    public static function normalizeStringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $items = [];
        foreach ($raw as $value) {
            $text = self::nonEmptyString($value);
            if ($text !== null && ! in_array($text, $items, true)) {
                $items[] = $text;
            }
        }
        sort($items, SORT_STRING);

        return $items;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function filterNonNull(array $values): array
    {
        $filtered = [];
        foreach ($values as $key => $value) {
            if ($value !== null) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    public static function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}

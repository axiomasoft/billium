<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Support;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Illuminate\Support\Str;
use stdClass;

/**
 * payment.observation.v1 wire envelopes with a real contentDigest (BC-ID-12) for the ledger observation proofs.
 * Totals are given in minor units; everything else defaults to one embedded-commerz source of seller-a/test.
 */
final class ObservationFactory
{
    public const string INSTALLATION = 'inst-test';

    /**
     * A charge snapshot at one revision: captured/refunded are the cumulative source totals (assigned, never added).
     *
     * @param  array<string, mixed>  $fields  wire overrides; requested/authorizedTotal/capturedTotal/refundedTotal accept minor ints
     */
    public static function charge(string $operation, int $revision, int $captured, int $refunded = 0, int $sequence = 1, array $fields = []): stdClass
    {
        return self::make([
            'operationRef' => $operation, 'operationKind' => 'charge', 'operationRevision' => $revision, 'sourceSequence' => $sequence,
            'requested' => max($captured, 1), 'authorizedTotal' => $captured, 'capturedTotal' => $captured, 'refundedTotal' => $refunded,
            ...$fields,
        ]);
    }

    /**
     * A refund child: its capturedTotal is the refunded amount (detail and reconcile only).
     *
     * @param  array<string, mixed>  $fields
     */
    public static function refund(string $operation, string $original, int $revision, int $amount, int $sequence = 1, array $fields = []): stdClass
    {
        return self::make([
            'operationRef' => $operation, 'operationKind' => 'refund', 'originalPaymentRef' => $original, 'operationRevision' => $revision,
            'sourceSequence' => $sequence, 'requested' => max($amount, 1), 'authorizedTotal' => $amount, 'capturedTotal' => $amount, 'refundedTotal' => 0,
            ...$fields,
        ]);
    }

    /** @param array<string, mixed> $fields */
    public static function make(array $fields): stdClass
    {
        $currency = is_string($fields['currency'] ?? null) ? $fields['currency'] : 'RUB';
        $body = [
            'schema' => 'payment.observation.v1', 'eventId' => (string) Str::ulid(), 'sourceAuthority' => 'embedded-commerz',
            'sourceLineage' => 'L1', 'partitionRef' => 'part-1', 'sourceSequence' => 1, 'installationRef' => self::INSTALLATION,
            'environment' => 'test', 'sellerRef' => 'seller-a', 'operationRef' => 'op-1', 'operationKind' => 'charge',
            'businessIntentRef' => 'intent-a', 'operationRevision' => 1, 'state' => 'succeeded', 'currency' => $currency,
            'requested' => 1, 'authorizedTotal' => 0, 'capturedTotal' => 0, 'refundedTotal' => 0,
            'providerAccountRef' => 'pa-1', 'evidenceRef' => 'ev-'.Str::lower((string) Str::ulid()),
            'observedAt' => '2026-10-29T10:00:00Z', 'recordedAt' => '2026-10-29T10:00:01Z',
            ...$fields,
        ];
        foreach (['requested', 'authorizedTotal', 'capturedTotal', 'refundedTotal'] as $money) {
            if (is_int($body[$money])) {
                $body[$money] = new Money($body[$money], Currency::of($currency))->toWire();
            }
        }
        unset($body['contentDigest']);
        $envelope = json_decode(json_encode($body, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
        if (! $envelope instanceof stdClass) {
            throw new \LogicException('An observation envelope is an object');
        }

        return self::seal($envelope);
    }

    /**
     * A copy with changed fields and a recomputed contentDigest (same eventId unless overridden).
     *
     * @param  array<string, mixed>  $fields
     */
    public static function with(stdClass $envelope, array $fields): stdClass
    {
        $copy = get_object_vars($envelope);
        $currency = is_string($copy['currency'] ?? null) ? $copy['currency'] : 'RUB';
        foreach ($fields as $name => $value) {
            $copy[$name] = in_array($name, ['requested', 'authorizedTotal', 'capturedTotal', 'refundedTotal'], true) && is_int($value)
                ? new Money($value, Currency::of($currency))->toWire() : $value;
        }
        $result = json_decode(json_encode($copy, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
        if (! $result instanceof stdClass) {
            throw new \LogicException('An observation envelope is an object');
        }
        unset($result->contentDigest);

        return self::seal($result);
    }

    public static function json(stdClass $envelope): string
    {
        return (new Codec)->canonicalize($envelope);
    }

    private static function seal(stdClass $envelope): stdClass
    {
        $envelope->contentDigest = (new Codec)->digest($envelope, 'contentDigest');

        return $envelope;
    }
}

<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Observation;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Data\Money;
use stdClass;

/**
 * Economic materialDigest `ledger-material/1` of one observed revision (D60, INV-06), separate from the transport
 * contentDigest. It covers the source business binding (installation, environment, seller, sourceAuthority, provider
 * account), the operation identity/kind/root, the business intent, the revision, state, currency, the four cumulative
 * totals and the economic discrepancy identity. Delivery identity (eventId, lineage, partition, sequence), timestamps
 * and evidence/transport refs are custody metadata: another delivery of the same snapshot has the same digest and never
 * a second economic effect; the same revision with another digest is a conflict.
 */
final readonly class EconomicDigest
{
    public const string VERSION = 'ledger-material/1';

    public static function of(PaymentObservation $observation): string
    {
        $discrepancy = $observation->discrepancy;

        return (new Codec)->digest((object) [
            'normalization' => self::VERSION,
            'installationRef' => $observation->installationRef,
            'environment' => $observation->environment,
            'sellerRef' => $observation->sellerRef,
            'sourceAuthority' => $observation->sourceAuthority,
            'providerAccountRef' => $observation->providerAccountRef,
            'operationRef' => $observation->operationRef,
            'operationKind' => $observation->operationKind,
            'originalPaymentRef' => $observation->originalPaymentRef,
            'businessIntentRef' => $observation->businessIntentRef,
            'operationRevision' => $observation->operationRevision,
            'state' => $observation->state,
            'currency' => $observation->currency,
            'requested' => self::minor($observation->requested),
            'authorized' => self::minor($observation->authorizedTotal),
            'captured' => self::minor($observation->capturedTotal),
            'refunded' => self::minor($observation->refundedTotal),
            'discrepancy' => $discrepancy === null ? null : self::discrepancy($discrepancy),
        ], 'materialDigest');
    }

    /** The economic part of a source discrepancy: its own identity, revision, accepted totals and resolution. */
    private static function discrepancy(stdClass $discrepancy): stdClass
    {
        $accepted = [];
        $totals = $discrepancy->acceptedTotals ?? null;
        foreach ($totals instanceof stdClass ? get_object_vars($totals) : [] as $name => $value) {
            $accepted[(string) $name] = $value instanceof stdClass ? self::minor(Money::fromWire($value)) : null;
        }
        ksort($accepted, SORT_STRING);

        return (object) [
            'discrepancyRef' => $discrepancy->discrepancyRef ?? null,
            'kind' => $discrepancy->kind ?? null,
            'revision' => $discrepancy->revision ?? null,
            'acceptedTotals' => (object) $accepted,
            'resolution' => $discrepancy->resolution ?? null,
        ];
    }

    private static function minor(Money $money): stdClass
    {
        return (object) ['minor' => (string) $money->minor, 'currency' => $money->currency->code];
    }
}

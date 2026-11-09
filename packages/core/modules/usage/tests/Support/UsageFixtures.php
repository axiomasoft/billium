<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Support;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\MetricVersion;
use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Core\Data\Caller;
use Billium\Usage\Contracts\UsageAttribution;
use Billium\Usage\Data\AttributionSnapshot;
use Billium\Usage\Internal\DatabaseUsage;
use stdClass;

final class UsageFixtures implements UsageAttribution
{
    public bool $unresolved = false;

    public string $cutoff = '2026-11-01T00:00:00Z';

    public function resolve(Caller $caller, UsageFactV2 $fact): ?AttributionSnapshot
    {
        if ($this->unresolved) {
            return null;
        }
        $attribution = clone self::fact()->attribution;
        $transferred = DatabaseUsage::time($fact->eventAt) >= DatabaseUsage::time($this->cutoff);
        if ($transferred) {
            $attribution->billingAccountRef = 'new-payer';
            $attribution->customerRef = 'new-customer';
            $attribution->effectiveAt = $this->cutoff;
        }

        return new AttributionSnapshot(
            sellerRef: $caller->sellerRef, attribution: $attribution, mappingRef: $transferred ? 'mapping-2' : 'mapping-1',
            validFrom: $transferred ? $this->cutoff : '2026-10-01T00:00:00Z', validUntil: $transferred ? '2026-11-01T00:00:00Z' : $this->cutoff,
            periodRef: 'october-2026', periodStart: '2026-10-01T00:00:00Z', periodEnd: '2026-11-01T00:00:00Z',
        );
    }

    /** @param (callable(stdClass):void)|null $change */
    public static function metric(?callable $change = null): MetricVersion
    {
        $raw = self::raw('metric');
        $raw->sellerRef = 'seller-a';
        if ($change !== null) {
            $change($raw);
        }
        $codec = new Codec;
        $raw->digest = $codec->digest($raw);
        $metric = $codec->decode($codec->canonicalize($raw));
        assert($metric instanceof MetricVersion);

        return $metric;
    }

    /** @param (callable(stdClass):void)|null $change */
    public static function fact(?callable $change = null): UsageFactV2
    {
        $raw = self::raw('fact');
        $raw->sellerRef = 'seller-a';
        $raw->metricDigest = self::metric()->digest;
        if ($change !== null) {
            $change($raw);
        }
        $codec = new Codec;
        $raw->digest = $codec->digest($raw);
        $fact = $codec->decode($codec->canonicalize($raw));
        assert($fact instanceof UsageFactV2);

        return $fact;
    }

    public static function correction(UsageFactV2 $previous, string $quantity = '10'): UsageFactV2
    {
        $raw = json_decode(json_encode($previous->raw, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
        $raw->revision++;
        $raw->value->quantity = $quantity;
        $raw->correction = (object) [
            'correctionRef' => 'correction-'.$raw->revision,
            'supersedesRevision' => $previous->revision, 'originalFactDigest' => $previous->digest,
        ];
        $codec = new Codec;
        $raw->digest = $codec->digest($raw);
        $fact = $codec->decode($codec->canonicalize($raw));
        assert($fact instanceof UsageFactV2);

        return $fact;
    }

    private static function raw(string $name): stdClass
    {
        $vector = json_decode(file_get_contents(dirname(__DIR__, 6).'/fixtures/vectors/usage/contracts/'.$name.'.json'), false, 64, JSON_THROW_ON_ERROR);

        return json_decode($vector->script[0]->args->json_text, false, 64, JSON_THROW_ON_ERROR);
    }
}

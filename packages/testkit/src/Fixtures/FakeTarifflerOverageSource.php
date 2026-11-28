<?php

declare(strict_types=1);

namespace Billium\Testkit\Fixtures;

use Billium\Contracts\Contracts\Codec;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final class FakeTarifflerOverageSource
{
    /** @var array<string, int> */
    private array $physicalEvents = [];

    private int $revision = 0;

    private int $currentNetBillable = 0;

    private ?string $lastFactDigest = null;

    /** @var array<string, array<string, mixed>|null> */
    private array $operationResults = [];

    private readonly string $factId;

    /**
     * @param  array<string, mixed>  $originalAttribution
     */
    public function __construct(
        private readonly Codec $codec,
        private readonly string $sellerRef,
        private readonly string $sourceRef,
        private readonly string $metricRef,
        private readonly string $metricVersion,
        private readonly string $metricDigest,
        private readonly string $commercialBasisRef,
        private readonly string $normalizationRef,
        private readonly string $periodRef,
        private readonly int $includedPoolTokens,
        private readonly array $originalAttribution,
        private readonly string $periodStartIso,
        private readonly string $periodEndIso,
    ) {
        $this->factId = 'overage:'.$this->commercialBasisRef.':'.$this->periodRef;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function recordPhysicalTokens(
        string $collectorEventId,
        int $tokens,
        string $eventAtIso,
        string $receivedAtIso,
        ?string $correctionRef = null,
    ): ?array {
        $opKey = json_encode([$collectorEventId, $correctionRef, $tokens], JSON_THROW_ON_ERROR);
        if (array_key_exists($opKey, $this->operationResults)) {
            return $this->operationResults[$opKey];
        }

        $this->physicalEvents[$collectorEventId] = $tokens;
        $totalPhysical = array_sum($this->physicalEvents);
        $netBillable = max(0, $totalPhysical - $this->includedPoolTokens);

        if ($netBillable === $this->currentNetBillable) {
            $this->operationResults[$opKey] = null;

            return null;
        }

        $previousRevision = $this->revision;
        $this->revision++;
        $this->currentNetBillable = $netBillable;

        $body = [
            'schema' => 'usage.fact.v2',
            'sellerRef' => $this->sellerRef,
            'source' => $this->sourceRef,
            'factId' => $this->factId,
            'revision' => $this->revision,
            'metricRef' => $this->metricRef,
            'metricVersion' => $this->metricVersion,
            'metricDigest' => $this->metricDigest,
            'unit' => 'token',
            'attribution' => $this->originalAttribution,
            'interval' => [
                'start' => $this->periodStartIso,
                'end' => $this->periodEndIso,
            ],
            'eventAt' => $eventAtIso,
            'receivedAt' => $receivedAtIso,
            'value' => [
                'kind' => 'decimal',
                'quantity' => (string) $netBillable,
            ],
            'grouping' => [
                ['name' => 'region', 'value' => 'eu'],
            ],
            'billableStream' => [
                'mode' => 'normalized_net_billable',
                'policy' => 'exclusive_commercial_basis/1',
                'commercialBasisRef' => $this->commercialBasisRef,
                'normalizationRef' => $this->normalizationRef,
            ],
            'provenance' => [
                'collector' => 'fake-tariffler-overage-adapter',
                'version' => '1',
                'sourceRef' => $collectorEventId,
                'sourceDigest' => $this->codec->digest([
                    'events' => $this->physicalEvents,
                    'includedPoolTokens' => $this->includedPoolTokens,
                    'netBillable' => $netBillable,
                ]),
            ],
        ];

        if ($previousRevision >= 1) {
            $body['correction'] = [
                'correctionRef' => $correctionRef ?? 'corr:'.$this->commercialBasisRef.':'.$this->periodRef.':rev'.$this->revision,
                'supersedesRevision' => $previousRevision,
                'originalFactDigest' => $this->lastFactDigest,
            ];
        }

        $body['digest'] = $this->codec->digest($body);
        $this->lastFactDigest = $body['digest'];
        $this->operationResults[$opKey] = $body;

        return $body;
    }
}

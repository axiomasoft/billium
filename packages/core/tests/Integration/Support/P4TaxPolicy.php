<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Integration\Support;

use Billium\Rating\Contracts\TaxSnapshotPort;
use Billium\Rating\Data\Tax\TaxCorrectionPolicySnapshot;
use Billium\Rating\Data\Tax\TaxCorrectionRequest;
use Billium\Rating\Data\Tax\TaxResolutionRequest;
use Billium\Rating\Data\Tax\TaxSnapshot;
use Billium\Rating\Enums\TaxMode;
use Billium\Rating\Enums\TaxSnapshotKind;
use DateTimeImmutable;
use LogicException;

/** Explicit fixture policy, not a jurisdictional default. */
final class P4TaxPolicy implements TaxSnapshotPort
{
    public function resolve(TaxResolutionRequest $request): TaxSnapshot
    {
        throw new LogicException('Replay corrections must use retained original tax');
    }

    public function resolveCorrection(TaxCorrectionRequest $request): TaxCorrectionPolicySnapshot
    {
        $from = new DateTimeImmutable('2026-01-01Z');

        return new TaxCorrectionPolicySnapshot(policyRef: 'p4-replay-tax', policyVersion: '1', treatment: 'original_rate',
            selectedRate: new TaxSnapshot(snapshotRef: 'p4-replay-tax/1', kind: TaxSnapshotKind::Correction,
                mode: TaxMode::Exclusive, jurisdiction: 'TEST', taxCode: 'test-tax', rate: '0.20', effectiveDate: $from,
                taxPoint: $request->originalTaxPoint, noTaxReason: null, resolver: 'p4-fixture', resolverVersion: '1',
                inputDigest: $request->inputDigest, evidenceDigest: 'fixture:p4-replay-tax/1'),
            effectiveFrom: $from, effectiveTo: null, inputDigest: $request->inputDigest,
            outputDigest: 'fixture:p4-replay-tax/1', resolverVersion: '1');
    }
}

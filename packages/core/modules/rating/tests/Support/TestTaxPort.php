<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Support;

use Billium\Rating\Contracts\TaxSnapshotPort;
use Billium\Rating\Data\Tax\TaxCorrectionPolicySnapshot;
use Billium\Rating\Data\Tax\TaxCorrectionRequest;
use Billium\Rating\Data\Tax\TaxResolutionRequest;
use Billium\Rating\Data\Tax\TaxSnapshot;
use Billium\Rating\Enums\TaxMode;
use Billium\Rating\Enums\TaxSnapshotKind;
use DateTimeImmutable;
use RuntimeException;

final class TestTaxPort implements TaxSnapshotPort
{
    public string $rate = '0';

    public bool $missing = false;

    public int $calls = 0;

    public function resolve(TaxResolutionRequest $request): TaxSnapshot
    {
        $this->calls++;
        if ($this->missing) {
            throw new RuntimeException('test missing tax');
        }

        return new TaxSnapshot(snapshotRef: 'tax:'.$request->componentRef.':'.$this->rate,
            kind: TaxSnapshotKind::Standard, mode: $this->rate === '0' ? TaxMode::NoneDeclared : TaxMode::Exclusive,
            jurisdiction: 'TEST', taxCode: 'test-tax', rate: $this->rate, effectiveDate: new DateTimeImmutable('2026-01-01Z'),
            taxPoint: $request->taxPoint, noTaxReason: $this->rate === '0' ? 'test-exempt' : null,
            resolver: 'test-host', resolverVersion: '1', inputDigest: $request->inputDigest, evidenceDigest: 'test-evidence');
    }

    public function resolveCorrection(TaxCorrectionRequest $request): TaxCorrectionPolicySnapshot
    {
        throw new RuntimeException('not used');
    }
}

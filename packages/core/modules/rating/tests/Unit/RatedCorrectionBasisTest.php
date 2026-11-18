<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Unit;

use Billium\Ledger\Data\Obligations\CorrectionBasis as LedgerCorrectionBasis;
use Billium\Rating\Internal\Adapters\RatedCorrectionBasis;
use Billium\Schedule\Data\Commands\RegisterCorrectionChild;
use Billium\Schedule\Data\CorrectionBasis as ScheduleCorrectionBasis;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RatedCorrectionBasisTest extends TestCase
{
    public function test_child_binds_the_ledger_basis_of_the_rated_predecessor_only(): void
    {
        $basis = RatedCorrectionBasis::derive(predecessorBasisRef: 'original', occurrenceRef: 'occurrence', correctionRevision: 2);
        self::assertSame(LedgerCorrectionBasis::derive(originalBasisRef: 'rating:original', correctionRef: 'occurrence', revision: 2), $basis);
        $policy = new RatedCorrectionBasis;
        self::assertTrue($policy->accepts(self::child($basis)));
        self::assertFalse($policy->accepts(self::child(ScheduleCorrectionBasis::derive(parentOccurrenceRef: 'occurrence', correctionRevision: 2))));
        self::assertFalse($policy->accepts(self::child(
            LedgerCorrectionBasis::derive(originalBasisRef: 'original', correctionRef: 'occurrence', revision: 2))));
        self::assertFalse($policy->accepts(self::child(obligationBasisRef: $basis, predecessor: '')));
    }

    private static function child(string $obligationBasisRef, string $predecessor = 'original'): RegisterCorrectionChild
    {
        return new RegisterCorrectionChild(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: 'occurrence',
            originalPeriodStart: new DateTimeImmutable('2026-01-01Z'), originalPeriodEnd: new DateTimeImmutable('2026-02-01Z'),
            correctionRevision: 2, predecessorRevision: 1, predecessorRatedBasisRef: $predecessor, sourceFrontierDigest: 'frontier',
            expectedHeadRevision: 1, expectedHeadFrontierDigest: 'frontier:1', taxCorrectionPolicyRef: null, taxCorrectionPolicyVersion: null,
            reasonCode: 'late_usage', obligationBasisRef: $obligationBasisRef);
    }
}

<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Feature;

use Billium\Contracts\Data\Envelopes\MetricVersion;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Contracts\RatingCorrection;
use Billium\Rating\Data\Correction\PrepareCorrection;
use Billium\Rating\Enums\CorrectionKind;
use Billium\Rating\Tests\RatingTestCase;
use Billium\Usage\Enums\LatePolicy;
use Billium\Usage\Tests\Support\UsageFixtures;
use DateTimeImmutable;

final class LateUsageCorrectionTest_Reject extends RatingTestCase
{
    protected function policy(LatePolicy $late = LatePolicy::AcceptAdjust, ?MetricVersion $metric = null, string $algorithm = 'additive_sum',
        int $scale = 9, bool $carry = false, string $missingInitial = 'refuse', ?string $definitionDigest = null): void
    {
        parent::policy(late: LatePolicy::Reject, metric: $metric, algorithm: $algorithm, scale: $scale,
            carry: $carry, missingInitial: $missingInitial, definitionDigest: $definitionDigest);
    }

    public function test_post_final_reject_policy_refuses_late_fact_without_financial_writes(): void
    {
        $this->book($this->prepared());
        $this->usage()->submitUsageFact(UsageFixtures::fact(static function ($fact): void {
            $fact->factId = 'late-2';
            $fact->value->quantity = '50';
        }));
        try {
            $this->application()->make(RatingCorrection::class)->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test',
                parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'late', releasePolicy: null,
                correctionEffectiveDate: new DateTimeImmutable('2026-11-15Z')));
            self::fail('Late rejection policy was bypassed');
        } catch (ProblemException $error) {
            self::assertSame('billium.usage.late_rejected', $error->detail);
        }
        self::assertSame(0, $this->rows('billium_rating_correction'));
        self::assertSame(0, $this->rows('billium_schedule_billing_correction'));
        self::assertSame(1, $this->rows('billium_obligation'));
    }
}

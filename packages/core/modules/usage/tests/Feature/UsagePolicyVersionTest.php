<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\MetricVersion;
use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Contracts\Data\Envelopes\UsageReceiptV2;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Usage\Tests\AggregationTestCase;
use Billium\Usage\Tests\Support\UsageFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

#[Group('pg')]
final class UsagePolicyVersionTest extends AggregationTestCase
{
    #[DataProvider('policies')]
    public function test_accepted_policy_v2_preserves_metric_v1_and_its_aggregate_after_host_changes(string $policy): void
    {
        $this->policy();
        $this->completeness();
        $old = UsageFixtures::fact();
        $oldReceipt = $this->usage()->submitUsageFact($old);
        $before = $this->freeze();
        $storedMetric = $this->value("SELECT body::text FROM billium_usage_metric WHERE metric_version='api_calls/1'");
        $metric = self::metric($policy);
        self::assertNotSame($old->metricDigest, $metric->digest);
        $this->usage()->registerMetric($metric);
        $new = self::fact($metric);
        $newReceipt = $this->usage()->submitUsageFact($new);
        self::assertSame(2, $this->rows('billium_usage_metric'));
        self::assertSame($storedMetric, $this->value("SELECT body::text FROM billium_usage_metric WHERE metric_version='api_calls/1'"));
        self::assertSame($metric->digest, $this->value('SELECT digest FROM billium_usage_metric WHERE metric_version=?', [$metric->metricVersion]));

        // Both committed receipts replay even when the host now resolves a different mapping.
        $this->mappings->cutoff = '2026-10-10T00:00:00Z';
        self::assertEquals($oldReceipt, $this->usage()->submitUsageFact($old));
        self::assertEquals($newReceipt, $this->usage()->submitUsageFact($new));
        $this->mappings->unresolved = true;
        self::assertEquals($oldReceipt, $this->usage()->submitUsageFact($old));
        self::assertEquals($newReceipt, $this->usage()->submitUsageFact($new));
        $after = $this->freeze();
        self::assertFalse($after['created']);
        self::assertSame('12.000000001', $after['quantity']);
        self::assertSame($before['snapshotDigest'], $after['snapshotDigest']);
        self::assertSame(1, $this->rows('billium_usage_aggregate_snapshot'));
        self::assertSame(1, $this->rows('billium_usage_aggregate_member'));
        self::assertSame(2, $this->rows('billium_usage_fact'));
        self::assertSame(2, $this->rows('billium_idempotency_receipt', "command_kind='usage.fact_submit'"));
        $this->assertNoMoney();
    }

    public function test_v1_accepts_event_specific_host_mapping_while_v2_requires_its_explicit_pin(): void
    {
        $metric = self::metric('attributionPolicy');
        $this->usage()->registerMetric($metric);
        $this->mappings->cutoff = '2026-10-20T00:00:00Z';
        $pinned = self::fact($metric, static function (stdClass $raw): void {
            $raw->interval->end = '2026-10-20T00:00:00Z';
        });
        $this->usage()->submitUsageFact($pinned);
        $transferred = static function (stdClass $raw): void {
            $raw->factId = 'transferred-'.$raw->metricVersion;
            $raw->eventAt = '2026-10-21T00:00:00Z';
            $raw->interval->start = '2026-10-20T00:00:00Z';
            $raw->attribution->effectiveAt = '2026-10-20T00:00:00Z';
            $raw->attribution->customerRef = 'new-customer';
            $raw->attribution->billingAccountRef = 'new-payer';
        };
        $this->usage()->submitUsageFact(UsageFixtures::fact($transferred));
        $this->refused(fn (): UsageReceiptV2 => $this->usage()->submitUsageFact(self::fact($metric, $transferred)));
        self::assertSame('mapping-1', $this->value('SELECT mapping_ref FROM billium_usage_fact WHERE metric_version=?', [$metric->metricVersion]));
        self::assertSame('mapping-2', $this->value("SELECT mapping_ref FROM billium_usage_fact WHERE metric_version='api_calls/1'"));
        self::assertSame('new-payer', $this->value("SELECT billing_account_ref FROM billium_usage_fact WHERE metric_version='api_calls/1'"));
        self::assertSame(2, $this->rows('billium_usage_fact'));
        self::assertSame(2, $this->rows('billium_idempotency_receipt', "command_kind='usage.fact_submit'"));
        $this->assertNoMoney();
    }

    #[DataProvider('pinMismatches')]
    public function test_v2_refuses_host_approved_facts_that_do_not_match_the_complete_metric_pin(string $field): void
    {
        $metric = self::metric('attributionPolicy', change: static function (stdClass $raw) use ($field): void {
            if ($field === 'mappingRef') {
                $raw->attributionPolicy->mappingRef = 'other-mapping';
            } elseif ($field === 'agreementRevision') {
                $raw->attributionPolicy->attribution->{$field} = 2;
            } elseif ($field === 'effectiveAt') {
                $raw->attributionPolicy->attribution->{$field} = '2026-09-01T00:00:00Z';
            } else {
                $raw->attributionPolicy->attribution->{$field} = 'other-'.$field;
            }
        });
        $this->usage()->registerMetric($metric);
        $this->refused(fn (): UsageReceiptV2 => $this->usage()->submitUsageFact(self::fact($metric)));
        self::assertSame(0, $this->rows('billium_usage_fact'));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind='usage.fact_submit'"));
        $this->assertNoMoney();
    }

    /** @return array<string, array{string}> */
    public static function pinMismatches(): array
    {
        $result = [];
        foreach (['mappingRef', 'subjectRef', 'customerRef', 'billingAccountRef', 'componentRef', 'agreementRef', 'agreementRevision', 'effectiveAt'] as $field) {
            $result[$field] = [$field];
        }

        return $result;
    }

    public function test_v2_cannot_pin_an_unregistered_attribution_snapshot_version(): void
    {
        $this->refused(fn (): MetricVersion => self::metric('attributionPolicy', change: static function (stdClass $raw): void {
            $raw->attributionPolicy->attribution->attributionVersion = 'host_snapshot/2';
        }));
        self::assertSame(1, $this->rows('billium_usage_metric'));
        self::assertSame(0, $this->rows('billium_usage_fact'));
        $this->assertNoMoney();
    }

    public function test_explicit_metric_pin_still_requires_host_approval_and_valid_event_interval(): void
    {
        $metric = self::metric('attributionPolicy');
        $this->usage()->registerMetric($metric);
        $this->mappings->unresolved = true;
        $this->refused(fn (): UsageReceiptV2 => $this->usage()->submitUsageFact(self::fact($metric)));
        $this->mappings->unresolved = false;
        $this->mappings->cutoff = '2026-10-20T00:00:00Z';
        $this->refused(fn (): UsageReceiptV2 => $this->usage()->submitUsageFact(self::fact($metric)));
        $this->mappings->cutoff = '2027-01-01T00:00:00Z';
        $this->refused(fn (): UsageReceiptV2 => $this->usage()->submitUsageFact(self::fact($metric, static function (stdClass $raw): void {
            $raw->interval->end = '2026-12-01T00:00:00Z';
        })));
        self::assertSame(0, $this->rows('billium_usage_fact'));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind='usage.fact_submit'"));
        $this->assertNoMoney();
    }

    #[DataProvider('revisionBounds')]
    public function test_v2_accepts_contiguous_replacements_through_its_bound_and_refuses_the_next_revision(int $maximum): void
    {
        $metric = self::metric('correctionPolicy', $maximum);
        $this->usage()->registerMetric($metric);
        $fact = self::fact($metric);
        for ($revision = 1; $revision <= $maximum; $revision++) {
            $receipt = $this->usage()->submitUsageFact($fact);
            self::assertSame($revision, $receipt->revision);
            if ($revision < $maximum) {
                $fact = self::correction($fact, (string) (10 - $revision));
            }
        }
        $this->refused(fn (): UsageReceiptV2 => $this->usage()->submitUsageFact(self::correction($fact, '1')));
        self::assertSame($maximum, $this->rows('billium_usage_fact'));
        self::assertSame($maximum, $this->rows('billium_idempotency_receipt', "command_kind='usage.fact_submit'"));
        self::assertSame($maximum, $this->value('SELECT max(revision) FROM billium_usage_fact'));
        self::assertSame($fact->digest, $this->value('SELECT digest FROM billium_usage_fact ORDER BY revision DESC LIMIT 1'));
        self::assertEquals($receipt, $this->usage()->submitUsageFact($fact));
        $this->assertNoMoney();
    }

    /** @return array<string, array{int}> */
    public static function revisionBounds(): array
    {
        return ['first revision is the bound' => [1], 'multiple full replacements' => [3]];
    }

    public function test_v2_keeps_predecessor_and_immutable_chain_checks_while_v1_has_no_new_ceiling(): void
    {
        $metric = self::metric('correctionPolicy', 3);
        $this->usage()->registerMetric($metric);
        $one = self::fact($metric);
        $two = self::correction($one, '9');
        $three = self::correction($two, '8');
        $this->refused(fn (): UsageReceiptV2 => $this->usage()->submitUsageFact($two));
        $this->usage()->submitUsageFact($one);
        $this->refused(fn (): UsageReceiptV2 => $this->usage()->submitUsageFact($three), ProblemCode::UsageConflict);
        $invalid = self::fact($metric, static function (stdClass $raw) use ($one): void {
            $raw->revision = 2;
            $raw->correction = (object) ['correctionRef' => 'bad-chain', 'supersedesRevision' => 1, 'originalFactDigest' => $one->digest];
            $raw->interval->start = '2026-10-02T00:00:00Z';
        });
        $this->refused(fn (): UsageReceiptV2 => $this->usage()->submitUsageFact($invalid), ProblemCode::UsageConflict);
        $this->usage()->submitUsageFact($two);
        $this->usage()->submitUsageFact($three);
        $old = UsageFixtures::fact();
        for ($revision = 1; $revision <= 4; $revision++) {
            $this->usage()->submitUsageFact($old);
            $old = self::correction($old, (string) $revision);
        }
        self::assertSame(3, $this->rows('billium_usage_fact', 'metric_version=?', [$metric->metricVersion]));
        self::assertSame(4, $this->rows('billium_usage_fact', "metric_version='api_calls/1'"));
        $this->assertNoMoney();
    }

    #[DataProvider('policies')]
    public function test_policy_parameters_affect_the_digest_and_cannot_mutate_registered_metric_versions(string $policy): void
    {
        $first = self::metric($policy);
        $this->usage()->registerMetric($first);
        $this->usage()->submitUsageFact(self::fact($first));
        $changed = self::metric($policy, 3, static function (stdClass $raw) use ($policy): void {
            if ($policy === 'attributionPolicy') {
                $raw->attributionPolicy->mappingRef = 'different-explicit-pin';
            }
        });
        self::assertNotSame($first->digest, $changed->digest);
        $this->refused(fn () => $this->usage()->registerMetric($changed), ProblemCode::IdempotencyConflict);
        self::assertSame($first->digest, $this->value('SELECT digest FROM billium_usage_metric WHERE metric_version=?', [$first->metricVersion]));
        self::assertSame(2, $this->rows('billium_usage_metric'));
        self::assertSame(1, $this->rows('billium_usage_fact'));
        $this->assertNoMoney();
    }

    /** @return array<string, array{string}> */
    public static function policies(): array
    {
        return ['COV-197 accepted attribution v2' => ['attributionPolicy'], 'COV-198 accepted correction v2' => ['correctionPolicy']];
    }

    /** @param (callable(stdClass):void)|null $change */
    private static function metric(string $policy, int $maximum = 2, ?callable $change = null): MetricVersion
    {
        return UsageFixtures::metric(static function (stdClass $raw) use ($policy, $maximum, $change): void {
            $raw->metricVersion = 'api_calls/2-'.$policy;
            $raw->{$policy}->version = 2;
            if ($policy === 'attributionPolicy') {
                $raw->attributionPolicy->mappingRef = 'mapping-1';
                $raw->attributionPolicy->attribution = clone UsageFixtures::fact()->attribution;
            } else {
                $raw->correctionPolicy->maxRevision = $maximum;
            }
            if ($change !== null) {
                $change($raw);
            }
        });
    }

    /** @param (callable(stdClass):void)|null $change */
    private static function fact(MetricVersion $metric, ?callable $change = null): UsageFactV2
    {
        return UsageFixtures::fact(static function (stdClass $raw) use ($metric, $change): void {
            $raw->factId = 'policy-v2-'.$metric->metricVersion;
            $raw->metricVersion = $metric->metricVersion;
            $raw->metricDigest = $metric->digest;
            if ($change !== null) {
                $change($raw);
            }
        });
    }

    private static function correction(UsageFactV2 $previous, string $quantity): UsageFactV2
    {
        $raw = UsageFixtures::correction($previous, $quantity)->raw;
        self::assertInstanceOf(stdClass::class, $raw->correction);
        $raw->correction->correctionRef = $previous->factId.'/correction-'.$raw->revision;
        $codec = new Codec;
        $raw->digest = $codec->digest($raw);
        $fact = $codec->decode($codec->canonicalize($raw));
        self::assertInstanceOf(UsageFactV2::class, $fact);

        return $fact;
    }

    private function refused(callable $operation, ProblemCode $expected = ProblemCode::ValidationInvalid): void
    {
        try {
            $operation();
            self::fail('unsupported usage policy input was admitted');
        } catch (ProblemException $error) {
            self::assertSame($expected, $error->problem, $error->detail);
        }
    }

    private function assertNoMoney(): void
    {
        self::assertSame(0, $this->rows('billium_obligation'));
        self::assertSame(0, $this->rows('billium_money_commit_log'));
        self::assertSame(0, $this->rows('billium_outbox_event'));
    }
}

<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\UsageFact;
use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\Support\FixtureValues;
use Billium\Usage\Contracts\UsageFactV1Attribution;
use Billium\Usage\Data\UsageFactV1Inputs;
use Billium\Usage\Internal\UsageQueries;
use Billium\Usage\Tests\Support\UsageFixtures;
use Billium\Usage\Tests\UsageTestCase;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

#[Group('pg')]
final class UsageAdmissionTest extends UsageTestCase
{
    public function test_v1_fact_pins_original_attribution_and_replays_without_the_resolver(): void
    {
        $v2 = UsageFixtures::fact();
        $caller = new Caller('seller-a', 'test', self::INSTALLATION, 'courier', ['tariffler']);
        $this->callers->set($caller);
        $snapshot = $this->mappings->resolve($caller, $v2);
        self::assertNotNull($snapshot);
        $inputs = $this->createMock(UsageFactV1Attribution::class);
        $inputs->expects(self::exactly(2))->method('resolve')->with($caller, self::isInstanceOf(UsageFact::class))
            ->willReturnCallback(static fn (Caller $caller, UsageFact $fact): UsageFactV1Inputs => new UsageFactV1Inputs(
                $snapshot, $v2->provenance->sourceDigest, (object) ['kind' => 'decimal', 'quantity' => $fact->revision === 1 ? '10' : '999'],
                $v2->grouping, $v2->billableStream, $fact->revision === 2 ? 'correction-2' : null));
        $codec = new Codec;
        $raw = ['schema' => UsageFact::SCHEMA, 'source' => $v2->source, 'factId' => $v2->factId, 'revision' => $v2->revision,
            'sellerRef' => $v2->sellerRef, 'subjectRef' => $v2->attribution->subjectRef, 'metricRef' => $v2->metricRef,
            'metricVersion' => $v2->metricVersion, 'semantics' => 'additive', 'unit' => $v2->unit, 'quantity' => '10.0000000000',
            'interval' => $v2->interval, 'eventAt' => $v2->eventAt, 'observedAt' => $v2->eventAt,
            'provenance' => (object) ['collector' => $v2->provenance->collector, 'version' => $v2->provenance->version, 'sourceRef' => $v2->provenance->sourceRef]];
        $fact = $codec->decode($codec->canonicalize($raw + ['digest' => $codec->digest($raw)]));
        self::assertInstanceOf(UsageFact::class, $fact);
        self::assertSame('accepted', $this->usage()->acceptUsageFact($fact, $inputs)->result);
        self::assertSame('duplicate', $this->usage()->acceptUsageFact($fact, $inputs)->result);
        $raw['quantity'] = '999';
        $changed = $codec->decode($codec->canonicalize($raw + ['digest' => $codec->digest($raw)]));
        self::assertInstanceOf(UsageFact::class, $changed);
        self::assertSame('conflict', $this->usage()->acceptUsageFact($changed, $inputs)->result);
        self::assertSame(1, $this->rows('billium_usage_fact'));
        self::assertSame($snapshot->mappingRef, $this->value('SELECT mapping_ref FROM billium_usage_fact'));
        self::assertSame($snapshot->periodRef, $this->value('SELECT period_ref FROM billium_usage_fact'));
        $originalDigest = $this->value('SELECT digest FROM billium_usage_fact');
        $raw['revision'] = 2;
        $raw['correctsFactId'] = $fact->factId;
        $correction = $codec->decode($codec->canonicalize($raw + ['digest' => $codec->digest($raw)]));
        self::assertInstanceOf(UsageFact::class, $correction);
        self::assertSame('accepted', $this->usage()->acceptUsageFact($correction, $inputs)->result);
        self::assertSame($originalDigest, $this->value("SELECT body::jsonb#>>'{correction,originalFactDigest}' FROM billium_usage_fact WHERE revision = 2"));
        self::assertSame(2, $this->rows('billium_usage_fact', "mapping_ref = 'mapping-1'"));
        self::assertSame(0, $this->rows('billium_money_commit_log'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function pinDivergences(): iterable
    {
        yield 'semantics differ from the metric model' => [['semantics' => 'gauge'], '10'];
        yield 'subject differs from the pinned attribution' => [['subjectRef' => 'another-subject'], '10'];
        yield 'quantity differs from the pinned value' => [[], '11'];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('pinDivergences')]
    public function test_v1_fact_that_differs_from_its_pinned_input_is_refused(array $changes, string $pinnedQuantity): void
    {
        $v2 = UsageFixtures::fact();
        $caller = new Caller('seller-a', 'test', self::INSTALLATION, 'courier', ['tariffler']);
        $this->callers->set($caller);
        $snapshot = $this->mappings->resolve($caller, $v2);
        self::assertNotNull($snapshot);
        $inputs = $this->createMock(UsageFactV1Attribution::class);
        $inputs->method('resolve')->willReturn(new UsageFactV1Inputs($snapshot, $v2->provenance->sourceDigest,
            (object) ['kind' => 'decimal', 'quantity' => $pinnedQuantity], $v2->grouping, $v2->billableStream));
        $codec = new Codec;
        $raw = array_replace(['schema' => UsageFact::SCHEMA, 'source' => $v2->source, 'factId' => $v2->factId, 'revision' => $v2->revision,
            'sellerRef' => $v2->sellerRef, 'subjectRef' => $v2->attribution->subjectRef, 'metricRef' => $v2->metricRef,
            'metricVersion' => $v2->metricVersion, 'semantics' => 'additive', 'unit' => $v2->unit, 'quantity' => '10',
            'interval' => $v2->interval, 'eventAt' => $v2->eventAt, 'observedAt' => $v2->eventAt,
            'provenance' => (object) ['collector' => $v2->provenance->collector, 'version' => $v2->provenance->version, 'sourceRef' => $v2->provenance->sourceRef]], $changes);
        $fact = $codec->decode($codec->canonicalize($raw + ['digest' => $codec->digest($raw)]));
        self::assertInstanceOf(UsageFact::class, $fact);
        try {
            $this->usage()->acceptUsageFact($fact, $inputs);
            self::fail('A v1 fact that differs from its pinned input was accepted');
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::ValidationInvalid, $problem->problem);
            self::assertSame('Usage fact differs from the pinned commercial input', $problem->detail);
        }
        self::assertSame(0, $this->rows('billium_usage_fact'));
    }

    public function test_same_fact_from_ten_couriers_returns_one_stored_receipt_and_no_money(): void
    {
        $first = $this->usage()->submitUsageFact(UsageFixtures::fact());
        $this->mappings->unresolved = true;
        for ($i = 0; $i < 10; $i++) {
            $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'courier-'.$i, ['tariffler']));
            $next = $this->usage()->submitUsageFact(UsageFixtures::fact());
            self::assertSame((new Codec)->encode($first), (new Codec)->encode($next));
        }
        self::assertSame(1, $this->rows('billium_usage_fact'));
        self::assertSame(1, $this->rows('billium_idempotency_receipt', "command_kind='usage.fact_submit'"));
        self::assertSame(0, $this->rows('billium_obligation'));
        self::assertSame(0, $this->rows('billium_money_commit_log'));
        self::assertSame(0, $this->rows('billium_outbox_event'));
        self::assertSame('accepted', $first->result);
    }

    public function test_changed_digest_conflicts_and_cannot_rebind_the_fact(): void
    {
        $this->usage()->submitUsageFact(UsageFixtures::fact());
        $this->refused(fn () => $this->usage()->submitUsageFact(UsageFixtures::fact(function (stdClass $raw): void {
            $raw->value->quantity = '999';
        })), ProblemCode::IdempotencyConflict);
        self::assertSame(1, $this->rows('billium_usage_fact'));
    }

    public function test_missing_and_out_of_order_predecessors_can_be_retried_without_rewriting_history(): void
    {
        $one = UsageFixtures::fact();
        $two = UsageFixtures::correction($one);
        $three = UsageFixtures::correction(previous: $two, quantity: '8');
        $this->refused(fn () => $this->usage()->submitUsageFact($three));
        $this->refused(fn () => $this->usage()->submitUsageFact($two));
        self::assertSame(0, $this->rows('billium_usage_fact'));
        $this->usage()->submitUsageFact($one);
        $this->refused(fn () => $this->usage()->submitUsageFact($three), ProblemCode::UsageConflict);
        $this->usage()->submitUsageFact($two);
        $this->usage()->submitUsageFact($three);
        self::assertSame(3, $this->rows('billium_usage_fact'));
        self::assertSame(0, $this->rows('billium_obligation'));
        $rows = $this->application()->make(UsageQueries::class)->period(subject: 'subject-1', metricVersion: 'api_calls/1', mappingRef: 'mapping-1', start: '2026-10-01T00:00:00Z', end: '2026-11-01T00:00:00Z');
        self::assertCount(1, $rows);
        self::assertInstanceOf(stdClass::class, $rows[0]);
        self::assertSame(3, $rows[0]->revision);
        self::assertSame('8.000000000', $rows[0]->quantity);
    }

    public function test_unknown_metric_and_semantic_mismatches_leave_no_facts_or_financial_rows(): void
    {
        $changes = [static function (stdClass $r): void {
            $r->metricVersion = 'unknown/1';
        }, static function (stdClass $r): void {
            $r->metricDigest = 'sha256:'.str_repeat('0', 64);
        }, static function (stdClass $r): void {
            $r->unit = 'event';
        }, static function (stdClass $r): void {
            $r->grouping = [];
        }, static function (stdClass $r): void {
            $r->value = (object) ['kind' => 'count', 'quantity' => '1'];
        }, static function (stdClass $r): void {
            self::assertInstanceOf(stdClass::class, $r->billableStream);
            $r->billableStream->mode = 'gross_usage';
        }];
        foreach ($changes as $change) {
            $this->refused(fn () => $this->usage()->submitUsageFact(UsageFixtures::fact($change)));
        }
        self::assertSame(0, $this->rows('billium_usage_fact'));
        self::assertSame(0, $this->rows('billium_obligation'));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind='usage.fact_submit'"));
    }

    public function test_late_event_uses_original_period_and_frozen_payer_at_cutoff(): void
    {
        $this->mappings->cutoff = '2026-10-20T00:00:00Z';
        $old = UsageFixtures::fact(function (stdClass $r): void {
            $r->interval->end = '2026-10-20T00:00:00Z';
            $r->receivedAt = '2026-12-01T00:00:00Z';
        });
        $this->usage()->submitUsageFact($old);
        $row = $this->row('SELECT * FROM billium_usage_fact');
        self::assertSame('original-account', $row['billing_account_ref']);
        self::assertSame('mapping-1', $row['mapping_ref']);
        self::assertSame('october-2026', $row['period_ref']);
        $rebound = UsageFixtures::correction($old)->raw;
        self::assertInstanceOf(stdClass::class, $rebound->attribution);
        $rebound->attribution->billingAccountRef = 'new-payer';
        $codec = new Codec;
        $rebound->digest = $codec->digest($rebound);
        $this->refused(fn () => $this->usage()->submitUsageFact(FixtureValues::instance($codec->decode($codec->canonicalize($rebound)), UsageFactV2::class)));
        self::assertSame(1, $this->rows('billium_usage_fact'));
        $this->usage()->submitUsageFact(UsageFixtures::fact(function (stdClass $r): void {
            $r->factId = 'after-transfer';
            $r->eventAt = '2026-10-21T00:00:00Z';
            $r->interval->start = '2026-10-20T00:00:00Z';
            $r->attribution->effectiveAt = '2026-10-20T00:00:00Z';
            $r->attribution->billingAccountRef = 'new-payer';
            $r->attribution->customerRef = 'new-customer';
        }));
        self::assertSame('new-payer', $this->value("SELECT billing_account_ref FROM billium_usage_fact WHERE fact_id='after-transfer'"));
        self::assertSame('original-account', $this->value("SELECT billing_account_ref FROM billium_usage_fact WHERE fact_id='fact-1'"));
    }

    public function test_unresolved_attribution_and_spanning_period_or_mapping_are_refused(): void
    {
        $this->mappings->unresolved = true;
        $this->refused(fn () => $this->usage()->submitUsageFact(UsageFixtures::fact()));
        $this->mappings->unresolved = false;
        $this->mappings->cutoff = '2026-10-20T00:00:00Z';
        $this->refused(fn () => $this->usage()->submitUsageFact(UsageFixtures::fact()));
        $this->mappings->cutoff = '2027-01-01T00:00:00Z';
        $this->refused(fn () => $this->usage()->submitUsageFact(UsageFixtures::fact(function (stdClass $r): void {
            $r->interval->end = '2026-12-01T00:00:00Z';
        })));
        self::assertSame(0, $this->rows('billium_usage_fact'));
    }

    public function test_metric_is_immutable_and_gross_plus_net_cannot_share_a_basis(): void
    {
        $this->usage()->registerMetric(UsageFixtures::metric());
        $this->refused(fn () => $this->usage()->registerMetric(UsageFixtures::metric(function (stdClass $r): void {
            $r->unit = 'event';
        })), ProblemCode::IdempotencyConflict);
        $this->refused(fn () => $this->usage()->registerMetric(UsageFixtures::metric(function (stdClass $r): void {
            $r->metricVersion = 'api_calls/2';
            self::assertInstanceOf(stdClass::class, $r->billableStream);
            $r->billableStream->mode = 'gross_usage';
        })), ProblemCode::UsageConflict);
        self::assertSame(1, $this->rows('billium_usage_metric'));
    }

    public function test_sellers_are_isolated_even_with_identical_source_and_fact_keys(): void
    {
        $this->usage()->submitUsageFact(UsageFixtures::fact());
        $this->callers->as('seller-b');
        $this->refused(fn () => $this->usage()->submitUsageFact(UsageFixtures::fact()), ProblemCode::AuthDenied);
        $this->usage()->registerMetric(UsageFixtures::metric(function (stdClass $r): void {
            $r->sellerRef = 'seller-b';
        }));
        $this->usage()->submitUsageFact(UsageFixtures::fact(function (stdClass $r): void {
            $r->sellerRef = 'seller-b';
            $r->metricDigest = UsageFixtures::metric(function (stdClass $m): void {
                $m->sellerRef = 'seller-b';
            })->digest;
        }));
        self::assertSame(2, $this->rows('billium_usage_fact'));
        $rows = $this->application()->make(UsageQueries::class)->period(subject: 'subject-1', metricVersion: 'api_calls/1', mappingRef: 'mapping-1', start: '2026-10-01T00:00:00Z', end: '2026-11-01T00:00:00Z');
        self::assertCount(1, $rows);
        self::assertInstanceOf(stdClass::class, $rows[0]);
        self::assertSame('seller-b', $rows[0]->seller_ref);
    }

    public function test_unauthorized_source_cannot_replay_a_receipt(): void
    {
        $this->usage()->submitUsageFact(UsageFixtures::fact());
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'untrusted'));
        $this->refused(fn () => $this->usage()->submitUsageFact(UsageFixtures::fact()), ProblemCode::AuthDenied);
    }

    public function test_correction_cannot_change_metric_or_reference_another_digest_or_cycle(): void
    {
        $one = UsageFixtures::fact();
        $this->usage()->submitUsageFact($one);
        $metric = UsageFixtures::metric(function (stdClass $r): void {
            $r->metricRef = 'other';
            $r->metricVersion = 'other/1';
        });
        $this->usage()->registerMetric($metric);
        $codec = new Codec;
        foreach (['metric', 'digest', 'cycle', 'seller'] as $case) {
            $raw = UsageFixtures::correction($one)->raw;
            self::assertInstanceOf(stdClass::class, $raw->correction);
            if ($case === 'metric') {
                $raw->metricRef = $metric->metricRef;
                $raw->metricVersion = $metric->metricVersion;
                $raw->metricDigest = $metric->digest;
            }
            if ($case === 'digest') {
                $raw->correction->originalFactDigest = 'sha256:'.str_repeat('0', 64);
            }
            if ($case === 'cycle') {
                $raw->correction->supersedesRevision = 2;
            }
            if ($case === 'seller') {
                $raw->sellerRef = 'seller-b';
            }
            $raw->digest = $codec->digest($raw);
            $expected = match ($case) {
                'cycle' => ProblemCode::ValidationInvalid,
                'seller' => ProblemCode::AuthDenied,
                default => ProblemCode::UsageConflict,
            };
            $this->refused(fn () => $this->usage()->submitUsageFact(FixtureValues::instance($codec->decode($codec->canonicalize($raw)), UsageFactV2::class)), $expected);
        }
        self::assertSame(1, $this->rows('billium_usage_fact'));
    }

    public function test_database_backstops_refuse_updates_and_deletes_even_for_owner(): void
    {
        $this->usage()->submitUsageFact(UsageFixtures::fact());
        foreach (['billium_usage_metric', 'billium_usage_fact', 'billium_usage_stream'] as $table) {
            foreach (["DELETE FROM {$table}", "UPDATE {$table} SET seller_ref='other'"] as $sql) {
                try {
                    $this->owner()->statement($sql);
                    self::fail('immutable history was changed');
                } catch (QueryException $e) {
                    self::assertSame('55000', $e->getCode());
                }
            }
        }
    }

    private function refused(callable $operation, ProblemCode $expected = ProblemCode::ValidationInvalid): void
    {
        try {
            $operation();
            self::fail('invalid usage accepted');
        } catch (ProblemException $e) {
            self::assertSame($expected, $e->problem, $e->detail);
        }
    }
}

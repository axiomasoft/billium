<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Feature;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\Support\FixtureValues;
use Billium\Schedule\Contracts\CommercialPeriods;
use Billium\Schedule\Contracts\IntervalResolver;
use Billium\Schedule\Data\PeriodPolicy;
use Billium\Schedule\Data\ResolvedInterval;
use Billium\Schedule\Data\ScheduleRevisionTerms;
use Billium\Schedule\Tests\ScheduleTestCase;
use Billium\Schedule\Tests\Support\Terms;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class CommercialPeriodPersistenceTest extends ScheduleTestCase
{
    public function test_persisted_quote_replay_and_commit_never_recalculate_boundaries(): void
    {
        $terms = Terms::make();
        $quote = $this->quote($terms);
        $this->application()->instance(IntervalResolver::class, new class implements IntervalResolver
        {
            public function resolve(ScheduleRevisionTerms $terms, int $ordinal): ResolvedInterval
            {
                throw new LogicException('current tzdb must not recalculate the stored quote');
            }
        });
        self::assertEquals($quote->body(), $this->quote($terms)->body());
        /** @var array{period: array{start: string, end: string, evidence: array{policy: array{timezone: string}}}, coverageRef: string, occurrenceRef: string, externalCorrelationRef: string, fundingRevision: int, periodRef: string} $receipt */
        $receipt = $this->commitQuote($quote)->body();
        self::assertEquals($quote->period->toArray(), $receipt['period']);
        self::assertEquals($quote->period->toArray(), json_decode(self::text($this->value('SELECT body FROM billium_schedule_period')), true));
        self::assertSame($quote->period->digest(), $this->value('SELECT period_digest FROM billium_schedule_period'));
        $wire = $this->application()->make(CommercialPeriods::class)->fromQuote($quote);
        self::assertSame($wire->periodRef, $receipt['periodRef']);
        $this->clock->instant = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $this->host->cancelled = true;
        self::assertEquals($receipt, $this->commitQuote($quote)->body());
        self::assertEquals($receipt, $this->commitQuote(quote: $quote, key: 'transport-retry')->body());
        self::assertEquals($receipt, $this->schedule()->coverage(FixtureValues::text($receipt['coverageRef']))?->body());
        self::assertSame(1, $this->rows('billium_schedule_period'));
        self::assertSame(1, $this->host->checks);
    }

    public function test_future_timezone_revision_preserves_past_evidence_and_explicit_anchor(): void
    {
        /** @var array{period: array{start: string, end: string, evidence: array{policy: array{timezone: string}}}, coverageRef: string, occurrenceRef: string, externalCorrelationRef: string, fundingRevision: int, periodRef: string} $first */
        $first = $this->commitQuote($this->quote(Terms::make()))->body();
        $terms = Terms::make(['agreementRevision' => 2, 'anchorLocal' => '2024-02-28T19:00:00', 'policy' => new PeriodPolicy(timezone: 'America/New_York', frequency: 'month'), 'anchorPolicy' => 'reanchor_explicit']);
        /** @var array{period: array{start: string, end: string, evidence: array{policy: array{timezone: string}}}, coverageRef: string, occurrenceRef: string, externalCorrelationRef: string, fundingRevision: int, periodRef: string} $second */
        $second = $this->commitQuote(quote: $this->quote(terms: $terms, key: 'zone-revision'), key: 'zone-revision')->body();
        self::assertSame($first['period']['end'], $second['period']['start']);
        self::assertEquals($first, $this->schedule()->coverage(FixtureValues::text($first['coverageRef']))?->body());
        self::assertSame('America/New_York', $second['period']['evidence']['policy']['timezone']);
    }

    public function test_future_boundary_pause_resume_retains_anchor_without_grant_work(): void
    {
        /** @var array{period: array{start: string, end: string, evidence: array{policy: array{timezone: string}}}, coverageRef: string, occurrenceRef: string, externalCorrelationRef: string, fundingRevision: int, periodRef: string} $first */
        $first = $this->commitQuote($this->quote(Terms::make()))->body();
        $pause = $this->quote(terms: Terms::make(['agreementRevision' => 2, 'action' => 'pause']), ordinal: 1, key: 'pause');
        $paused = $this->commitQuote(quote: $pause, key: 'pause')->body();
        self::assertNull($paused['coverageRef']);
        self::assertNull($paused['occurrenceRef']);
        self::assertSame(1, $this->rows('billium_schedule_period'));
        $resume = $this->quote(terms: Terms::make(['agreementRevision' => 3, 'action' => 'resume']), ordinal: 3, key: 'resume');
        /** @var array{period: array{start: string, end: string, evidence: array{policy: array{timezone: string}}}, coverageRef: string, occurrenceRef: string, externalCorrelationRef: string, fundingRevision: int, periodRef: string} $resumed */
        $resumed = $this->commitQuote(quote: $resume, key: 'resume')->body();
        self::assertSame('2024-04-30T00:00:00Z', $resumed['period']['start']);
        self::assertSame('2024-05-31T00:00:00Z', $resumed['period']['end']);
        self::assertSame(2, $this->rows('billium_schedule_period'));
        self::assertEquals($first, $this->schedule()->coverage(FixtureValues::text($first['coverageRef']))?->body());
    }

    public function test_annual_funding_replays_and_reordering_keep_one_coverage_and_host_monthly_keys(): void
    {
        $terms = Terms::make(['policy' => new PeriodPolicy(timezone: 'Etc/UTC', frequency: 'year'), 'fundingRevision' => 7]);
        $quote = $this->quote($terms);
        /** @var array{period: array{start: string, end: string, evidence: array{policy: array{timezone: string}}}, coverageRef: string, occurrenceRef: string, externalCorrelationRef: string, fundingRevision: int, periodRef: string} $receipt */
        $receipt = $this->commitQuote($quote)->body();
        // External consumers own obligation booking and monthly grants; this double checks stable correlation only.
        $hostObligations = [];
        $hostGrantKeys = [];
        foreach ([11, 0, 4, 4, 3, 1, 2, 8, 7, 6, 5, 10, 9, 0] as $i => $month) {
            $notice = $this->commitQuote(quote: $quote, key: 'funding-replay-'.$i)->body();
            self::assertSame($receipt['coverageRef'], $notice['coverageRef']);
            self::assertSame(7, $notice['fundingRevision']);
            $hostObligations[FixtureValues::text($notice['occurrenceRef'])] = 'host-booking:annual';
            $hostGrantKeys[FixtureValues::text($notice['externalCorrelationRef']).':month:'.$month] = FixtureValues::text($notice['coverageRef']);
        }
        $this->host->cancelled = true;
        // Funding reversal/cancellation does not create or revoke host grants here.
        self::assertEquals($receipt, $this->schedule()->coverage(FixtureValues::text($receipt['coverageRef']))?->body());
        self::assertCount(1, $hostObligations);
        self::assertCount(12, $hostGrantKeys);
        self::assertSame(1, $this->rows('billium_schedule_period'));
        self::assertSame(1, $this->rows('billium_schedule_revision'));
        self::assertSame(0, $this->rows('billium_obligation'));
    }

    public function test_foreign_seller_cannot_read_coverage_or_commit_quote(): void
    {
        $quote = $this->quote(Terms::make());
        /** @var array{period: array{start: string, end: string, evidence: array{policy: array{timezone: string}}}, coverageRef: string, occurrenceRef: string, externalCorrelationRef: string, fundingRevision: int, periodRef: string} $receipt */
        $receipt = $this->commitQuote($quote)->body();
        $this->callers->set(new Caller(sellerRef: 'seller-b', environment: 'test', installationRef: self::INSTALLATION, actorRef: 'foreign'));
        self::assertNull($this->schedule()->coverage(FixtureValues::text($receipt['coverageRef'])));
        $this->expectException(ProblemException::class);
        $this->expectExceptionMessage('quote_unknown');
        $this->commitQuote(quote: $quote, key: 'foreign');
    }

    public function test_quote_cutoff_is_enforced_before_persisting(): void
    {
        $terms = Terms::make(['policy' => new PeriodPolicy(timezone: 'Etc/UTC', frequency: 'month', cutoffSeconds: 86400)]);
        try {
            $this->schedule()->preview(terms: $terms, ordinal: 0, validUntil: '2024-01-30T12:00:00Z', commandKey: 'past-cutoff');
            self::fail('quote beyond cutoff was accepted');
        } catch (ProblemException $exception) {
            self::assertStringContainsString('cutoff', $exception->detail);
        }
        self::assertSame(0, $this->rows('billium_schedule_quote'));
    }
}

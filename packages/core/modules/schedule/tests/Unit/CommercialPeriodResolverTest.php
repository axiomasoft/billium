<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Unit;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Schedule\Data\PeriodPolicy;
use Billium\Schedule\Data\PeriodQuote;
use Billium\Schedule\Data\ScheduleRevisionTerms;
use Billium\Schedule\Internal\CalendarIntervalResolver;
use Billium\Schedule\Internal\CommercialPeriodExport;
use Billium\Schedule\Tests\Support\Terms;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommercialPeriodResolverTest extends TestCase
{
    public static function boundaries(): iterable
    {
        $path = dirname(__DIR__, 6).'/fixtures/vectors/schedule/periods/calendar-cases.json';
        foreach (json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR)['given']['cases'] as $case) {
            yield $case['id'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('boundaries')]
    public function test_explicit_calendar_boundaries(array $case): void
    {
        $policy = new PeriodPolicy(...$case['policy']);
        $terms = Terms::make(['anchorLocal' => $case['anchor'], 'policy' => $policy]);
        $resolver = new CalendarIntervalResolver;
        if (isset($case['refusal'])) {
            $this->expectException(ProblemException::class);
            $this->expectExceptionMessage($case['refusal']);
        }
        $period = $resolver->resolve(terms: $terms, ordinal: $case['ordinal']);
        self::assertSame($case['start'], $period->start);
        self::assertSame($case['end'], $period->end);
        if (isset($case['resolvedStart'])) {
            self::assertSame($case['resolvedStart'], $period->evidence['start']['resolvedLocal']);
        }
        self::assertTrue($period->contains(new DateTimeImmutable($period->start)));
        self::assertFalse($period->contains(new DateTimeImmutable($period->end)));
    }

    public function test_january_anchor_survives_short_months_and_replay_ignores_process_timezone(): void
    {
        $resolver = new CalendarIntervalResolver;
        $terms = Terms::make();
        $original = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Honolulu');
            $january = $resolver->resolve(terms: $terms, ordinal: 0);
            $february = $resolver->resolve(terms: $terms, ordinal: 1);
            date_default_timezone_set('Asia/Tokyo');
            $march = $resolver->resolve(terms: $terms, ordinal: 2);
            self::assertSame($january->end, $february->start);
            self::assertSame($february->end, $march->start);
            self::assertSame('2024-03-31T00:00:00Z', $march->start);
            self::assertSame($february->digest(), $resolver->resolve(terms: $terms, ordinal: 1)->digest());
            self::assertNotSame(30 * 86400, (new DateTimeImmutable($january->end))->getTimestamp() - (new DateTimeImmutable($january->start))->getTimestamp());
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_cutoff_and_wire_export_use_frozen_boundary_evidence(): void
    {
        $terms = Terms::make(['policy' => new PeriodPolicy(timezone: 'Etc/UTC', frequency: 'month', cutoffSeconds: 3600)]);
        $period = (new CalendarIntervalResolver)->resolve(terms: $terms, ordinal: 0);
        self::assertSame('2024-01-30T23:00:00Z', $period->cutoffAt);
        $quote = new PeriodQuote(terms: $terms, ordinal: 0, period: $period, issuedAt: '2024-01-01T00:00:00Z', validUntil: '2024-01-20T00:00:00Z');
        $wire = (new CommercialPeriodExport(new Codec))->fromQuote($quote);
        self::assertSame($period->start, $wire->interval->start);
        self::assertSame(31, $wire->anchor->day);
        self::assertSame($quote->digest, PeriodQuote::fromArray($quote->body())->digest);
    }

    public function test_non_wire_policies_refuse_export_without_weakening_frozen_contract(): void
    {
        $terms = Terms::make(['policy' => new PeriodPolicy(timezone: 'Etc/UTC', frequency: 'year', dstFoldPolicy: 'later_offset')]);
        $period = (new CalendarIntervalResolver)->resolve(terms: $terms, ordinal: 0);
        $quote = new PeriodQuote(terms: $terms, ordinal: 0, period: $period, issuedAt: '2024-01-01T00:00:00Z', validUntil: '2024-01-20T00:00:00Z');
        $this->expectException(ProblemException::class);
        $this->expectExceptionMessage('period_wire_policy_unsupported');
        (new CommercialPeriodExport(new Codec))->fromQuote($quote);
    }

    public function test_unknown_fixed_duration_and_proration_policies_are_not_approximated(): void
    {
        foreach ([['frequency' => 'fixed_30_days'], ['calendarPolicy' => 'process_timezone/1'], ['prorationPolicy' => 'midperiod/unknown']] as $override) {
            try {
                new PeriodPolicy(...['timezone' => 'Etc/UTC', 'frequency' => 'month', ...$override]);
                self::fail('unregistered policy accepted');
            } catch (ProblemException $exception) {
                self::assertStringContainsString('unsupported_period_policy', $exception->detail);
            }
        }
    }

    public function test_unknown_commercial_fields_are_refused_during_hydration(): void
    {
        $body = Terms::make()->toArray();
        $this->expectException(ProblemException::class);
        $this->expectExceptionMessage('unsupported_terms_fields');
        ScheduleRevisionTerms::fromArray([...$body, 'discount' => 'unregistered']);
    }
}

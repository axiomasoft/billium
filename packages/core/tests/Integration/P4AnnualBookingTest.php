<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Integration;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\FixtureValues;
use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Contracts\RatingBooking;
use Billium\Rating\Contracts\RatingPreparer;
use Billium\Rating\Contracts\TaxSnapshotPort;
use Billium\Rating\Data\Pricing\FixedTerms;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Pricing\QuantityRules;
use Billium\Rating\Data\Rating\BookRatedBasis;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Data\Rating\PrepareRating;
use Billium\Rating\Enums\BookingOutcome;
use Billium\Rating\Enums\IncrementMode;
use Billium\Rating\Enums\RoundingMode;
use Billium\Rating\Enums\RoundingStage;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Tests\Support\TestTaxPort;
use Billium\Schedule\Contracts\AgreementRevisionCommit;
use Billium\Schedule\Contracts\AgreementRevisionGuard;
use Billium\Schedule\Contracts\Clock;
use Billium\Schedule\Contracts\OccurrenceService;
use Billium\Schedule\Data\Commands\ApplyScheduleRevision;
use Billium\Schedule\Data\Commands\EnsureOccurrence;
use Billium\Schedule\Data\PeriodPolicy;
use Billium\Schedule\Enums\OccurrencePurpose;
use Billium\Schedule\Tests\Support\FrozenClock;
use Billium\Schedule\Tests\Support\HostGuard;
use Billium\Schedule\Tests\Support\Terms;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class P4AnnualBookingTest extends CompositionTestCase
{
    protected function modules(): array
    {
        return ['platform', 'ledger', 'rating', 'schedule'];
    }

    public function test_annual_money_books_once_while_external_monthly_grants_replay_and_reorder(): void
    {
        $app = $this->application();
        $host = new HostGuard;
        $clock = new FrozenClock(new DateTimeImmutable('2023-12-01T00:00:00Z'));
        $tax = new TestTaxPort;
        $tax->rate = '0.20';
        $app->instance(AgreementRevisionGuard::class, $host);
        $app->instance(Clock::class, $clock);
        $app->instance(TaxSnapshotPort::class, $tax);
        $price = $this->price(version: 1, amount: '120');
        $app->make(PriceVersionStore::class)->publish(price: $price, commandKey: 'annual-price');
        $terms = Terms::make(['anchorLocal' => '2024-01-01T00:00:00', 'purpose' => 'recurring_charge',
            'policy' => new PeriodPolicy(timezone: 'Etc/UTC', frequency: 'year'), 'fundingRevision' => 7,
            'priceVersionRef' => 'annual@1', 'monetaryTermsDigest' => $price->termsDigest]);
        $host->approved = $terms;
        $schedule = $app->make(AgreementRevisionCommit::class);
        $quote = $schedule->preview(terms: $terms, ordinal: 0, validUntil: '2023-12-20T00:00:00Z', commandKey: 'annual-preview');
        $notice = $schedule->commit(quoteRef: $quote->quoteRef, quoteDigest: $quote->digest,
            expectedRevision: 1, cancellationGeneration: 0, commandKey: 'annual-commit')->body();
        self::assertSame('2024-01-01T00:00:00Z', $quote->period->start);
        self::assertSame('2025-01-01T00:00:00Z', $quote->period->end);
        $policy = $quote->period->evidence['policy'];
        self::assertIsArray($policy);
        self::assertSame('year', $policy['frequency']);

        // The host bridges coverage and the monetary scheduler by their approved component and resolved interval.
        $digest = $terms->digest();
        $occurrences = $app->make(OccurrenceService::class);
        $occurrences->applyScheduleRevision(new ApplyScheduleRevision(sellerRef: 'seller-a', environment: 'test',
            scheduleRef: 'annual', expectedRevision: 0, expectedRowVersion: 0, newRevision: 1, terms: $terms,
            effectiveFrom: new DateTimeImmutable($quote->period->start), inputsDigest: $digest, commandKey: 'annual-scheduler'));
        $ensure = new EnsureOccurrence(sellerRef: 'seller-a', environment: 'test', componentRef: 'component-a',
            purpose: OccurrencePurpose::RecurringCharge, periodStart: new DateTimeImmutable($quote->period->start),
            periodEnd: new DateTimeImmutable($quote->period->end), scheduleRef: 'annual', scheduleRevision: 1,
            inputsDigest: $digest, commandKey: 'annual-occurrence');
        $occurrence = $occurrences->ensureOccurrence($ensure)->body();
        $ref = FixtureValues::text($occurrence['occurrenceRef']);
        $prepared = $app->make(RatingPreparer::class)->prepare(new PrepareRating(occurrenceRef: $ref,
            billingAccountRef: 'account-a', attribution: '{"customerRef":"customer-a","agreementRef":"agreement-a"}',
            agreementRevision: '1', occurrenceInputsDigest: $digest, sourceDigest: 'host:annual:1',
            components: [['componentRef' => 'component-a', 'snapshotRef' => 'fixed', 'priceRef' => 'annual', 'priceVersion' => 1]]));
        self::assertInstanceOf(PreparedRating::class, $prepared, json_encode($prepared, JSON_THROW_ON_ERROR));
        $booking = $app->make(RatingBooking::class);
        $first = $booking->book(new BookRatedBasis(prepared: $prepared, operationKey: 'annual-book'));
        self::assertSame(BookingOutcome::Booked, $first->outcome);
        self::assertSame('14400', $first->grossMinor);
        self::assertSame('USD', $first->currency);
        self::assertNotNull($first->obligationRef);
        self::assertSame($first->body(), $booking->book(new BookRatedBasis(prepared: $prepared, operationKey: 'annual-book'))->body());
        $expectedReplay = [...$first->body(), 'outcome' => BookingOutcome::Existing->value];

        // A new price/tax and cancelled host state cannot mutate previously frozen annual money.
        $tax->rate = '0.99';
        $app->make(PriceVersionStore::class)->publish(price: $this->price(version: 2, amount: '999'), commandKey: 'annual-upgrade-price');
        $grantKeys = [];
        foreach ([11, 0, 4, 4, 3, 1, 2, 8, 7, 6, 5, 10, 9, 0] as $delivery => $month) {
            $replayed = $schedule->commit(quoteRef: $quote->quoteRef, quoteDigest: $quote->digest,
                expectedRevision: 1, cancellationGeneration: 0, commandKey: 'annual-funding-'.$delivery)->body();
            self::assertSame($notice, $replayed);
            self::assertSame($occurrence, $occurrences->ensureOccurrence($ensure)->body());
            self::assertSame($expectedReplay, $booking->book(new BookRatedBasis(prepared: $prepared, operationKey: 'annual-book-'.$delivery))->body());
            // Only the external grant owner owns these monthly delivery identities.
            $start = new DateTimeImmutable($quote->period->start)->modify('+'.$month.' months');
            $key = FixtureValues::text($replayed['externalCorrelationRef']).':'.$start->format('Y-m');
            $grantKeys[$key] = ['coverageRef' => $replayed['coverageRef'], 'start' => $start->format('Y-m-d'),
                'end' => $start->modify('+1 month')->format('Y-m-d')];
        }
        self::assertCount(12, $grantKeys);
        $expectedKeys = array_map(static fn (int $month): string => 'host:component-a:2024-'.sprintf('%02d', $month), range(1, 12));
        $actualKeys = array_keys($grantKeys);
        sort($actualKeys);
        self::assertSame($expectedKeys, $actualKeys);
        self::assertSame('2024-01-01', $grantKeys['host:component-a:2024-01']['start']);
        self::assertSame('2025-01-01', $grantKeys['host:component-a:2024-12']['end']);
        foreach ($grantKeys as $grant) {
            self::assertSame($notice['coverageRef'], $grant['coverageRef']);
        }

        $upgrade = Terms::make([...$terms->toArray(), 'agreementRevision' => 2, 'fundingRevision' => 8,
            'policy' => $terms->policy, 'priceVersionRef' => 'annual@2']);
        $host->approved = $upgrade;
        $upgradeQuote = $schedule->preview(terms: $upgrade, ordinal: 0, validUntil: '2023-12-20T00:00:00Z', commandKey: 'upgrade-preview');
        try {
            $schedule->commit(quoteRef: $upgradeQuote->quoteRef, quoteDigest: $upgradeQuote->digest,
                expectedRevision: 2, cancellationGeneration: 0, commandKey: 'overlap-upgrade');
            self::fail('An upgrade repriced a booked annual interval without correction');
        } catch (ProblemException $exception) {
            self::assertStringContainsString('correction_required', $exception->detail);
        }
        $pauseTerms = Terms::make([...$terms->toArray(), 'policy' => $terms->policy,
            'agreementRevision' => 2, 'fundingRevision' => 8, 'action' => 'pause']);
        $host->approved = $pauseTerms;
        $pause = $schedule->preview(terms: $pauseTerms, ordinal: 1, validUntil: '2023-12-20T00:00:00Z', commandKey: 'pause-preview');
        $paused = $schedule->commit(quoteRef: $pause->quoteRef, quoteDigest: $pause->digest,
            expectedRevision: 2, cancellationGeneration: 0, commandKey: 'future-pause')->body();
        self::assertNull($paused['occurrenceRef']);
        self::assertNull($paused['coverageRef']);
        self::assertSame(2, $this->owner()->table('billium_schedule_component')->value('agreement_revision'));
        $stale = $schedule->preview(terms: $terms, ordinal: 1, validUntil: '2023-12-20T00:00:00Z', commandKey: 'stale-preview');
        $host->approved = $terms;
        try {
            $schedule->commit(quoteRef: $stale->quoteRef, quoteDigest: $stale->digest,
                expectedRevision: 1, cancellationGeneration: 0, commandKey: 'stale-reordered-revision');
            self::fail('A reordered old revision advanced coverage');
        } catch (ProblemException $exception) {
            self::assertStringContainsString('stale_agreement_revision', $exception->detail);
        }
        $host->approved = $upgrade;
        $future = $schedule->preview(terms: $upgrade, ordinal: 1, validUntil: '2023-12-20T00:00:00Z', commandKey: 'future-preview');
        $host->cancelled = true;
        try {
            $schedule->commit(quoteRef: $future->quoteRef, quoteDigest: $future->digest,
                expectedRevision: 2, cancellationGeneration: 0, commandKey: 'cancelled-future');
            self::fail('A cancelled host revision created coverage');
        } catch (ProblemException $exception) {
            self::assertSame('host_revision_or_cancellation_changed', $exception->detail);
        }
        self::assertSame($notice, $schedule->commit(quoteRef: $quote->quoteRef, quoteDigest: $quote->digest,
            expectedRevision: 1, cancellationGeneration: 0, commandKey: 'old-funding-after-pause')->body());
        self::assertEquals($notice, $schedule->coverage(FixtureValues::text($notice['coverageRef']))?->body());
        self::assertSame($expectedReplay, $booking->book(new BookRatedBasis(prepared: $prepared, operationKey: 'cancelled-replay'))->body());
        self::assertCount(12, $grantKeys);
        self::assertSame(2, $this->owner()->table('billium_schedule_revision')->count());
        foreach (['billium_schedule_period', 'billium_schedule_billing_occurrence', 'billium_rating_rated_basis', 'billium_rating_booking', 'billium_obligation'] as $table) {
            self::assertSame(1, $this->owner()->table($table)->count(), $table);
        }
        foreach (['billium_schedule_period', 'billium_schedule_billing_occurrence'] as $table) {
            self::assertSame('2024-01-01', $this->owner()->scalar("SELECT (period_start AT TIME ZONE 'UTC')::date::text FROM {$table}"));
            self::assertSame('2025-01-01', $this->owner()->scalar("SELECT (period_end AT TIME ZONE 'UTC')::date::text FROM {$table}"));
            self::assertSame(366, $this->owner()->scalar("SELECT (period_end AT TIME ZONE 'UTC')::date - (period_start AT TIME ZONE 'UTC')::date FROM {$table}"));
            self::assertSame('component-a', $this->owner()->table($table)->value('component_ref'));
        }
        $basis = $this->owner()->table('billium_rating_rated_basis')->first();
        self::assertNotNull($basis);
        self::assertSame($ref, $basis->occurrence_ref);
        self::assertSame(14400, $basis->gross_minor);
        self::assertSame($first->basisRef, $basis->basis_ref);
        self::assertSame('rating:'.$first->basisRef, $this->owner()->table('billium_obligation')->value('basis_ref'));
        self::assertSame($first->obligationRef, $this->owner()->table('billium_obligation')->value('obligation_ref'));
        self::assertSame(14400, $this->owner()->table('billium_obligation')->value('amount_due_minor'));
        self::assertSame(14400, $this->owner()->table('billium_obligation')->value('open_minor'));
        self::assertNull($this->owner()->scalar("SELECT to_regclass('public.billium_usage_fact')::text"));
    }

    private function price(int $version, string $amount): PriceVersion
    {
        $args = ['sellerRef' => 'seller-a', 'priceRef' => 'annual', 'version' => $version, 'strategyId' => StrategyId::Fixed,
            'strategyVersion' => 1, 'currency' => 'USD', 'currencyExponent' => 2, 'exponentVersion' => 'ISO4217-2026-10', 'unit' => 'component',
            'quantityRules' => new QuantityRules(quantityScale: 0, conversionNumerator: '1', conversionDenominator: '1', billingIncrement: '1', incrementMode: IncrementMode::None),
            'terms' => new FixedTerms(amount: $amount, scope: 'per_component_occurrence'), 'roundingMode' => RoundingMode::HalfUp,
            'roundingStage' => RoundingStage::AfterTax, 'roundingPolicyVersion' => 'test/1', 'commitment' => null];
        $draft = new PriceVersion(...$args, termsDigest: 'draft');

        return new PriceVersion(...$args, termsDigest: CanonicalJson::termsDigest($draft));
    }
}

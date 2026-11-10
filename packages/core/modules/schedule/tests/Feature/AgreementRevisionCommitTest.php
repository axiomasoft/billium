<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Schedule\Contracts\AgreementRevisionGuard;
use Billium\Schedule\Data\PeriodPolicy;
use Billium\Schedule\Internal\UnboundAgreementRevisionGuard;
use Billium\Schedule\Tests\ScheduleTestCase;
use Billium\Schedule\Tests\Support\Terms;
use DateTimeImmutable;

final class AgreementRevisionCommitTest extends ScheduleTestCase
{
    public function test_expired_quote_is_refused_without_financial_change(): void
    {
        $quote = $this->quote(Terms::make());
        $this->clock->instant = new DateTimeImmutable($quote->validUntil);
        $this->refuses(fn () => $this->commitQuote($quote));
        $this->assertNoFinancialChange();
    }

    public function test_stale_host_revision_or_changed_terms_are_refused(): void
    {
        $quote = $this->quote(Terms::make());
        $this->host->approved = Terms::make(['agreementRevision' => 2, 'priceVersionRef' => 'price:2']);
        $this->refuses(fn () => $this->commitQuote($quote));
        $this->host->approved = Terms::make(['priceVersionRef' => 'changed-without-revision']);
        $this->refuses(fn () => $this->commitQuote(quote: $quote, key: 'commit-2'));
        $this->assertNoFinancialChange();
    }

    public function test_host_cancellation_tombstone_and_generation_change_refuse_commit(): void
    {
        $quote = $this->quote(Terms::make());
        $this->host->cancelled = true;
        $this->refuses(fn () => $this->commitQuote($quote));
        $this->host->cancelled = false;
        $this->host->approved = Terms::make(['cancellationGeneration' => 1]);
        $this->refuses(fn () => $this->commitQuote(quote: $quote, key: 'generation-change'));
        $this->assertNoFinancialChange();
    }

    public function test_missing_host_guard_fails_closed(): void
    {
        $quote = $this->quote(Terms::make());
        $this->application()->instance(AgreementRevisionGuard::class, new UnboundAgreementRevisionGuard);
        $this->refuses(fn () => $this->commitQuote($quote));
        $this->assertNoFinancialChange();
    }

    public function test_wrong_quote_digest_expected_revision_and_generation_conflict(): void
    {
        $quote = $this->quote(Terms::make());
        foreach ([['sha256:'.str_repeat('b', 64), 1, 0], [$quote->digest, 2, 0], [$quote->digest, 1, 1]] as $i => [$digest, $revision, $generation]) {
            $this->refuses(fn () => $this->schedule()->commit(quoteRef: $quote->quoteRef, quoteDigest: $digest,
                expectedRevision: $revision, cancellationGeneration: $generation, commandKey: 'wrong-'.$i), ProblemCode::IdempotencyConflict);
        }
        $this->assertNoFinancialChange();
    }

    public function test_same_command_key_with_changed_quote_payload_conflicts(): void
    {
        $quote = $this->quote(Terms::make());
        $first = $this->commitQuote($quote)->body();
        $this->refuses(fn () => $this->schedule()->commit(quoteRef: $quote->quoteRef, quoteDigest: $quote->digest,
            expectedRevision: 2, cancellationGeneration: 0, commandKey: 'commit-1'), ProblemCode::IdempotencyConflict);
        self::assertEquals($first, $this->commitQuote($quote)->body());
        self::assertSame(1, $this->rows('billium_schedule_revision'));
        self::assertSame(1, $this->rows('billium_schedule_period'));
    }

    public function test_new_revision_cannot_reprice_an_already_committed_interval(): void
    {
        $first = $this->quote(Terms::make());
        $this->commitQuote($first);
        $second = $this->quote(terms: Terms::make(['agreementRevision' => 2, 'priceVersionRef' => 'price:2']), key: 'preview-2');
        $this->refuses(fn () => $this->commitQuote(quote: $second, key: 'commit-2'));
        self::assertSame(1, $this->rows('billium_schedule_period'));
        self::assertSame(1, $this->rows('billium_schedule_revision'));
        self::assertSame($first->terms->digest(), $this->value('SELECT terms_digest FROM billium_schedule_period'));
    }

    public function test_midperiod_and_implicit_timezone_changes_need_explicit_policy(): void
    {
        $this->commitQuote($this->quote(Terms::make()));
        $midperiod = Terms::make(['agreementRevision' => 2, 'anchorLocal' => '2024-02-15T00:00:00', 'anchorPolicy' => 'reanchor_explicit']);
        $this->refuses(fn () => $this->commitQuote(quote: $this->quote(terms: $midperiod, key: 'midperiod'), key: 'midperiod'));
        $zoneChange = Terms::make(['agreementRevision' => 2, 'anchorLocal' => '2024-02-28T19:00:00',
            'policy' => new PeriodPolicy(timezone: 'America/New_York', frequency: 'month')]);
        $this->refuses(fn () => $this->commitQuote(quote: $this->quote(terms: $zoneChange, key: 'implicit-zone'), key: 'implicit-zone'));
        self::assertSame(1, $this->rows('billium_schedule_period'));
    }

    public function test_reordered_uncommitted_revision_cannot_overwrite_the_new_head(): void
    {
        $old = $this->quote(Terms::make());
        $new = $this->quote(terms: Terms::make(['agreementRevision' => 2]), ordinal: 1, key: 'preview-2');
        $this->commitQuote(quote: $new, key: 'commit-2');
        $this->host->approved = $old->terms;
        $this->refuses(fn () => $this->commitQuote($old));
        self::assertSame(2, $this->value('SELECT agreement_revision FROM billium_schedule_component'));
        self::assertSame(1, $this->rows('billium_schedule_period'));
    }

    private function assertNoFinancialChange(): void
    {
        self::assertSame(0, $this->rows('billium_schedule_revision'));
        self::assertSame(0, $this->rows('billium_schedule_period'));
        self::assertSame(0, $this->rows('billium_schedule_component'));
        self::assertSame(0, $this->rows('billium_money_commit_log'));
        self::assertSame(0, $this->rows('billium_obligation'));
    }

    private function refuses(callable $call, ProblemCode $problem = ProblemCode::PolicyUnknown): void
    {
        try {
            $call();
            self::fail('unsafe agreement revision was accepted');
        } catch (ProblemException $exception) {
            self::assertSame($problem, $exception->problem, $exception->getMessage());
        }
    }
}

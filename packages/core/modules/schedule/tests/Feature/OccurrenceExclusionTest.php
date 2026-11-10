<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Feature;

use Billium\Core\Tests\Support\FixtureValues;
use Billium\Core\Tests\TestEnvironment;
use Billium\Schedule\Data\PeriodPolicy;
use Billium\Schedule\Enums\OccurrencePurpose;
use Billium\Schedule\Tests\OccurrenceTestCase;
use Billium\Schedule\Tests\Support\Terms;
use DateTimeImmutable;

final class OccurrenceExclusionTest extends OccurrenceTestCase
{
    public function test_annual_money_correlates_with_twelve_external_grants_without_minting_monthly_money(): void
    {
        $path = dirname(__DIR__, 6).'/fixtures/vectors/schedule/occurrences/annual-money-monthly-grants.json';
        /** @var array{id: string, description: string, spec_refs: list<string>, profiles: list<string>, given: array{occurrence: array{schema: string, owners: list<string>, selectionGate: string, annual: array{purpose: string, correlationRef: string, start: string, end: string}, externalGrants: list<array{grantRef: string, correlationRef: string, start: string, end: string}>, expect: array{normalMonetaryOccurrences: int, externalGrantWindows: int, grantPurposeAccepted: bool}}}, script: list<array{op: string, args: array{json_text: string}}>, expect: list<array{ok: array{digest: array{golden: string}}}>} $vector */
        $vector = json_decode(self::text(file_get_contents($path)), true, 32, JSON_THROW_ON_ERROR);
        $fixture = $vector['given']['occurrence'];
        self::assertCount(12, $fixture['externalGrants']);
        $this->occurrences()->applyScheduleRevision($this->revision(['terms' => Terms::make(['purpose' => 'recurring_charge', 'anchorLocal' => '2024-01-01T00:00:00', 'policy' => new PeriodPolicy(timezone: 'Etc/UTC', frequency: 'year'), 'externalCorrelationRef' => $fixture['annual']['correlationRef']])]));
        foreach ($fixture['externalGrants'] as $grant) {
            self::assertSame($fixture['annual']['correlationRef'], $grant['correlationRef']);
            $this->occurrences()->ensureOccurrence($this->ensure(['periodStart' => new DateTimeImmutable($fixture['annual']['start']), 'periodEnd' => new DateTimeImmutable($fixture['annual']['end']), 'commandKey' => $grant['grantRef']]));
        }
        self::assertSame(1, $this->rows('billium_schedule_billing_occurrence'));
        $this->refusal(fn () => $this->occurrences()->applyScheduleRevision($this->revision(['terms' => Terms::make(['purpose' => 'entitlement_grant'])])), 'occurrence.purpose_rejected');
    }

    public function test_overlap_is_refused_and_adjacent_intervals_are_allowed(): void
    {
        $this->createSchedule();
        $this->occurrences()->ensureOccurrence($this->ensure());
        $this->refusal(fn () => $this->occurrences()->ensureOccurrence($this->ensure(['periodStart' => new DateTimeImmutable('2024-02-01Z'), 'periodEnd' => new DateTimeImmutable('2024-03-01Z'), 'commandKey' => 'overlap'])), 'occurrence.overlap');
        $this->occurrences()->ensureOccurrence($this->ensure(['periodStart' => new DateTimeImmutable('2024-02-29Z'), 'periodEnd' => new DateTimeImmutable('2024-03-31Z'), 'ordinal' => 1, 'commandKey' => 'adjacent']));
        self::assertSame(2, $this->rows('billium_schedule_billing_occurrence'));
    }

    public function test_usage_and_time_coverage_coexist_but_proration_cannot_bypass_time_overlap(): void
    {
        $this->createSchedule();
        $parent = FixtureValues::text($this->occurrences()->ensureOccurrence($this->ensure())->body()['occurrenceRef']);
        $this->occurrences()->applyScheduleRevision($this->revision(['scheduleRef' => 'usage', 'commandKey' => 'usage-revision', 'terms' => Terms::make(['purpose' => 'usage_settlement'])]));
        $this->occurrences()->ensureOccurrence($this->ensure(['purpose' => OccurrencePurpose::UsageSettlement, 'scheduleRef' => 'usage', 'commandKey' => 'usage']));
        $this->refusal(fn () => $this->occurrences()->ensureOccurrence($this->ensure(['purpose' => OccurrencePurpose::Proration, 'parentOccurrenceRef' => $parent, 'periodStart' => new DateTimeImmutable('2024-02-10Z'), 'commandKey' => 'proration'])), 'occurrence.overlap');
        self::assertSame(2, $this->rows('billium_schedule_billing_occurrence'));
    }

    public function test_normal_purpose_registry_and_database_history_backstops(): void
    {
        foreach (['entitlement_grant', 'collection_step', 'collection_retry', 'correction', 'anything_else'] as $purpose) {
            $this->refusal(fn () => $this->occurrences()->applyScheduleRevision($this->revision(['terms' => Terms::make(['purpose' => $purpose])])), 'occurrence.purpose_rejected');
        }
        $this->createSchedule();
        $this->occurrences()->ensureOccurrence($this->ensure());
        $owner = TestEnvironment::pdo(owner: true);
        self::assertSame('55000', self::sqlState(pdo: $owner, sql: "UPDATE billium_schedule_billing_occurrence SET inputs_digest='changed'"));
        self::assertSame('55000', self::sqlState(pdo: $owner, sql: 'DELETE FROM billium_schedule_billing_occurrence'));
        self::assertSame('23514', self::sqlState(pdo: $owner, sql: <<<'SQL'
        INSERT INTO billium_schedule_billing_occurrence
          (seller_ref,environment,occurrence_ref,incarnation,component_ref,purpose,period_start,period_end,
           schedule_id,created_revision,inputs_digest,state,cancel_generation)
        SELECT seller_ref,environment,occurrence_ref,2,component_ref,'correction',period_start,period_end,
          schedule_id,created_revision,inputs_digest,state,cancel_generation FROM billium_schedule_billing_occurrence
        SQL));
    }
}

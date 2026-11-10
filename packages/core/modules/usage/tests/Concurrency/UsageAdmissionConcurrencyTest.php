<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Concurrency;

use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Usage\Tests\UsageTestCase;

final class UsageAdmissionConcurrencyTest extends UsageTestCase
{
    public function test_two_worker_smoke(): void
    {
        $this->race(2);
    }

    public function test_ten_independent_postgresql_couriers_admit_one_fact_and_receipt(): void
    {
        $this->race(10);
    }

    public function test_ten_conflicting_couriers_produce_one_winner(): void
    {
        $results = ConcurrencyStand::run(scenario: UsageAdmissionScenario::class, workers: 10, args: ['conflict' => true], timeoutSeconds: 60);
        self::assertCount(1, array_filter($results, static fn (array $r): bool => isset($r['ok'])));
        self::assertCount(9, array_filter($results, static fn (array $r): bool => ($r['error']['code'] ?? '') === 'billium.idempotency.conflict'));
        self::assertSame(1, $this->rows('billium_usage_fact'));
        self::assertSame(1, $this->rows('billium_idempotency_receipt', "command_kind='usage.fact_submit'"));
        self::assertSame(0, $this->rows('billium_obligation'));
    }

    private function race(int $workers): void
    {
        $results = ConcurrencyStand::run(scenario: UsageAdmissionScenario::class, workers: $workers, timeoutSeconds: 60);
        $ok = array_map(static fn (array $r): array => $r['ok'] ?? self::fail(json_encode($r, JSON_THROW_ON_ERROR)), $results);
        self::assertCount($workers, array_unique(array_map(self::integer(...), array_column($ok, 'pid'))));
        self::assertCount(1, array_unique(array_map(self::text(...), array_column($ok, 'receipt'))));
        self::assertCount(1, array_unique(array_map(self::text(...), array_column($ok, 'digest'))));
        self::assertSame(1, $this->rows('billium_usage_fact'));
        self::assertSame(1, $this->rows('billium_idempotency_receipt', "command_kind='usage.fact_submit'"));
        self::assertSame(0, $this->rows('billium_obligation'));
        self::assertSame(0, $this->rows('billium_money_commit_log'));
    }
}

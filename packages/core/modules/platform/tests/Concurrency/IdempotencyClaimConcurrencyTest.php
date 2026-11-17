<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Concurrency;

use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Platform\Tests\Concurrency\Scenarios\RootOperationScenario;
use Billium\Platform\Tests\PlatformTestCase;
use PHPUnit\Framework\Attributes\Group;

/** INV-05 under real processes: the claim is an atomic first write, never check-then-insert (M-53, D51). */
#[Group('INV-05')]
#[Group('REQ-ACC-11')]
#[Group('pg')]
final class IdempotencyClaimConcurrencyTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
    }

    public function test_ten_processes_with_the_same_key_and_digest_produce_one_effect(): void
    {
        $this->assertSameKeyRace(10);
    }

    public function test_twenty_processes_with_the_same_key_and_digest_produce_one_effect(): void
    {
        $this->assertSameKeyRace(20);
    }

    public function test_ten_processes_with_the_same_key_and_different_digests_conflict(): void
    {
        $digests = array_map(static fn (int $i): string => 'sha256:'.str_pad(dechex($i), 64, '0', STR_PAD_LEFT), range(0, 9));
        $results = ConcurrencyStand::run(RootOperationScenario::class, 10, ['key' => 'k-digests', 'digests' => $digests, 'body_sleep_ms' => 200]);

        $accepted = array_values(array_filter($results, static fn (array $result): bool => ($result['ok']['accepted'] ?? false) === true));
        $conflicts = array_filter($results, static fn (array $result): bool => ($result['error']['code'] ?? null) === 'billium.idempotency.conflict');
        self::assertCount(1, $accepted);
        self::assertFalse($accepted[0]['ok']['replayed']);
        self::assertCount(9, $conflicts);
        self::assertSame(1, $this->rows('billium_idempotency_receipt', 'command_key = ?', ['k-digests']));
        self::assertSame(1, $this->rows('billium_test_effect'));
        self::assertSame(1, $this->rows('billium_money_commit_log'));
    }

    private function assertSameKeyRace(int $workers): void
    {
        $results = ConcurrencyStand::run(RootOperationScenario::class, $workers, ['key' => 'k-same', 'body_sleep_ms' => 200], 60);

        $ok = array_map(static fn (array $result): array => $result['ok'] ?? self::fail('unexpected refusal '.json_encode($result)), $results);
        $original = array_values(array_filter($ok, static fn (array $result): bool => $result['replayed'] === false));
        self::assertCount(1, $original, 'exactly one process executes the body');
        self::assertCount($workers - 1, array_filter($ok, static fn (array $result): bool => $result['replayed'] === true));
        self::assertCount(1, array_unique(array_map(static fn (array $result): int => self::integer($result['receipt']), $ok)), 'every duplicate returns the same receiptRef');
        self::assertCount(1, array_unique(array_map(static fn (array $result): string => json_encode($result['body'], JSON_THROW_ON_ERROR), $ok)));
        self::assertSame(1, $this->rows('billium_idempotency_receipt', 'command_key = ?', ['k-same']));
        self::assertSame(1, $this->rows('billium_test_effect'));
        self::assertSame(1, $this->rows('billium_money_commit_log'));
    }
}

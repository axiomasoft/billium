<?php

declare(strict_types=1);

namespace Billium\Testkit\Tests;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Data\LookupRequest;
use Billium\Execution\Enums\CaptureMode;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ProviderLookupState;
use Billium\Execution\Enums\QualificationMode;
use Billium\Testkit\Drivers\DeterministicPaymentDriver;
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use Billium\Testkit\Oracles\IndependentFinancialOracle;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DeterministicDriverTest extends TestCase
{
    private string $directory;

    private FixtureClock $clock;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/billium-harness-'.bin2hex(random_bytes(8));
        $this->clock = new FixtureClock(new DateTimeImmutable('2026-11-17T00:00:00Z'));
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory.'/*');
        if ($files === false) {
            throw new RuntimeException('Cannot enumerate fixture files');
        }
        foreach ($files as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    private function journal(): DurableJournal
    {
        return new DurableJournal($this->directory.'/provider.log');
    }

    /** @return iterable<string,array{ResponseMode,ProviderLookupState,string}> */
    public static function modes(): iterable
    {
        yield 'success' => [ResponseMode::Success, ProviderLookupState::Found, 'succeeded'];
        yield 'pending' => [ResponseMode::Pending, ProviderLookupState::Pending, 'pending'];
        yield 'declined' => [ResponseMode::Declined, ProviderLookupState::Found, 'declined'];
        yield 'accepted response lost' => [ResponseMode::AcceptedThenTimeout, ProviderLookupState::Unavailable, 'succeeded'];
    }

    #[DataProvider('modes')]
    public function test_response_modes_have_durable_provider_truth(ResponseMode $mode, ProviderLookupState $state, string $status): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a');
        $driver = new DeterministicPaymentDriver($this->journal(), $this->clock, $cap, static fn (): int => 0, $mode);
        $request = HarnessFactory::request($cap);
        self::assertSame($state, $driver->execute($request)->state);
        $restarted = new DeterministicPaymentDriver($this->journal(), $this->clock, $cap, static fn (): int => 0);
        $lookup = $restarted->lookup(new LookupRequest($request->binding, 'operation-a', 'reference-a', null, $request->credentials));
        self::assertSame($status, $lookup->snapshot?->normalizedStatus);
        self::assertSame($status === 'succeeded' ? 100 : 0, $lookup->snapshot->capturedTotal->minor);
    }

    public function test_twenty_duplicate_calls_are_counted_even_when_provider_deduplicates(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a');
        $driver = new DeterministicPaymentDriver($this->journal(), $this->clock, $cap, static fn (): int => 0);
        $request = HarnessFactory::request($cap);
        for ($i = 0; $i < 20; $i++) {
            $driver->execute($request);
        }
        self::assertCount(1, array_filter($this->journal()->records(), static fn (object $record): bool => $record instanceof ProviderRecord));
        $oracle = new IndependentFinancialOracle;
        $oracle->assertSendCount($this->journal(), 'operation-a', 20);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected actual provider send count');
        $oracle->assertSendCount($this->journal(), 'operation-a', 1);
    }

    /** @return iterable<string,array{string}> */
    public static function unsupported(): iterable
    {
        foreach (['authorize', 'capture', 'voidAuthorization', 'refund'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('unsupported')]
    public function test_unsupported_effect_refuses_without_journal_or_send(string $method): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a', capture: CaptureMode::None, effects: [EffectKind::Charge]);
        $driver = new DeterministicPaymentDriver($this->journal(), $this->clock, $cap, static fn (): int => 0);
        try {
            match ($method) {
                'authorize' => $driver->authorize(HarnessFactory::request($cap)), 'capture' => $driver->capture(HarnessFactory::request($cap)), 'voidAuthorization' => $driver->voidAuthorization(HarnessFactory::request($cap)), 'refund' => $driver->refund(HarnessFactory::request($cap)), default => throw new RuntimeException('Unknown test method')
            };
            self::fail('Unsupported call accepted');
        } catch (ProblemException) {
            self::assertSame([], $this->journal()->records());
        }
    }

    public function test_expired_idempotency_and_lookup_retention_never_certify_absence(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a');
        $driver = new DeterministicPaymentDriver($this->journal(), $this->clock, $cap, static fn (): int => 0);
        $request = HarnessFactory::request($cap);
        $driver->execute($request);
        $this->clock->advance(1000000);
        $result = $driver->lookup(new LookupRequest($request->binding, 'operation-a', 'reference-a', null, $request->credentials));
        self::assertSame(ProviderLookupState::Ambiguous, $result->state);
        self::assertNull($result->absence);
        $driver->execute($request);
        self::assertCount(2, array_filter($this->journal()->records(), static fn (object $record): bool => $record instanceof ProviderRecord));
    }

    public function test_no_idempotency_mode_can_reveal_duplicate_external_effect(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a', idempotencySeconds: null);
        $driver = new DeterministicPaymentDriver($this->journal(), $this->clock, $cap, static fn (): int => 0);
        $request = HarnessFactory::request($cap);
        $driver->execute($request);
        self::assertSame(200, $driver->execute($request)->snapshot?->capturedTotal->minor);
    }

    public function test_eventual_visibility_and_incomplete_inventory_preserve_unknown(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a', visibility: 'eventual', completeInventory: false, absence: false);
        $driver = new DeterministicPaymentDriver($this->journal(), $this->clock, $cap, static fn (): int => 0);
        $request = HarnessFactory::request($cap);
        $driver->execute($request);
        self::assertSame(ProviderLookupState::Ambiguous, $driver->lookup(new LookupRequest($request->binding, 'operation-a', 'reference-a', null, $request->credentials))->state);
        $page = $driver->inventory(HarnessFactory::merchant($cap), $request->credentials, $this->clock->now, null);
        self::assertFalse($page->complete);
        self::assertNull($page->completenessWatermark);
    }

    public function test_foreign_account_and_in_transaction_calls_have_zero_sends(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a');
        $driver = new DeterministicPaymentDriver($this->journal(), $this->clock, $cap, static fn (): int => 1);
        try {
            $driver->execute(HarnessFactory::request($cap));
            self::fail('Tx accepted');
        } catch (RuntimeException) {
        }
        $driver = new DeterministicPaymentDriver($this->journal(), $this->clock, $cap, static fn (): int => 0);
        try {
            $driver->execute(HarnessFactory::request(SimulatedCapabilities::snapshot('account-b')));
            self::fail('Foreign account accepted');
        } catch (RuntimeException) {
            self::assertSame([], $this->journal()->records());
        }
    }

    public function test_callback_checkpoint_runs_after_durable_accept_before_response(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a');
        $stages = [];
        $driver = new DeterministicPaymentDriver($this->journal(), $this->clock, $cap, static fn (): int => 0, checkpoint: function (string $stage) use (&$stages): void {
            $stages[] = $stage;
            if ($stage === 'after_accept') {
                self::assertCount(1, array_filter($this->journal()->records(), static fn (object $record): bool => $record instanceof ProviderRecord));
            }
        });
        $result = $driver->execute(HarnessFactory::request($cap));
        self::assertSame(['before_accept', 'after_accept'], $stages);
        self::assertNotNull($result->evidence);
        self::assertEquals($result->snapshot, $driver->normalize($result->evidence, $cap));
    }

    public function test_pending_and_declined_can_publish_late_success_without_resending(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a');
        $driver = new DeterministicPaymentDriver($this->journal(), $this->clock, $cap, static fn (): int => 0, ResponseMode::Pending);
        $result = $driver->execute(HarnessFactory::request($cap));
        self::assertNotNull($result->snapshot?->stableEffectId);
        $settled = $driver->settle($result->snapshot->stableEffectId, 'succeeded');
        self::assertSame(100, $settled->snapshot?->capturedTotal->minor);
        self::assertNotSame($result->evidence?->providerEventId, $settled->evidence?->providerEventId);
        (new IndependentFinancialOracle)->assertSendCount($this->journal(), 'operation-a', 1);
    }

    public function test_seller_payload_conflict_cannot_multiply_provider_effect_and_rotated_credential_can_lookup(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a');
        $driver = new DeterministicPaymentDriver($this->journal(), $this->clock, $cap, static fn (): int => 0);
        $request = HarnessFactory::request($cap);
        $first = $driver->execute($request);
        try {
            $driver->execute(HarnessFactory::request($cap, binding: HarnessFactory::binding($cap, 'seller-b')));
            self::fail('Seller collision accepted');
        } catch (RuntimeException) {
        }
        $rotated = HarnessFactory::credentials('account-a', 'credentials-v2');
        $lookup = $driver->lookup(new LookupRequest($request->binding, 'operation-a', 'reference-a', $first->snapshot?->providerOperationId, $rotated));
        self::assertSame(100, $lookup->snapshot?->capturedTotal->minor);
        (new IndependentFinancialOracle)->assertSendCount($this->journal(), 'operation-a', 1);
    }

    public function test_simulated_manifest_never_qualifies_for_production(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a');
        $manifest = SimulatedCapabilities::manifest($cap, $this->clock->now);
        self::assertSame(QualificationMode::Simulated, $manifest->mode);
        self::assertNotEmpty($manifest->unresolvedGaps);
        $this->expectException(ProblemException::class);
        $cap->requireEffect(EffectKind::Charge, 'RUB', true);
    }
}

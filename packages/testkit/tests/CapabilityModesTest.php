<?php

declare(strict_types=1);

namespace Billium\Testkit\Tests;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Data\LookupRequest;
use Billium\Execution\Enums\CaptureMode;
use Billium\Execution\Enums\EffectKind;
use Billium\Testkit\Drivers\DeterministicPaymentDriver;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use Billium\Testkit\Fixtures\ProcessBarrier;
use Billium\Testkit\Oracles\IndependentFinancialOracle;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CapabilityModesTest extends TestCase
{
    private string $directory;

    private FixtureClock $clock;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/billium-modes-'.bin2hex(random_bytes(8));
        $this->clock = new FixtureClock(new DateTimeImmutable('2026-11-17T00:00:00Z'));
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory.'/*');
        if ($files === false) {
            throw new RuntimeException('Cannot enumerate modes fixture files');
        }
        foreach ($files as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /** @return iterable<string,array{CaptureMode}> */
    public static function captureModes(): iterable
    {
        foreach (CaptureMode::cases() as $mode) {
            yield $mode->value => [$mode];
        }
    }

    #[DataProvider('captureModes')]
    public function test_exact_capture_modes(CaptureMode $mode): void
    {
        $effects = $mode === CaptureMode::None ? [EffectKind::Charge, EffectKind::Authorize] : EffectKind::cases();
        $cap = SimulatedCapabilities::snapshot('account-a', capture: $mode, effects: $effects);
        $driver = new DeterministicPaymentDriver(new DurableJournal($this->directory.'/provider.log'), $this->clock, $cap, static fn (): int => 0);
        $driver->authorize(HarnessFactory::request($cap, 'authorize', 'auth-reference'));
        if ($mode === CaptureMode::None) {
            $this->expectException(ProblemException::class);
            $driver->capture(HarnessFactory::request($cap, 'capture', 'capture-reference'));

            return;
        }
        if ($mode === CaptureMode::Full) {
            try {
                $driver->capture(HarnessFactory::request($cap, 'partial', 'partial-reference', 50));
                self::fail('Partial capture accepted');
            } catch (ProblemException) {
            }
            self::assertSame(100, $driver->capture(HarnessFactory::request($cap, 'full', 'full-reference'))->snapshot?->capturedTotal->minor);

            return;
        }
        self::assertSame(50, $driver->capture(HarnessFactory::request($cap, 'capture-a', 'capture-ref-a', 50))->snapshot?->capturedTotal->minor);
        if ($mode === CaptureMode::PartialSingle) {
            $this->expectException(ProblemException::class);
            $driver->capture(HarnessFactory::request($cap, 'capture-b', 'capture-ref-b', 50));
        } else {
            self::assertSame(100, $driver->capture(HarnessFactory::request($cap, 'capture-b', 'capture-ref-b', 50))->snapshot?->capturedTotal->minor);
        }
    }

    public function test_partial_void_and_refund_have_separate_child_effects_and_totals(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a');
        $driver = new DeterministicPaymentDriver(new DurableJournal($this->directory.'/provider.log'), $this->clock, $cap, static fn (): int => 0);
        $driver->authorize(HarnessFactory::request($cap, 'auth', 'auth'));
        $driver->capture(HarnessFactory::request($cap, 'capture', 'capture', 60));
        $driver->voidAuthorization(HarnessFactory::request($cap, 'void', 'void', 40));
        $result = $driver->refund(HarnessFactory::request($cap, 'refund', 'refund', 20));
        self::assertSame(40, $result->snapshot?->voidedTotal->minor);
        self::assertSame(20, $result->snapshot->refundedTotal->minor);
        $oracle = new IndependentFinancialOracle;
        $oracle->ingestProvider($driver->journal);
        $oracle->assertApplied(IndependentFinancialOracle::businessIdentity(HarnessFactory::binding($cap)), ['authorized' => 100, 'captured' => 60, 'refunded' => 20, 'voided' => 40]);
        self::assertSame(['capture' => 0, 'refund' => 40, 'void' => 0], $oracle->capacities(IndependentFinancialOracle::businessIdentity(HarnessFactory::binding($cap))));
    }

    public function test_full_void_and_single_full_refund_refuse_partial_or_multiple(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a', partialRefund: false, multipleRefund: false, partialVoid: false);
        $driver = new DeterministicPaymentDriver(new DurableJournal($this->directory.'/provider.log'), $this->clock, $cap, static fn (): int => 0);
        $driver->authorize(HarnessFactory::request($cap, 'auth', 'auth'));
        try {
            $driver->voidAuthorization(HarnessFactory::request($cap, 'void', 'void', 50));
            self::fail('Partial void accepted');
        } catch (ProblemException) {
        }
        $driver->capture(HarnessFactory::request($cap, 'capture', 'capture'));
        try {
            $driver->refund(HarnessFactory::request($cap, 'partial-refund', 'partial-refund', 50));
            self::fail('Partial refund accepted');
        } catch (ProblemException) {
        }
        self::assertSame(100, $driver->refund(HarnessFactory::request($cap, 'refund', 'refund'))->snapshot?->refundedTotal->minor);
        $this->expectException(ProblemException::class);
        $driver->refund(HarnessFactory::request($cap, 'refund-b', 'refund-b'));
    }

    public function test_lookup_and_inventory_unsupported_refuse_before_external_calls(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a', visibility: 'none', completeInventory: false, absence: false, inventorySupported: false);
        $driver = new DeterministicPaymentDriver(new DurableJournal($this->directory.'/provider.log'), $this->clock, $cap, static fn (): int => 0);
        $request = HarnessFactory::request($cap);
        try {
            $driver->lookup(new LookupRequest($request->binding, 'op', 'ref', null, $request->credentials));
            self::fail('Unsupported lookup accepted');
        } catch (ProblemException) {
        }
        try {
            $driver->inventory(HarnessFactory::merchant($cap), $request->credentials, $this->clock->now, null);
            self::fail('Unsupported inventory accepted');
        } catch (ProblemException) {
        }
        self::assertSame([], $driver->journal->records());
    }

    public function test_twenty_real_refund_processes_share_durable_capacity_and_independent_oracle(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a');
        $journal = new DurableJournal($this->directory.'/provider.log');
        $driver = new DeterministicPaymentDriver($journal, $this->clock, $cap, static fn (): int => 0);
        $request = HarnessFactory::request($cap);
        $driver->execute($request);
        $children = [];
        for ($i = 0; $i < 20; $i++) {
            $children[] = new ChildProcess([PHP_BINARY, __DIR__.'/harness-worker.php', $journal->path, 'refund', (string) $i]);
        }
        $barrier = new ProcessBarrier($journal);
        self::assertCount(20, $barrier->await('ready', 20, 15000));
        $barrier->arrive('release');
        foreach ($children as $child) {
            self::assertSame(0, $child->wait());
        }
        $oracle = new IndependentFinancialOracle;
        $oracle->ingestProvider($journal);
        $oracle->assertApplied(IndependentFinancialOracle::businessIdentity($request->binding), ['authorized' => 0, 'captured' => 100, 'refunded' => 100, 'voided' => 0]);
        self::assertSame(100, $driver->lookup(new LookupRequest($request->binding, 'operation-a', 'reference-a', null, $request->credentials))->snapshot?->refundedTotal->minor);
    }
}

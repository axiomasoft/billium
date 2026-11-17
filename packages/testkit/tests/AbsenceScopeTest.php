<?php

declare(strict_types=1);

namespace Billium\Testkit\Tests;

use Billium\Execution\Data\AbsenceCertificate;
use Billium\Testkit\Drivers\DeterministicPaymentDriver;
use Billium\Testkit\Drivers\SimulatedAbsenceVerifier;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\DeterministicWriterHost;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AbsenceScopeTest extends TestCase
{
    private string $directory;

    private FixtureClock $clock;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/billium-absence-'.bin2hex(random_bytes(8));
        $this->clock = new FixtureClock(new DateTimeImmutable('2026-11-17T00:00:00Z'));
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory.'/*');
        if ($files === false) {
            throw new RuntimeException('Cannot enumerate absence fixture files');
        }
        foreach ($files as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function test_complete_simulated_absence_requires_independent_retirement_and_issued_certificate(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a');
        $provider = new DurableJournal($this->directory.'/provider.log');
        $authority = new DurableJournal($this->directory.'/authority.log');
        $certificates = new DurableJournal($this->directory.'/certificates.log');
        $verifier = new SimulatedAbsenceVerifier($provider, $authority, $certificates, $this->clock, $cap, static fn (): int => 0);
        $binding = HarnessFactory::binding($cap);
        try {
            $verifier->certify($binding, 'never-sent', $this->clock->now, $this->clock->now);
            self::fail('Unfenced absence accepted');
        } catch (RuntimeException) {
        }
        $claims = HarnessFactory::dispatch(HarnessFactory::request($cap));
        $host = new DeterministicWriterHost($authority, $this->clock, static fn (): int => 0);
        $host->openSession($claims);
        $host->retire($claims, 'simulated-workers-terminated-and-egress-revoked');
        $certificate = $verifier->certify($binding, 'never-sent', $this->clock->now, $this->clock->now);
        $verifier->verify($certificate, $binding);
        self::assertCount(1, $certificates->records());
        $this->expectException(RuntimeException::class);
        $verifier->verify($certificate, HarnessFactory::binding($cap, 'foreign-seller'));
    }

    public function test_retained_provider_effect_never_becomes_absent_after_ttl(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a');
        $provider = new DurableJournal($this->directory.'/provider.log');
        $authority = new DurableJournal($this->directory.'/authority.log');
        $clock = $this->clock;
        (new DeterministicPaymentDriver($provider, $clock, $cap, static fn (): int => 0))->execute(HarnessFactory::request($cap));
        $host = new DeterministicWriterHost($authority, $clock, static fn (): int => 0);
        $claims = HarnessFactory::dispatch(HarnessFactory::request($cap));
        $host->openSession($claims);
        $host->retire($claims, 'simulated-retired');
        $clock->advance(1000000);
        $verifier = new SimulatedAbsenceVerifier($provider, $authority, new DurableJournal($this->directory.'/certificates.log'), $clock, $cap, static fn (): int => 0);
        $this->expectException(RuntimeException::class);
        $verifier->certify(HarnessFactory::binding($cap), 'reference-a', new DateTimeImmutable('2026-11-17T00:00:00Z'), $clock->now);
    }

    public function test_constructed_certificate_fields_do_not_authenticate_absence(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a');
        $binding = HarnessFactory::binding($cap);
        $provider = new DurableJournal($this->directory.'/provider.log');
        $authority = new DurableJournal($this->directory.'/authority.log');
        $host = new DeterministicWriterHost($authority, $this->clock, static fn (): int => 0);
        $claims = HarnessFactory::dispatch(HarnessFactory::request($cap));
        $host->openSession($claims);
        $host->retire($claims, 'simulated-retired');
        $verifier = new SimulatedAbsenceVerifier($provider, $authority, new DurableJournal($this->directory.'/certificates.log'), $this->clock, $cap, static fn (): int => 0);
        $certificate = new AbsenceCertificate('unissued', $binding, 'missing', 'namespace', 'watermark', $this->clock->now, $this->clock->now, 'manifest', 'retirement', 'fence', 'qualification', 'issuer', 'signature', $this->clock->now->modify('+30 seconds'));
        $this->expectException(RuntimeException::class);
        $verifier->verify($certificate, $binding);
    }
}

<?php

declare(strict_types=1);

namespace Billium\Testkit\Tests;

use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Enums\ProviderLookupState;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\DeterministicWriterHost;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WriterHostTest extends TestCase
{
    private string $path;

    private FixtureClock $clock;

    private DeterministicWriterHost $host;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/billium-host-'.bin2hex(random_bytes(8)).'/authority.log';
        $this->clock = new FixtureClock(new DateTimeImmutable('2026-11-17T00:00:00Z'));
        $this->host = new DeterministicWriterHost(new DurableJournal($this->path), $this->clock, static fn (): int => 0);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->path)) {
            unlink($this->path);
        } rmdir(dirname($this->path));
    }

    public function test_unissued_ticket_is_not_authenticated_by_its_constructor(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a');
        $claims = HarnessFactory::dispatch(HarnessFactory::request($cap));
        $ticket = new DispatchTicket('forged', $claims, 'simulated-writer-host.v1', $this->clock->now, $this->clock->now->modify('+30 seconds'), 'forged', 'forged', 'forged');
        $this->host->openSession($claims);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unissued, changed or expired simulated ticket');
        $this->host->verify($ticket, $claims);
    }

    public function test_revocation_retirement_expiry_and_replay_block_sends(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a');
        $claims = HarnessFactory::dispatch(HarnessFactory::request($cap));
        $this->host->openSession($claims);
        $ticket = $this->host->ticket($claims);
        $request = HarnessFactory::request($cap, ticket: $ticket);
        $sends = 0;
        $send = static function () use (&$sends): ProviderLookupResult {
            $sends++;

            return new ProviderLookupResult(ProviderLookupState::Unavailable, null, null, null, 'simulated-lost-response', null);
        };
        $this->host->invoke($ticket, $request, $send);
        try {
            $this->host->invoke($ticket, $request, $send);
            self::fail('Consumed ticket accepted');
        } catch (RuntimeException $error) {
            self::assertSame('Simulated ticket already consumed', $error->getMessage());
        }
        $revoked = $this->host->ticket($claims);
        $this->host->revoke($revoked);
        try {
            $this->host->invoke($revoked, HarnessFactory::request($cap, ticket: $revoked), $send);
            self::fail('Revoked ticket accepted');
        } catch (RuntimeException $error) {
            self::assertSame('Revoked simulated ticket', $error->getMessage());
        }
        $expired = $this->host->ticket($claims);
        $this->clock->advance(31);
        try {
            $this->host->invoke($expired, HarnessFactory::request($cap, ticket: $expired), $send);
            self::fail('Expired ticket accepted');
        } catch (RuntimeException $error) {
            self::assertSame('Unissued, changed or expired simulated ticket', $error->getMessage());
        }
        $live = $this->host->ticket($claims);
        $this->host->retire($claims, 'simulated-drained-workers-and-egress-revoked');
        try {
            $this->host->invoke($live, HarnessFactory::request($cap, ticket: $live), $send);
            self::fail('Retired owner accepted');
        } catch (RuntimeException $error) {
            self::assertSame('Retired simulated session', $error->getMessage());
        }
        self::assertSame(1, $sends);
        $restarted = new DeterministicWriterHost(new DurableJournal($this->path), $this->clock, static fn (): int => 0);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Retired simulated session');
        $restarted->openSession($claims);
        $restarted->ticket($claims);
    }

    public function test_egress_checks_full_request_and_full_binding_digest(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a');
        $request = HarnessFactory::request($cap);
        $claims = HarnessFactory::dispatch($request);
        $this->host->openSession($claims);
        $ticket = $this->host->ticket($claims);
        self::assertNotSame($request->binding->semanticDigest, $claims->bindingDigest);
        self::assertStringStartsWith('sha256:', $claims->bindingDigest);
        self::assertNotSame($claims->bindingDigest, DeterministicWriterHost::bindingDigest(HarnessFactory::binding($cap, 'seller-b')));
        $sends = 0;
        try {
            $this->host->invoke($ticket, HarnessFactory::request($cap, amount: 101, ticket: $ticket), static function () use (&$sends): ProviderLookupResult {
                $sends++;
                throw new RuntimeException('Should not send');
            });
            self::fail('Changed amount accepted');
        } catch (RuntimeException $error) {
            self::assertSame('Simulated egress request differs from issued claims', $error->getMessage());
            self::assertSame(0, $sends);
        }
    }

    public function test_binding_digest_matches_complete_explicit_canonical_map(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a');
        // Literal vectors from conformance/oracles/jcs over the explicit fixture fields.
        self::assertSame('sha256:27cf0b98ac65ae4ee27d952f54b3eb86a41671cd84f5248c5e50adc93f37e4cf', DeterministicWriterHost::bindingDigest(HarnessFactory::binding($cap)));
        self::assertSame('sha256:6e642acf9ee58bda028c5dc3f74aee6d29af42279c8bf3f86faefeec5f495055', DeterministicWriterHost::bindingDigest(HarnessFactory::binding($cap, sourceContext: 'subscriptions')));
    }
}

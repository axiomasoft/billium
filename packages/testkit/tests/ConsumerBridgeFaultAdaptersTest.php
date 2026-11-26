<?php

declare(strict_types=1);

namespace Billium\Testkit\Tests;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Data\DispatchDecision;
use Billium\Execution\Data\DriverRequest;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ProviderLookupState;
use Billium\Testkit\Drivers\FaultInjectingPaymentDrivers;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use Billium\Testkit\Fixtures\TransportFaultAdapter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ConsumerBridgeFaultAdaptersTest extends TestCase
{
    public function test_fault_injecting_drivers_remain_simulated_and_emit_provider_double_evidence(): void
    {
        $journalFile = tempnam(sys_get_temp_dir(), 'billium-ct-drv-');
        self::assertIsString($journalFile);
        try {
            $clock = new FixtureClock(new DateTimeImmutable('2026-11-22T10:00:00Z'));
            $drivers = new FaultInjectingPaymentDrivers(
                new DurableJournal($journalFile),
                $clock,
                static fn (): int => 0,
            );
            self::assertSame('provider-double', $drivers->evidenceClass());
            self::assertSame(0, $drivers->paidPspTransactionCount());

            $cap = $drivers->registerAccount('account-a', SimulatedCapabilities::snapshot('account-a'), ResponseMode::AcceptedThenTimeout);
            $scope = new ExecutionScope('inst-test', 'seller-a', 'test', 'source-a');
            $binding = new FrozenBinding(
                $scope,
                'remote-billium',
                'intent-1',
                'op-1',
                'merchant-a',
                'account-a',
                $cap->driverId,
                'simulated-driver.v1',
                $cap->digest,
                'sha256:'.str_repeat('a', 64),
                'v1',
                null,
                'checkout',
            );
            $permit = new DispatchDecision('op-1', 'attempt-1', 1, 1, 'ticket-1', 'fence-1', null, 'sha256:'.str_repeat('b', 64), $clock->now->modify('+30 seconds'));
            $credentials = new ResolvedCredentials('account-a', 'cred-v1', new SecretRef('secret://fixture/a'), 'new', $clock->now->modify('+1 hour'), 'proof-a');
            $request = new DriverRequest($permit, $binding, $cap, new Money(1000, Currency::of('RUB')), 'ref-1', $credentials);

            $driver = $drivers->resolve($cap->driverId, 'simulated-driver.v1');
            $timeout = $driver->execute($request);
            self::assertSame(ProviderLookupState::Unavailable, $timeout->state);
            self::assertCount(1, $drivers->providerEvidence()['calls']);

            $signed = $drivers->signCallbackEvidence(
                $scope,
                $binding,
                'op-1',
                EffectKind::Charge,
                'ref-1',
                'simulated-effect-1',
                2,
                'succeeded',
                'RUB',
                1000,
                1000,
            );
            $normalized = $driver->normalize($signed, $cap);
            self::assertSame('succeeded', $normalized->normalizedStatus);
            self::assertSame(1000, $normalized->capturedTotal->minor);
        } finally {
            if (is_file($journalFile)) {
                unlink($journalFile);
            }
        }
    }

    public function test_transport_fault_adapter_validates_closed_commands_and_preserves_full_digest(): void
    {
        $adapter = new TransportFaultAdapter(new Codec);
        self::assertSame('transport-contract', $adapter->evidenceClass());

        try {
            $adapter->prepareCommand(
                json_encode([
                    'schema' => 'payment.execute.v2',
                    'commandKey' => '00000000-0000-4100-8800-000000000001',
                ], JSON_THROW_ON_ERROR),
                '/billium/v1/sellers/seller-a/payments/execute',
            );
            self::fail('Expected unsupported major schema rejection');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::SchemaUnsupported, $error->problem);
        }

        $codec = new Codec;
        $statusBody = [
            'schema' => 'ledger.payment_status.v1',
            'installationRef' => 'inst-test',
            'environment' => 'test',
            'sellerRef' => 'seller-a',
            'result' => ['projectionStatus' => 'current'],
            'additiveExtension' => ['bridgeTrace' => 'v1'],
        ];
        $statusBody['digest'] = $codec->digest($statusBody, 'digest');
        $verified = $adapter->verifyFullEnvelopeDigest(json_encode($statusBody, JSON_THROW_ON_ERROR));
        self::assertTrue($verified['valid']);
        self::assertTrue($verified['digestCoversUnknown']);
        self::assertSame(['additiveExtension'], $verified['unknownFields']);
    }
}

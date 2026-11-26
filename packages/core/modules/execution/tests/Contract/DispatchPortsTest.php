<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Contract;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Data\DispatchRequest;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\OriginDecision;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Testkit\Fixtures\DeterministicWriterHost;
use LogicException;
use PHPUnit\Framework\TestCase;

final class DispatchPortsTest extends TestCase
{
    private function binding(?OriginDecision $origin = null): FrozenBinding
    {
        return new FrozenBinding(new ExecutionScope('installation', 'seller', 'test', 'owner'), 'remote-billium', 'intent', 'root', 'revision',
            'account', 'driver', 'driver.v1', 'capability', 'semantic', 'execution.intent.v1', null, 'shop', $origin);
    }

    public function test_historical_binding_and_independent_v2_wire_hash_survive_rehydration(): void
    {
        $legacy = $this->binding();
        $origin = new OriginDecision($legacy->scope, 'root', 'intent', 'shop', 'remote-billium', 17, null, 'commercial-basis', 'policy.v3', null);
        $binding = $this->binding($origin);
        self::assertStringNotContainsString('origin', ExecutionCodec::encode($legacy));
        self::assertEquals($legacy, ExecutionCodec::decode(FrozenBinding::class, ExecutionCodec::encode($legacy)));
        self::assertSame(DeterministicWriterHost::bindingDigest($legacy), AuthorityClaims::bindingDigest($legacy));
        self::assertSame(DeterministicWriterHost::bindingDigest($binding), AuthorityClaims::bindingDigest($binding));
        self::assertNotSame(AuthorityClaims::bindingDigest($legacy), AuthorityClaims::bindingDigest($binding));
        self::assertEquals($binding, ExecutionCodec::decode(FrozenBinding::class, ExecutionCodec::encode($binding)));
        $amount = Money::parse('1.23', 'RUB');
        $digest = AuthorityClaims::immutableDriverRequestDigest($binding, 'root', 'attempt', 'reference', EffectKind::Charge, $amount);
        self::assertSame('sha256:9bf557d057025e80208888d27203a199f748e7bfeb7a214592d7bc4556d147a6', AuthorityClaims::bindingDigest($legacy));
        self::assertSame('sha256:840ff23c459ef901308860bf8df5de5e3f90d14cbba8ede0a4bd0493eaddec93', AuthorityClaims::bindingDigest($binding));
        self::assertSame('sha256:6be40069dd1ea83ab05c964eb8425a98c12925adfcb458b384687312515e4468', $digest);
        self::assertNotSame($binding->semanticDigest, $digest);
        self::assertNotSame($digest, AuthorityClaims::immutableDriverRequestDigest($binding, 'root', 'attempt', 'other', EffectKind::Charge, $amount));
        self::assertNotSame($digest, AuthorityClaims::immutableDriverRequestDigest($binding, 'root', 'attempt', 'reference', EffectKind::Authorize, $amount));
        self::assertNotSame($digest, AuthorityClaims::immutableDriverRequestDigest($binding, 'root', 'attempt', 'reference', EffectKind::Charge, Money::parse('1.24', 'RUB')));
    }

    public function test_stored_origin_rejects_coerced_routing_epoch_or_unknown_fields(): void
    {
        $legacy = $this->binding();
        $original = new OriginDecision($legacy->scope, 'root', 'intent', 'shop', 'remote-billium', 1, null, 'basis', 'policy', null);
        $body = ExecutionCodec::map(ExecutionCodec::encode($this->binding($original)));
        $origin = $body['origin'];
        self::assertIsArray($origin);
        foreach ([['routingEpoch' => '1'], ['unrecognizedPolicy' => true]] as $change) {
            $body['origin'] = [...$origin, ...$change];
            try {
                ExecutionCodec::decode(FrozenBinding::class, ExecutionCodec::encode($body));
                self::fail('Malformed stored commercial origin accepted');
            } catch (LogicException $e) {
                self::assertStringContainsString('stored original binding', $e->getMessage());
            }
        }
    }

    public function test_verified_origin_cannot_be_retargeted_to_another_root(): void
    {
        $origin = new OriginDecision($this->binding()->scope, 'other', 'intent', 'shop', 'remote-billium', 1, null, 'basis', 'policy', null);
        $this->expectException(ProblemException::class);
        $this->binding($origin);
    }

    public function test_a_binding_or_dispatch_request_without_capability_digest_is_refused(): void
    {
        $binding = $this->binding();
        $request = new DispatchRequest($binding->scope, 'root', 'attempt', 'reference', EffectKind::Charge, Money::parse('1.00', 'RUB'), 'binding', 'capability',
            'owner', 1, 1, 1, 1, 1, 'billium.psp_dispatch.v1');
        foreach ([
            'binding' => fn (): object => new FrozenBinding(...array_replace(get_object_vars($binding), ['capabilityDigest' => ''])),
            'dispatch request' => fn (): object => new DispatchRequest(...array_replace(get_object_vars($request), ['capabilityDigest' => ''])),
        ] as $case => $make) {
            try {
                $make();
                self::fail($case);
            } catch (ProblemException $e) {
                self::assertStringContainsString('capabilityDigest', $e->getMessage(), $case);
            }
        }
    }
}

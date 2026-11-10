<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Contract;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Execution\Contracts\CapabilityGate;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Data\Ambiguous;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\CertifiedAbsent;
use Billium\Execution\Data\DriverLookup;
use Billium\Execution\Data\ExecutionBinding;
use Billium\Execution\Data\QualificationManifest;
use Billium\Execution\Data\Unavailable;
use Billium\Execution\Data\UnsupportedCapability;
use Billium\Execution\Enums\CapabilityRequirement;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionUnionType;

final class CapabilityContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $vector = json_decode((string) file_get_contents(dirname(__DIR__, 6).'/fixtures/vectors/payment/capabilities/'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);

        return json_decode($vector['script'][0]['args']['json_text'], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $value */
    private function signed(array $value): string
    {
        $value['digest'] = (new Codec)->digest($value);

        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $snapshot */
    private function manifest(array $snapshot): QualificationManifest
    {
        $manifest = $this->fixture('simulated-qualification');
        foreach (['driver', 'driverVersion', 'provider', 'providerApiVersion'] as $key) {
            $manifest[$key] = $snapshot[$key];
        }
        $manifest['capabilityDigest'] = (new Codec)->digest($snapshot);

        return new QualificationManifest($this->signed($manifest));
    }

    public function test_capability_matrix_refuses_before_any_admission_or_provider_effect(): void
    {
        $full = $this->fixture('simulated-sbp-a');
        $restricted = $this->fixture('simulated-sbp-b');
        $gate = new CapabilityGate;
        foreach (CapabilityRequirement::cases() as $requirement) {
            $operations = $attempts = $reservations = $providerCalls = 0;
            $dispatch = function (array $values) use ($gate, $requirement, &$operations, &$attempts, &$reservations, &$providerCalls): ?UnsupportedCapability {
                $problem = $gate->check(
                    snapshot: new CapabilitySnapshot($this->signed($values)),
                    requirement: $requirement,
                    qualification: $this->manifest($values),
                );
                if ($problem !== null) {
                    return $problem;
                }
                $operations++;
                $attempts++;
                $reservations++;
                $providerCalls++;

                return null;
            };
            self::assertNull($dispatch($full), $requirement->value);
            self::assertSame([1, 1, 1, 1], [$operations, $attempts, $reservations, $providerCalls]);
            if ($requirement === CapabilityRequirement::Sale) {
                continue;
            }
            $problem = $dispatch($restricted);
            self::assertInstanceOf(UnsupportedCapability::class, $problem, $requirement->value);
            self::assertSame(ProblemCode::CapabilityUnsupported, $problem->problem);
            self::assertSame('none', $problem->problem->receipt());
            self::assertFalse($problem->problem->retryable());
            self::assertTrue($problem->problem->sameKey());
            self::assertSame([1, 1, 1, 1], [$operations, $attempts, $reservations, $providerCalls], 'Refusal created no additional effect');
        }
    }

    public function test_unverified_modes_and_missing_qualification_fail_closed(): void
    {
        $values = $this->fixture('simulated-sbp-a');
        $values['authorize'] = 'unverified';
        $snapshot = new CapabilitySnapshot($this->signed($values));
        self::assertFalse($snapshot->supports(CapabilityRequirement::Authorize));
        self::assertFalse($snapshot->supports(CapabilityRequirement::Capture));
        self::assertFalse($snapshot->supports(CapabilityRequirement::VoidAuthorization));
        self::assertNotNull((new CapabilityGate)->check(snapshot: $snapshot, requirement: CapabilityRequirement::Sale));
    }

    public function test_independent_simulated_callback_authenticity_fixture_rejects_wrong_signature_and_changed_bytes(): void
    {
        $vector = json_decode((string) file_get_contents(dirname(__DIR__, 6).'/fixtures/vectors/payment/capabilities/callback-signature-fixture.json'), true, 512, JSON_THROW_ON_ERROR);
        $fixture = $vector['given']['authenticity'];
        self::assertSame('fixture-hmac-sha256', $fixture['scheme']);
        $signature = hash_hmac('sha256', $fixture['body'], $fixture['key']);
        self::assertTrue(hash_equals($signature, $fixture['signature']));
        self::assertFalse(hash_equals($signature, $fixture['wrongSignature']));
        self::assertFalse(hash_equals(hash_hmac('sha256', $fixture['alteredBody'], $fixture['key']), $fixture['signature']));
    }

    public function test_capture_modes_are_not_interchangeable_and_refund_is_independently_gated(): void
    {
        foreach (['none' => [false, false, false], 'full' => [true, false, false], 'partial_single' => [true, true, false], 'partial_multiple' => [true, true, true], 'unverified' => [false, false, false]] as $mode => $expected) {
            $values = $this->fixture('simulated-sbp-a');
            $values['capture'] = $mode;
            $snapshot = new CapabilitySnapshot($this->signed($values));
            self::assertSame($expected, array_map($snapshot->supports(...), [CapabilityRequirement::Capture, CapabilityRequirement::PartialCapture, CapabilityRequirement::MultipleCapture]), $mode);
            self::assertTrue($snapshot->supports(CapabilityRequirement::Refund));
        }
    }

    public function test_simulated_and_sandbox_qualification_cannot_enable_production_or_another_version(): void
    {
        $values = $this->fixture('simulated-sbp-a');
        $snapshot = new CapabilitySnapshot($this->signed($values));
        $manifest = $this->fixture('simulated-qualification');
        foreach (['simulated', 'sandbox'] as $mode) {
            $manifest['qualification'] = $mode;
            $qualification = new QualificationManifest($this->signed($manifest));
            self::assertTrue($qualification->qualifies(snapshot: $snapshot, production: false));
            self::assertNotNull((new CapabilityGate)->check(snapshot: $snapshot, requirement: CapabilityRequirement::Sale, qualification: $qualification, production: true));
        }
        $manifest['qualification'] = 'production';
        $qualification = new QualificationManifest($this->signed($manifest));
        self::assertTrue($qualification->qualifies(snapshot: $snapshot, production: true));
        $values['driverVersion'] = 'future-version';
        self::assertFalse($qualification->qualifies(snapshot: new CapabilitySnapshot($this->signed($values)), production: true));
        $values = $this->fixture('simulated-sbp-a');
        $values['idempotency']['guaranteedTtlSeconds'] = 1;
        self::assertFalse($qualification->qualifies(snapshot: new CapabilitySnapshot($this->signed($values)), production: true));
        $manifest['unresolvedGaps'] = ['live rotation not proved'];
        self::assertFalse((new QualificationManifest($this->signed($manifest)))->qualifies(snapshot: $snapshot, production: true));
    }

    public function test_lookup_contract_distinguishes_uncertainty_and_cannot_infer_absence_from_transport(): void
    {
        foreach (['find', 'findRefund'] as $method) {
            $return = (new ReflectionMethod(PaymentDrivers::class, $method))->getReturnType();
            self::assertInstanceOf(ReflectionUnionType::class, $return);
            $types = array_map(static fn ($type): string => $type->getName(), $return->getTypes());
            foreach ([Unavailable::class, Ambiguous::class, UnsupportedCapability::class, CertifiedAbsent::class] as $expected) {
                self::assertContains($expected, $types);
            }
        }
        self::assertNotEquals(new Unavailable('HTTP 404'), new Ambiguous('HTTP 404'));
        self::assertTrue((new Unavailable('timeout'))->effectMayExist);
    }

    public function test_absence_certificate_is_bound_to_original_account_selector_and_dispatch_window(): void
    {
        $capabilities = new CapabilitySnapshot($this->signed($this->fixture('simulated-sbp-a')));
        $binding = new ExecutionBinding(
            installationRef: 'inst-test', sellerRef: 'seller-a', environment: 'test',
            merchantAccountRef: 'merchant:original', bindingRevision: 'binding:original',
            driver: 'simulated-sbp-a', driverVersion: 'fixture-1', providerApiVersion: 'fixture-api-1',
            credentialRevisionRef: 'credential:rotated-same-account', capabilityDigest: $capabilities->digest,
        );
        $lookup = new DriverLookup(
            binding: $binding, selectorKind: 'business_ref', selectorValue: 'business:1',
            dispatchFrom: new DateTimeImmutable('2026-11-09T00:00:00Z'),
            dispatchThrough: new DateTimeImmutable('2026-11-09T01:00:00Z'),
        );
        $value = $this->fixture('lookup-certified-absent');
        $certificate = new CertifiedAbsent(json: json_encode($value, JSON_THROW_ON_ERROR), query: $lookup, capabilities: $capabilities);
        self::assertSame('external:retirement-proof', $certificate->fenceProofRef);
        $value['absenceCertificate']['dispatchThrough'] = '2026-11-09T00:30:00Z';
        $this->expectException(InvalidArgumentException::class);
        new CertifiedAbsent(json: json_encode($value, JSON_THROW_ON_ERROR), query: $lookup, capabilities: $capabilities);
    }

    public function test_incomplete_lookup_cannot_produce_a_certificate_even_with_a_claimed_horizon(): void
    {
        $values = $this->fixture('simulated-sbp-a');
        $values['lookup']['business_ref']['mode'] = 'partial';
        $capabilities = new CapabilitySnapshot($this->signed($values));
        $binding = new ExecutionBinding(
            installationRef: 'inst-test', sellerRef: 'seller-a', environment: 'test',
            merchantAccountRef: 'merchant:original', bindingRevision: 'binding:original',
            driver: 'simulated-sbp-a', driverVersion: 'fixture-1', providerApiVersion: 'fixture-api-1',
            credentialRevisionRef: 'credential:1', capabilityDigest: $capabilities->digest,
        );
        $query = new DriverLookup(binding: $binding, selectorKind: 'business_ref', selectorValue: 'business:1',
            dispatchFrom: new DateTimeImmutable('2026-11-09T00:00:00Z'), dispatchThrough: new DateTimeImmutable('2026-11-09T01:00:00Z'));
        $this->expectException(InvalidArgumentException::class);
        new CertifiedAbsent(json: json_encode($this->fixture('lookup-certified-absent'), JSON_THROW_ON_ERROR), query: $query, capabilities: $capabilities);
    }
}

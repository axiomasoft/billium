<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Contract;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\PaymentDriver;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Data\CapabilityDetails;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\MerchantRevisionPolicy;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Enums\CaptureMode;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ProviderEvidenceMode;
use Billium\Execution\Enums\QualificationMode;
use Billium\Execution\Internal\Admission\QualifiedAdapter;
use Billium\Execution\Internal\Dispatch\TransportBoundary;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('COV-396')]
final class SbpWorkflowSelectionTest extends TestCase
{
    public function test_two_named_sbp_adapters_admit_only_their_independently_qualified_workflows(): void
    {
        $bankA = self::snapshot('bank-a', refunds: false);
        $bankB = self::snapshot('bank-b', refunds: true);
        $merchantA = self::merchant('bank-a', $bankA);
        $merchantB = self::merchant('bank-b', $bankB);
        self::assertNotSame($merchantA->providerId, $merchantB->providerId);
        self::assertNotSame($merchantA->driverId, $merchantB->driverId);
        self::assertNotSame($merchantA->merchantAccountRef, $merchantB->merchantAccountRef);
        $driverA = $this->driver($bankA->driverContractVersion);
        $driverB = $this->driver($bankB->driverContractVersion);

        $merchants = self::createStub(MerchantRegistry::class);
        $merchants->method('capabilities')->willReturnMap([[$bankA->snapshotRef, $bankA], [$bankB->snapshotRef, $bankB]]);
        $drivers = self::createStub(PaymentDrivers::class);
        $drivers->method('resolve')->willReturnCallback(static fn (string $id, string $version): PaymentDriver => match ([$id, $version]) {
            [$bankA->driverId, $bankA->driverContractVersion] => $driverA,
            [$bankB->driverId, $bankB->driverContractVersion] => $driverB,
            default => throw new ProblemException(ProblemCode::CapabilityUnsupported, 'No exact registered SBP driver version'),
        });
        $drivers->method('capabilities')->willReturnCallback(static fn (MerchantRevision $merchant): CapabilitySnapshot => match ($merchant->revisionRef) {
            'bank-a-changed-capability', 'bank-b' => $bankB,
            default => $bankA,
        });
        $verified = [];
        $verifier = self::createStub(AdapterQualification::class);
        $verifier->method('verify')->willReturnCallback(static function (MerchantRevision $merchant, CapabilitySnapshot $cap, EffectKind $kind) use (&$verified): void {
            $evidence = [$merchant->revisionRef, $merchant->driverContractVersion, $merchant->merchantAccountRef, $cap->qualificationEvidenceRef, $kind];
            $verified[] = $evidence;
            if (! in_array($evidence, [
                ['bank-a', 'bank-a-sbp.v1', 'bank-a-account', 'bank-a-qualification', EffectKind::Charge],
                ['bank-b', 'bank-b-sbp.v1', 'bank-b-account', 'bank-b-qualification', EffectKind::Charge],
                ['bank-b', 'bank-b-sbp.v1', 'bank-b-account', 'bank-b-qualification', EffectKind::Refund],
            ], true)) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'No independent bank workflow evidence');
            }
        });
        $databases = self::createStub(DatabaseManager::class);
        $databases->method('getConnections')->willReturn([]);
        $selector = new QualifiedAdapter($merchants, static fn (): PaymentDrivers => $drivers, static fn (): AdapterQualification => $verifier, new TransportBoundary($databases));

        [$selectedA, $profileA] = $selector->select($merchantA, EffectKind::Charge, 'RUB');
        [$selectedB, $profileB] = $selector->select($merchantB, EffectKind::Charge, 'RUB');
        self::assertSame([$driverA, $bankA], [$selectedA, $profileA]);
        self::assertSame([$driverB, $bankB], [$selectedB, $profileB]);
        self::assertSame([true, true, false], [$profileA->details->lookupByProviderId, $profileA->lookupByReference, $profileA->details->refundLookup]);
        self::assertSame([true, false, true], [$profileB->details->lookupByProviderId, $profileB->lookupByReference, $profileB->details->refundLookup]);
        self::assertSame([$driverB, $bankB], $selector->select($merchantB, EffectKind::Refund, 'RUB'));

        foreach ([
            [$merchantA, EffectKind::Refund, 'RUB'],
            [$merchantB, EffectKind::Authorize, 'RUB'],
            [$merchantB, EffectKind::Charge, 'USD'],
            [self::merchant('bank-a', $bankA, account: $bankB->merchantAccountRef), EffectKind::Charge, 'RUB'],
            [self::merchant('bank-a', $bankA, driverVersion: $bankB->driverContractVersion), EffectKind::Charge, 'RUB'],
            [self::merchant('bank-a-changed-capability', $bankA), EffectKind::Charge, 'RUB'],
            [self::merchant('bank-b', $bankB, environment: 'production'), EffectKind::Refund, 'RUB'],
        ] as [$merchant, $kind, $currency]) {
            try {
                $selector->select($merchant, $kind, $currency);
                self::fail('Unsupported SBP workflow reached the admission continuation');
            } catch (ProblemException $exception) {
                self::assertSame(ProblemCode::CapabilityUnsupported, $exception->problem);
            }
        }
        self::assertCount(3, $verified, 'Invalid or unsupported fixtures must fail before host verification');

        try {
            $selector->select(self::merchant('unproven-bank-a', $bankA), EffectKind::Charge, 'RUB');
            self::fail('An unproven binding passed the independent qualification gate');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::RecoveryRequired, $exception->problem);
        }
        self::assertCount(4, $verified);
    }

    private function driver(string $contractVersion): PaymentDriver
    {
        $driver = $this->createMock(PaymentDriver::class);
        $driver->method('contractVersion')->willReturn($contractVersion);
        foreach (['execute', 'authorize', 'capture', 'voidAuthorization', 'refund', 'lookup', 'inventory'] as $method) {
            $driver->expects(self::never())->method($method);
        }

        return $driver;
    }

    private static function snapshot(string $bank, bool $refunds): CapabilitySnapshot
    {
        $effects = $refunds ? [EffectKind::Charge, EffectKind::Refund] : [EffectKind::Charge];
        $details = new CapabilityDetails(
            version: 'sbp-fixture.v1',
            initiateWithIdempotency: true,
            initiateWithoutIdempotency: false,
            idempotencyKeyScope: $bank.'/account/reference',
            idempotencyRetentionSeconds: 300,
            lookupByProviderId: true,
            lookupVisibility: $refunds ? 'eventual' : 'complete',
            lookupRetentionSeconds: $refunds ? 86400 : 172800,
            captureMode: CaptureMode::None,
            multipleRefund: $refunds,
            refundLookup: $refunds,
            signedCallbacks: false,
            callbackEventIdentity: false,
            callbackEventLookup: false,
            asynchronousStates: true,
            settlementListing: false,
            settlementExport: false,
            disputes: false,
            savedMethod: false,
            recurringConsent: false,
            fiscalPassthrough: false,
            lookupNamespace: $bank.'/account/lookup',
            referenceSemantics: 'immutable-reference.v1',
            qualificationManifestRef: $bank.'-manifest',
        );

        return new CapabilitySnapshot(
            snapshotRef: $bank.'-sbp-snapshot',
            driverId: $bank.'-sbp',
            driverContractVersion: $bank.'-sbp.v1',
            capabilityVersion: 'sbp-fixture.v1',
            merchantAccountRef: $bank.'-account',
            supportedEffects: $effects,
            currencies: ['RUB'],
            evidenceMode: ProviderEvidenceMode::CumulativeSnapshot,
            partialCapture: false,
            partialRefund: $refunds,
            partialVoidAuthorization: false,
            authorizationExpiryEvidence: false,
            lookupByReference: ! $refunds,
            inventoryComplete: false,
            absenceCertification: false,
            idempotencyValidUntil: null,
            qualificationMode: QualificationMode::Simulated,
            qualificationEvidenceRef: $bank.'-qualification',
            digest: hash('sha256', $bank.'-immutable-sbp-fixture'),
            details: $details,
        );
    }

    private static function merchant(string $revision, CapabilitySnapshot $capability, string $environment = 'test', ?string $account = null, ?string $driverVersion = null): MerchantRevision
    {
        $time = new DateTimeImmutable('2026-11-17T00:00:00Z');

        return new MerchantRevision($revision, new ExecutionScope('test-installation', 'seller-a', $environment, 'sbp-owner'),
            $account ?? $capability->merchantAccountRef, $capability->driverId.'-provider', $capability->driverId, $driverVersion ?? $capability->driverContractVersion,
            $capability->snapshotRef, $capability->digest, new SecretRef('secret://fixture/sbp'), 'credential-v1', ['RUB'],
            hash('sha256', $revision), $time, new MerchantRevisionPolicy($time, null, null, 'fixture-sbp.v1', $revision.'-webhook',
                $revision.'-continuity', $revision.'-settlement', $revision.'-mandate'));
    }
}

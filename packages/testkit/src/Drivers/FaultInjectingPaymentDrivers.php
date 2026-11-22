<?php

declare(strict_types=1);

namespace Billium\Testkit\Drivers;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\PaymentDriver;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\QualificationMode;
use Billium\Testkit\Fixtures\DeterministicWriterHost;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use Billium\Testkit\Fixtures\FixtureValue;
use Billium\Testkit\Fixtures\JournalEvent;
use Closure;
use DateTimeImmutable;
use Packman\Attributes\Api;
use RuntimeException;

/**
 * Dev-only PaymentDrivers and qualification fault adapter for consumer/bridge contract tests.
 *
 * Uses only public Billium APIs/SPIs, enforces simulated-only qualification (zero paid PSP effects),
 * and labels its output explicitly as provider-double so it can never be promoted to external readiness.
 */
#[Api(since: '1.0')]
final class FaultInjectingPaymentDrivers implements AdapterQualification, PaymentDrivers
{
    private const string FIXTURE_HMAC_KEY = 'simulated-public-fixture-key-not-production';

    /** @var array<string, CapabilitySnapshot> */
    private array $snapshotsByAccount = [];

    /** @var array<string, ResponseMode> */
    private array $modesByAccount = [];

    private string $activeAccount = 'account-a';

    /**
     * @param  Closure():int  $transactionLevel
     * @param  null|Closure(string):void  $checkpoint
     */
    public function __construct(
        public readonly DurableJournal $journal,
        private readonly FixtureClock $clock,
        private readonly Closure $transactionLevel,
        private ResponseMode $defaultMode = ResponseMode::Success,
        private bool $qualified = true,
        private readonly ?Closure $checkpoint = null,
    ) {
        $this->registerAccount('account-a');
    }

    public function evidenceClass(): string
    {
        return 'provider-double';
    }

    public function paidPspTransactionCount(): int
    {
        return 0;
    }

    public function registerAccount(
        string $merchantAccountRef,
        ?CapabilitySnapshot $capabilities = null,
        ?ResponseMode $mode = null,
    ): CapabilitySnapshot {
        $snapshot = $capabilities ?? SimulatedCapabilities::snapshot($merchantAccountRef);
        if ($snapshot->qualificationMode !== QualificationMode::Simulated) {
            throw new RuntimeException('FaultInjectingPaymentDrivers accepts only QualificationMode::Simulated');
        }
        $this->snapshotsByAccount[$merchantAccountRef] = $snapshot;
        if ($mode !== null) {
            $this->modesByAccount[$merchantAccountRef] = $mode;
            $this->activeAccount = $merchantAccountRef;
        }

        return $snapshot;
    }

    public function setMode(ResponseMode $mode, ?string $merchantAccountRef = null): void
    {
        if ($merchantAccountRef !== null) {
            $this->modesByAccount[$merchantAccountRef] = $mode;
            $this->activeAccount = $merchantAccountRef;

            return;
        }
        $this->defaultMode = $mode;
        foreach (array_keys($this->snapshotsByAccount) as $account) {
            $this->modesByAccount[$account] = $mode;
        }
    }

    public function setQualified(bool $qualified): void
    {
        $this->qualified = $qualified;
    }

    public function setActiveAccount(string $merchantAccountRef): void
    {
        if (! isset($this->snapshotsByAccount[$merchantAccountRef])) {
            $this->registerAccount($merchantAccountRef);
        }
        $this->activeAccount = $merchantAccountRef;
    }

    public function forAccount(string $merchantAccountRef): DeterministicPaymentDriver
    {
        $snapshot = $this->snapshotsByAccount[$merchantAccountRef] ?? $this->registerAccount($merchantAccountRef);
        $mode = $this->modesByAccount[$merchantAccountRef] ?? $this->defaultMode;

        return new DeterministicPaymentDriver(
            $this->journal,
            $this->clock,
            $snapshot,
            $this->transactionLevel,
            $mode,
            $this->checkpoint,
        );
    }

    public function resolve(string $driverId, string $contractVersion): PaymentDriver
    {
        $driver = $this->forAccount($this->activeAccount);
        $snapshot = $this->snapshotsByAccount[$this->activeAccount];
        if ($driverId !== $snapshot->driverId || $contractVersion !== $driver->contractVersion()) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Unknown simulated provider driver contract');
        }

        return $driver;
    }

    public function capabilities(MerchantRevision $merchant): CapabilitySnapshot
    {
        $this->activeAccount = $merchant->merchantAccountRef;

        return $this->snapshotsByAccount[$merchant->merchantAccountRef] ?? $this->registerAccount($merchant->merchantAccountRef);
    }

    public function verify(MerchantRevision $revision, CapabilitySnapshot $snapshot, EffectKind $kind): void
    {
        $approved = $this->snapshotsByAccount[$revision->merchantAccountRef] ?? null;
        if (
            ! $this->qualified
            || $snapshot->qualificationMode !== QualificationMode::Simulated
            || $approved === null
            || ! FixtureValue::same($snapshot, $approved)
            || ! in_array($kind, $snapshot->supportedEffects, true)
        ) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Simulated host qualification refused merchant/effect');
        }
        $this->activeAccount = $revision->merchantAccountRef;
    }

    /**
     * Builds a signed simulated callback evidence envelope for deterministic late/out-of-order callback tests.
     */
    public function signCallbackEvidence(
        ExecutionScope $scope,
        FrozenBinding $binding,
        string $operationRef,
        EffectKind $kind,
        string $providerReference,
        string $stableEffectId,
        int $revision,
        string $status,
        string $currency,
        int $authorizedMinor,
        int $capturedMinor,
        int $refundedMinor = 0,
        int $voidedMinor = 0,
        ?int $operationAmountMinor = null,
        ?string $providerOperationId = null,
        ?DateTimeImmutable $observedAt = null,
    ): ProviderEvidence {
        $snapshot = $this->snapshotsByAccount[$binding->merchantAccountRef] ?? $this->registerAccount($binding->merchantAccountRef);
        $unit = Currency::of($currency);
        $at = $observedAt ?? $this->clock->now;
        $material = [
            'schema' => 'billium.testkit.provider-material.v2',
            'scope' => get_object_vars($scope),
            'bindingDigest' => DeterministicWriterHost::bindingDigest($binding),
            'reference' => $providerReference,
            'operationRef' => $operationRef,
            'kind' => $kind->value,
            'providerId' => 'simulated-provider',
            'merchantAccountRef' => $binding->merchantAccountRef,
            'capabilityDigest' => $snapshot->digest,
            'handlerContractVersion' => 'simulated-driver.v1',
            'currency' => $unit->code,
            'authorized' => new Money($authorizedMinor, $unit)->toWire(),
            'captured' => new Money($capturedMinor, $unit)->toWire(),
            'refunded' => new Money($refundedMinor, $unit)->toWire(),
            'voided' => new Money($voidedMinor, $unit)->toWire(),
            'status' => $status,
            'revision' => (string) $revision,
            'stableEffectId' => $stableEffectId,
            'observedAt' => $at->format('Y-m-d\TH:i:s.uP'),
            'providerOperationId' => $providerOperationId ?? ('simulated-op-'.$stableEffectId),
            'operationAmount' => $status === 'pending'
                ? null
                : new Money($operationAmountMinor ?? ($status === 'succeeded' ? max($capturedMinor, $authorizedMinor, $refundedMinor, $voidedMinor) : 0), $unit)->toWire(),
        ];

        return $this->sealMaterial($scope, $binding->merchantAccountRef, $material);
    }

    /**
     * Produces a re-signed variant of an existing simulated ProviderEvidence with updated material fields.
     *
     * @param  array<string, mixed>  $materialChanges
     */
    public function mutateSignedEvidence(ProviderEvidence $original, array $materialChanges): ProviderEvidence
    {
        $material = $original->sanitizedPayload['material'] ?? null;
        if (! is_array($material)) {
            throw new RuntimeException('Expected simulated provider material payload');
        }
        $merged = array_replace($material, $materialChanges);

        return $this->sealMaterial($original->scope, $original->merchantAccountRef, $merged);
    }

    /**
     * @return array{calls: list<array<string, bool|int|string>>, lookups: list<array<string, bool|int|string>>}
     */
    public function providerEvidence(): array
    {
        $events = ['calls' => [], 'lookups' => []];
        foreach ($this->journal->records() as $record) {
            if ($record instanceof JournalEvent && $record->kind === 'provider.call') {
                $events['calls'][] = $record->data;
            } elseif ($record instanceof JournalEvent && $record->kind === 'provider.lookup') {
                $events['lookups'][] = $record->data;
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $material
     */
    private function sealMaterial(ExecutionScope $scope, string $merchantAccountRef, array $material): ProviderEvidence
    {
        $canonical = (new Codec)->canonicalize($material);
        $digest = hash('sha256', $canonical);
        $effectId = is_string($material['stableEffectId'] ?? null) ? $material['stableEffectId'] : 'unknown';
        $revision = is_string($material['revision'] ?? null) ? $material['revision'] : '1';

        return new ProviderEvidence(
            'evidence:'.$digest,
            $scope,
            'simulated-provider',
            $merchantAccountRef,
            'simulated-event:'.$effectId.':'.$revision,
            $digest,
            'simulated-valid',
            'simulated-driver.v1',
            $this->clock->now,
            [
                'material' => $material,
                'fixtureSignature' => hash_hmac('sha256', $canonical, self::FIXTURE_HMAC_KEY),
            ],
            'fixture-journal:'.$effectId,
        );
    }
}

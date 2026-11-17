<?php

declare(strict_types=1);

namespace Billium\Testkit\Drivers;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Contracts\PaymentDriver;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\DriverRequest;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\InventoryPage;
use Billium\Execution\Data\LookupRequest;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Enums\CaptureMode;
use Billium\Execution\Enums\DriverCapability;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ProviderLookupState;
use Billium\Execution\Enums\QualificationMode;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use Billium\Testkit\Fixtures\FixtureValue;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessIdentity;
use Closure;
use DateTimeImmutable;
use Packman\Attributes\Api;
use RuntimeException;

/** Deterministic test PSP. All external state and sends survive a Billium DB restore. */
#[Api(since: '1.0')]
final readonly class DeterministicPaymentDriver implements PaymentDriver
{
    /**
     * @param  Closure():int  $transactionLevel
     * @param  null|Closure(string):void  $checkpoint
     */
    public function __construct(public DurableJournal $journal, private FixtureClock $clock, public CapabilitySnapshot $capabilities, private Closure $transactionLevel, public ResponseMode $mode = ResponseMode::Success, private ?Closure $checkpoint = null)
    {
        if ($capabilities->qualificationMode !== QualificationMode::Simulated) {
            throw new RuntimeException('Test driver accepts only simulated qualification');
        }
    }

    public function contractVersion(): string
    {
        return 'simulated-driver.v1';
    }

    public function execute(DriverRequest $driverRequest): ProviderLookupResult
    {
        return $this->send(EffectKind::Charge, $driverRequest);
    }

    public function authorize(DriverRequest $driverRequest): ProviderLookupResult
    {
        return $this->send(EffectKind::Authorize, $driverRequest);
    }

    public function capture(DriverRequest $driverRequest): ProviderLookupResult
    {
        return $this->send(EffectKind::Capture, $driverRequest);
    }

    public function voidAuthorization(DriverRequest $driverRequest): ProviderLookupResult
    {
        return $this->send(EffectKind::VoidAuthorization, $driverRequest);
    }

    public function refund(DriverRequest $driverRequest): ProviderLookupResult
    {
        return $this->send(EffectKind::Refund, $driverRequest);
    }

    private function outsideTransaction(): void
    {
        if (($this->transactionLevel)() !== 0) {
            throw new RuntimeException('Simulated external call inside transaction');
        }
    }

    private function binding(FrozenBinding $binding, ResolvedCredentials $credentials): void
    {
        if ($binding->merchantAccountRef !== $this->capabilities->merchantAccountRef || $credentials->merchantAccountRef !== $binding->merchantAccountRef || $binding->driverId !== $this->capabilities->driverId || $binding->driverContractVersion !== $this->contractVersion() || $binding->capabilityDigest !== $this->capabilities->digest || $credentials->expiresAt <= $this->clock->now) {
            throw new RuntimeException('Foreign, changed or expired merchant binding');
        }
    }

    private function send(EffectKind $kind, DriverRequest $request): ProviderLookupResult
    {
        $this->outsideTransaction();
        $this->binding($request->binding, $request->credentials);
        $request->capabilities->requireEffect($kind, $request->amount->currency->code, false);
        if (! FixtureValue::same($request->capabilities, $this->capabilities) || $request->amount->minor <= 0) {
            throw new RuntimeException('Changed capability or invalid amount');
        }
        $existing = $this->journal->records();
        if ($this->replay($kind, $request, $existing) === null) {
            $this->validateEffect($kind, $request, $existing);
        }
        $this->journal->append(new JournalEvent('provider.call', ['kind' => $kind->value, 'operation' => $request->permit->operationRef, 'reference' => $request->providerReference, 'account' => $request->binding->merchantAccountRef], ProcessIdentity::current()));
        if ($this->mode === ResponseMode::Unavailable) {
            return new ProviderLookupResult(ProviderLookupState::Unavailable, null, null, null, 'simulated-unavailable', null);
        }
        $this->checkpoint?->__invoke('before_accept');
        $record = $this->journal->transact(function (array $records) use ($kind, $request): array {
            $old = $this->replay($kind, $request, $records);
            if ($old !== null) {
                return ['value' => $old, 'append' => []];
            }
            $this->validateEffect($kind, $request, $records);
            $identity = 'simulated-effect-'.(count($records) + 1);
            $status = match ($this->mode) {
                ResponseMode::Pending => 'pending', ResponseMode::Declined => 'declined', default => 'succeeded'
            };
            $record = new ProviderRecord($identity, $kind, $request, $this->clock->now, ProcessIdentity::current(), $status);

            return ['value' => $record, 'append' => [$record]];
        });
        $this->checkpoint?->__invoke('after_accept');
        if ($this->mode === ResponseMode::AcceptedThenTimeout) {
            return new ProviderLookupResult(ProviderLookupState::Unavailable, null, null, null, 'simulated-response-lost', null);
        }

        return $this->result($record);
    }

    /** @param list<object> $records */
    private function replay(EffectKind $kind, DriverRequest $request, array $records): ?ProviderRecord
    {
        $ttl = $this->capabilities->details->idempotencyRetentionSeconds;
        foreach ($records as $old) {
            if (! $old instanceof ProviderRecord || $old->kind !== $kind || $old->request->providerReference !== $request->providerReference || $old->request->binding->scope->environment !== $request->binding->scope->environment || $old->request->binding->merchantAccountRef !== $request->binding->merchantAccountRef) {
                continue;
            }
            if ($ttl !== null && $old->acceptedAt->getTimestamp() + $ttl > $this->clock->now->getTimestamp()) {
                if (! FixtureValue::same($old->request->amount, $request->amount) || ! FixtureValue::same($old->request->binding, $request->binding)) {
                    throw new RuntimeException('Provider idempotency payload conflict');
                }

                return $old;
            }
        }

        return null;
    }

    /** @param list<object> $records */
    private function validateEffect(EffectKind $kind, DriverRequest $request, array $records): void
    {
        $totals = ['authorized' => 0, 'captured' => 0, 'refunded' => 0, 'voided' => 0];
        $captures = 0;
        $refunds = 0;
        foreach ($records as $record) {
            if (! $record instanceof ProviderRecord || $record->request->binding->rootOperationRef !== $request->binding->rootOperationRef || ! FixtureValue::same($record->request->binding->scope, $request->binding->scope) || $record->request->binding->merchantAccountRef !== $request->binding->merchantAccountRef) {
                continue;
            }
            $status = $record->status;
            foreach ($records as $settlement) {
                if ($settlement instanceof ProviderSettlement && $settlement->identity === $record->identity) {
                    $status = $settlement->status;
                }
            }
            if (! in_array($status, ['succeeded', 'pending'], true)) {
                continue;
            }
            $field = match ($record->kind) {
                EffectKind::Authorize => 'authorized', EffectKind::Charge, EffectKind::Capture => 'captured', EffectKind::Refund => 'refunded', EffectKind::VoidAuthorization => 'voided'
            };
            $amount = $record->request->amount->minor;
            if ($amount > PHP_INT_MAX - $totals[$field]) {
                throw new RuntimeException('Simulated provider overflow');
            }
            $totals[$field] += $amount;
            if ($record->kind === EffectKind::Capture) {
                $captures++;
            }
            if ($record->kind === EffectKind::Refund) {
                $refunds++;
            }
        }
        $remaining = $totals['authorized'] - $totals['captured'] - $totals['voided'];
        $amount = $request->amount->minor;
        if ($kind === EffectKind::Capture && ($amount > $remaining || ($this->capabilities->details->captureMode === CaptureMode::Full && $amount !== $remaining) || ($this->capabilities->details->captureMode === CaptureMode::PartialSingle && $captures !== 0))) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Simulated capture mode/capacity refuses request');
        }
        if ($kind === EffectKind::VoidAuthorization && ($amount > $remaining || (! $this->capabilities->partialVoidAuthorization && $amount !== $remaining))) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Simulated void mode/capacity refuses request');
        }
        if ($kind === EffectKind::Refund && ($amount > $totals['captured'] - $totals['refunded'] || (! $this->capabilities->partialRefund && $amount !== $totals['captured']) || (! $this->capabilities->details->multipleRefund && $refunds !== 0))) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Simulated refund mode/capacity refuses request');
        }
    }

    private function result(ProviderRecord $record): ProviderLookupResult
    {
        $effectiveStatus = $record->status;
        $totals = ['authorized' => 0, 'captured' => 0, 'refunded' => 0, 'voided' => 0];
        $revision = 0;
        $identities = [];
        $entries = $this->journal->records();
        foreach ($entries as $entry) {
            if ($entry instanceof ProviderSettlement && $entry->identity === $record->identity) {
                $effectiveStatus = $entry->status;
            }
        }
        foreach ($entries as $entry) {
            if (! $entry instanceof ProviderRecord || $entry->request->binding->rootOperationRef !== $record->request->binding->rootOperationRef || ! FixtureValue::same($entry->request->binding->scope, $record->request->binding->scope) || $entry->request->binding->merchantAccountRef !== $record->request->binding->merchantAccountRef) {
                continue;
            }
            $revision++;
            $identities[$entry->identity] = true;
            $status = $entry->status;
            foreach ($entries as $settlement) {
                if ($settlement instanceof ProviderSettlement && $settlement->identity === $entry->identity) {
                    $status = $settlement->status;
                }
            }
            if ($status !== 'succeeded') {
                continue;
            }
            $field = match ($entry->kind) {
                EffectKind::Authorize => 'authorized', EffectKind::Charge, EffectKind::Capture => 'captured', EffectKind::Refund => 'refunded', EffectKind::VoidAuthorization => 'voided'
            };
            if ($entry->request->amount->minor > PHP_INT_MAX - $totals[$field]) {
                throw new RuntimeException('Simulated provider overflow');
            }
            $totals[$field] += $entry->request->amount->minor;
        }
        $currency = $record->request->amount->currency;
        foreach ($entries as $settlement) {
            if ($settlement instanceof ProviderSettlement && isset($identities[$settlement->identity])) {
                $revision++;
            }
        }
        $digest = hash('sha256', serialize([$record->identity, $totals, $revision, $effectiveStatus]));
        $snapshot = new MaterialSnapshot($record->request->permit->operationRef, $record->kind, $record->request->binding->merchantAccountRef, $currency->code, new Money($totals['authorized'], $currency), new Money($totals['captured'], $currency), new Money($totals['refunded'], $currency), new Money($totals['voided'], $currency), $effectiveStatus, (string) $revision, $record->identity, $digest, ['evidence:'.$digest], $this->clock->now);
        $evidence = new ProviderEvidence('evidence:'.$digest, $record->request->binding->scope, 'simulated-provider', $record->request->binding->merchantAccountRef, 'simulated-event:'.$record->identity.':'.$revision, $digest, 'simulated-valid', $this->contractVersion(), $this->clock->now, ['snapshot' => $snapshot, 'fixtureSignature' => hash_hmac('sha256', serialize([$snapshot, $record->request->binding->scope]), 'simulated-public-fixture-key-not-production')], null);

        return new ProviderLookupResult($effectiveStatus === 'pending' ? ProviderLookupState::Pending : ProviderLookupState::Found, $evidence, $snapshot, null, null, null);
    }

    public function settle(string $identity, string $status): ProviderLookupResult
    {
        $this->outsideTransaction();
        if (! in_array($status, ['succeeded', 'declined'], true)) {
            throw new RuntimeException('Invalid simulated settlement');
        }
        $found = null;
        foreach ($this->journal->records() as $record) {
            if ($record instanceof ProviderRecord && $record->identity === $identity) {
                $found = $record;
            }
        }
        if ($found === null) {
            throw new RuntimeException('Unknown simulated effect');
        }
        if ($this->result($found)->snapshot?->normalizedStatus === 'succeeded' && $status !== 'succeeded') {
            throw new RuntimeException('Cannot erase successful provider effect');
        }
        $this->journal->append(new ProviderSettlement($identity, $status, $this->clock->now, ProcessIdentity::current()));

        return $this->result($found);
    }

    public function lookup(LookupRequest $lookupRequest): ProviderLookupResult
    {
        $this->outsideTransaction();
        $this->binding($lookupRequest->binding, $lookupRequest->credentials);
        $this->capabilities->requireCapability($lookupRequest->providerOperationId === null ? DriverCapability::LookupByReference : DriverCapability::LookupByProviderId, false);
        $this->journal->append(new JournalEvent('provider.lookup', ['operation' => $lookupRequest->operationRef], ProcessIdentity::current()));
        foreach (array_reverse($this->journal->records()) as $record) {
            if (! $record instanceof ProviderRecord || ! FixtureValue::same($record->request->binding->scope, $lookupRequest->binding->scope) || $record->request->binding->merchantAccountRef !== $lookupRequest->binding->merchantAccountRef || $record->request->binding->rootOperationRef !== $lookupRequest->binding->rootOperationRef || $record->request->permit->operationRef !== $lookupRequest->operationRef) {
                continue;
            }
            if (($lookupRequest->providerOperationId !== null ? $record->identity === $lookupRequest->providerOperationId : $record->request->providerReference === $lookupRequest->providerReference)) {
                $retention = $this->capabilities->details->lookupRetentionSeconds;
                if ($retention !== null && $record->acceptedAt->getTimestamp() + $retention <= $this->clock->now->getTimestamp()) {
                    break;
                }
                if ($this->capabilities->details->lookupVisibility !== 'complete') {
                    break;
                }

                return $this->result($record);
            }
        }

        return new ProviderLookupResult(ProviderLookupState::Ambiguous, null, null, null, 'simulated-no-completeness-proof', null);
    }

    public function inventory(MerchantRevision $merchantRevision, ResolvedCredentials $resolvedCredentials, DateTimeImmutable $from, ?string $cursor): InventoryPage
    {
        $this->outsideTransaction();
        $this->capabilities->requireCapability(DriverCapability::SettlementListing, false);
        if ($merchantRevision->providerId !== 'simulated-provider' || $merchantRevision->driverContractVersion !== $this->contractVersion() || $merchantRevision->capabilityDigest !== $this->capabilities->digest || $merchantRevision->merchantAccountRef !== $this->capabilities->merchantAccountRef || $resolvedCredentials->merchantAccountRef !== $merchantRevision->merchantAccountRef || $resolvedCredentials->expiresAt <= $this->clock->now || $cursor !== null || $from > $this->clock->now) {
            throw new RuntimeException('Invalid simulated inventory scope');
        }
        $this->journal->append(new JournalEvent('provider.inventory', ['account' => $merchantRevision->merchantAccountRef, 'from' => $from->format(DATE_ATOM)], ProcessIdentity::current()));
        $evidence = [];
        foreach ($this->journal->records() as $record) {
            if ($record instanceof ProviderRecord && $record->request->binding->scope->environment === $merchantRevision->scope->environment && $record->request->binding->merchantAccountRef === $merchantRevision->merchantAccountRef && $record->acceptedAt >= $from) {
                $item = $this->result($record)->evidence;
                if ($item !== null) {
                    $evidence[] = $item;
                }
            }
        }
        $complete = $this->capabilities->inventoryComplete;

        return new InventoryPage($evidence, null, $complete ? 'simulated-through:'.$this->clock->now->format(DATE_ATOM) : null, $complete, $merchantRevision->merchantAccountRef, $this->capabilities->qualificationEvidenceRef);
    }

    public function normalize(ProviderEvidence $providerEvidence, CapabilitySnapshot $capabilitySnapshot): MaterialSnapshot
    {
        $snapshot = $providerEvidence->sanitizedPayload['snapshot'] ?? null;
        $signature = $providerEvidence->sanitizedPayload['fixtureSignature'] ?? null;
        if (! $snapshot instanceof MaterialSnapshot || ! is_string($signature) || ! hash_equals(hash_hmac('sha256', serialize([$snapshot, $providerEvidence->scope]), 'simulated-public-fixture-key-not-production'), $signature) || $providerEvidence->signatureVerdict !== 'simulated-valid' || ! FixtureValue::same($capabilitySnapshot, $this->capabilities) || $providerEvidence->merchantAccountRef !== $capabilitySnapshot->merchantAccountRef || $snapshot->merchantAccountRef !== $providerEvidence->merchantAccountRef || $snapshot->materialDigest !== $providerEvidence->payloadDigest) {
            throw new RuntimeException('Invalid simulated evidence');
        }

        return $snapshot;
    }
}

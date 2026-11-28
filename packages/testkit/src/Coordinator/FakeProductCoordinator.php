<?php

declare(strict_types=1);

namespace Billium\Testkit\Coordinator;

use Billium\Collection\Contracts\CollectionSignalService;
use Billium\Collection\Data\CollectionHostSignalView;
use Billium\Collection\Data\CollectionHostStatusSnapshot;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Enums\CollectionSignalKind;
use Billium\Collection\Enums\HostSnapshotFreshness;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Api;

/**
 * Deterministic host ProductCoordinator test double for acceptance tests.
 *
 * Consumes durable signal-only facts from Billium Collection without ever deleting host resources
 * or mutating grants/provisioning/GL directly, enforces provision-only retries on paid+provision-failure
 * (zero extra charges), and exposes stale/unknown read provenance when offline without ever initiating
 * a backup local charge.
 */
#[Api(since: '1.0')]
final class FakeProductCoordinator
{
    /** @var array<string, CollectionHostSignalView> */
    private array $consumedSignals = [];

    /** @var array<string, string> caseRef -> host access posture (active|grace|read_only) */
    private array $accessPostureByCase = [];

    /** @var array<string, CollectionHostStatusSnapshot> */
    private array $cachedSnapshots = [];

    /** @var array<string, string> grantKey -> receiptRef */
    private array $activeGrants = [];

    /** @var list<ProvisioningAttemptReceipt> */
    private array $provisioningReceipts = [];

    /** @var array<string, int> */
    private array $provisioningAttemptsByGrant = [];

    private bool $billiumReachable = true;

    private bool $provisioningOffline = false;

    private ?string $failNextProvisionReason = null;

    private int $resourceDeletions = 0;

    private int $directGrantMutationsFromSignals = 0;

    private int $glWrites = 0;

    private int $backupChargesInitiated = 0;

    private int $refusedBackupCharges = 0;

    private int $extraChargesFromProvisionRetry = 0;

    public function setBilliumReachable(bool $reachable): void
    {
        $this->billiumReachable = $reachable;
    }

    public function setProvisioningOffline(bool $offline): void
    {
        $this->provisioningOffline = $offline;
    }

    public function failNextProvisioning(string $reason = 'host_provisioner_timeout'): void
    {
        $this->failNextProvisionReason = $reason;
    }

    /**
     * Consumes a signal-only fact from Billium Collection idempotently.
     * Updates host access posture as a local policy decision only; NEVER deletes host resources
     * or directly mutates grants/provisioning/GL.
     */
    public function consumeSignal(CollectionHostSignalView $signal): bool
    {
        if (! $signal->signalOnly) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'billium.collection.signal_must_be_signal_only',
            );
        }

        if (isset($this->consumedSignals[$signal->signalRef])) {
            return false;
        }

        $this->consumedSignals[$signal->signalRef] = $signal;

        $current = $this->accessPostureByCase[$signal->caseRef] ?? 'active';
        $this->accessPostureByCase[$signal->caseRef] = match ($signal->signalKind) {
            CollectionSignalKind::Grace => $current === 'read_only' ? 'read_only' : 'grace',
            CollectionSignalKind::ReadOnly => 'read_only',
            CollectionSignalKind::RenewalSucceeded => 'active',
            CollectionSignalKind::RenewalFailed,
            CollectionSignalKind::CustomerActionRequired,
            CollectionSignalKind::ManualIntervention => $current,
        };

        return true;
    }

    public function accessPosture(string $caseRef): string
    {
        return $this->accessPostureByCase[$caseRef] ?? 'active';
    }

    public function consumedSignalCount(): int
    {
        return count($this->consumedSignals);
    }

    /**
     * Reads host status from Billium when reachable, or returns an explicit stale/unknown
     * snapshot when offline. NEVER authorizes a backup local charge.
     */
    public function readCaseStatus(
        CollectionSignalService $signals,
        CollectionScope $scope,
        string $caseRef,
        DateTimeImmutable $now,
        ?int $maxStalenessSeconds = null,
    ): CollectionHostStatusSnapshot {
        $cacheKey = $scope->sellerRef.'|'.$scope->environment.'|'.$caseRef;

        if (! $this->billiumReachable) {
            $cached = $this->cachedSnapshots[$cacheKey] ?? null;
            if ($cached !== null) {
                return CollectionHostStatusSnapshot::stale($cached, $now, 'billium_unreachable');
            }

            return CollectionHostStatusSnapshot::unknown($scope, $caseRef, $now, 'billium_unreachable');
        }

        $snapshot = $signals->readHostStatus($scope, $caseRef, $now, $maxStalenessSeconds);
        if ($snapshot->freshness === HostSnapshotFreshness::Authoritative) {
            $this->cachedSnapshots[$cacheKey] = $snapshot;
        }

        return $snapshot;
    }

    /**
     * Host guard enforcing that offline/stale/unknown provenance NEVER initiates a backup local charge.
     */
    public function attemptOfflineBackupCharge(CollectionHostStatusSnapshot $snapshot): never
    {
        $this->refusedBackupCharges++;

        throw new ProblemException(
            ProblemCode::OperationFinancialHold,
            'billium.collection.offline_backup_charge_forbidden:'.$snapshot->freshness->value,
        );
    }

    /**
     * Provisions or retries provisioning for an already-settled payment.
     * On failure, records a failure receipt and instructs caller to retry provisioning ONLY —
     * never triggering a second payment charge.
     */
    public function provisionAfterSettledPayment(
        string $paymentRef,
        string $componentRef,
        string $grantKey,
        bool $paymentSettled,
        ?DateTimeImmutable $now = null,
    ): ProvisioningAttemptReceipt {
        if (! $paymentSettled) {
            throw new ProblemException(
                ProblemCode::OperationFinancialHold,
                'billium.collection.provisioning_requires_settled_payment',
            );
        }

        $utc = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));

        if (isset($this->activeGrants[$grantKey])) {
            return new ProvisioningAttemptReceipt(
                receiptRef: $this->activeGrants[$grantKey],
                paymentRef: $paymentRef,
                componentRef: $componentRef,
                grantKey: $grantKey,
                attemptNo: $this->provisioningAttemptsByGrant[$grantKey] ?? 1,
                succeeded: true,
                failureReason: null,
                retryProvisionOnly: false,
                chargeTriggered: false,
                recordedAt: $utc,
            );
        }

        $attempt = ($this->provisioningAttemptsByGrant[$grantKey] ?? 0) + 1;
        $this->provisioningAttemptsByGrant[$grantKey] = $attempt;

        $failureReason = null;
        if ($this->provisioningOffline) {
            $failureReason = 'host_provisioner_offline';
        } elseif ($this->failNextProvisionReason !== null) {
            $failureReason = $this->failNextProvisionReason;
            $this->failNextProvisionReason = null;
        }

        if ($failureReason !== null) {
            $receipt = new ProvisioningAttemptReceipt(
                receiptRef: sprintf('prov-fail:%s:%d', $grantKey, $attempt),
                paymentRef: $paymentRef,
                componentRef: $componentRef,
                grantKey: $grantKey,
                attemptNo: $attempt,
                succeeded: false,
                failureReason: $failureReason,
                retryProvisionOnly: true,
                chargeTriggered: false,
                recordedAt: $utc,
            );
            $this->provisioningReceipts[] = $receipt;

            return $receipt;
        }

        $receiptRef = sprintf('prov-grant:%s:%s', $componentRef, $grantKey);
        $this->activeGrants[$grantKey] = $receiptRef;
        $receipt = new ProvisioningAttemptReceipt(
            receiptRef: $receiptRef,
            paymentRef: $paymentRef,
            componentRef: $componentRef,
            grantKey: $grantKey,
            attemptNo: $attempt,
            succeeded: true,
            failureReason: null,
            retryProvisionOnly: false,
            chargeTriggered: false,
            recordedAt: $utc,
        );
        $this->provisioningReceipts[] = $receipt;

        return $receipt;
    }

    public function hasActiveGrant(string $grantKey): bool
    {
        return isset($this->activeGrants[$grantKey]);
    }

    public function provisioningAttemptCount(string $grantKey): int
    {
        return $this->provisioningAttemptsByGrant[$grantKey] ?? 0;
    }

    /** @return list<ProvisioningAttemptReceipt> */
    public function provisioningReceipts(): array
    {
        return $this->provisioningReceipts;
    }

    public function resourceDeletions(): int
    {
        return $this->resourceDeletions;
    }

    public function directGrantMutationsFromSignals(): int
    {
        return $this->directGrantMutationsFromSignals;
    }

    public function glWrites(): int
    {
        return $this->glWrites;
    }

    public function backupChargesInitiated(): int
    {
        return $this->backupChargesInitiated;
    }

    public function refusedBackupCharges(): int
    {
        return $this->refusedBackupCharges;
    }

    public function extraChargesFromProvisionRetry(): int
    {
        return $this->extraChargesFromProvisionRetry;
    }
}

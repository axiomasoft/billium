<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\CollectionStepStatus;
use Billium\Collection\Enums\DunningCaseStatus;
use Billium\Collection\Enums\HostSnapshotFreshness;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class CollectionHostStatusSnapshot
{
    /**
     * @param  list<CollectionHostSignalView>  $signals
     */
    public function __construct(
        public string $sellerRef,
        public string $environment,
        public string $caseRef,
        public ?string $billingAccountRef,
        public ?string $obligationRef,
        public ?string $componentRef,
        public HostSnapshotFreshness $freshness,
        public ?DunningCaseStatus $caseStatus,
        public ?int $caseRevision,
        public ?CollectionStepStatus $latestStepStatus,
        public bool $paymentSettled,
        public bool $graceActive,
        public bool $readOnlyActive,
        public bool $backupChargeAllowed,
        public array $signals,
        public DateTimeImmutable $observedAt,
        public ?DateTimeImmutable $authoritativeAsOf,
        public ?string $stalenessReason,
    ) {
        if ($backupChargeAllowed) {
            throw new ProblemException(
                ProblemCode::OperationFinancialHold,
                'billium.collection.offline_backup_charge_forbidden',
            );
        }
    }

    public static function stale(
        self $cached,
        DateTimeImmutable $observedAt,
        string $reason = 'host_offline_cached_snapshot',
    ): self {
        return new self(
            sellerRef: $cached->sellerRef,
            environment: $cached->environment,
            caseRef: $cached->caseRef,
            billingAccountRef: $cached->billingAccountRef,
            obligationRef: $cached->obligationRef,
            componentRef: $cached->componentRef,
            freshness: HostSnapshotFreshness::Stale,
            caseStatus: $cached->caseStatus,
            caseRevision: $cached->caseRevision,
            latestStepStatus: $cached->latestStepStatus,
            paymentSettled: $cached->paymentSettled,
            graceActive: $cached->graceActive,
            readOnlyActive: $cached->readOnlyActive,
            backupChargeAllowed: false,
            signals: $cached->signals,
            observedAt: $observedAt,
            authoritativeAsOf: $cached->authoritativeAsOf,
            stalenessReason: $reason,
        );
    }

    public static function unknown(
        CollectionScope $scope,
        string $caseRef,
        DateTimeImmutable $observedAt,
        string $reason = 'host_offline_no_authoritative_snapshot',
    ): self {
        return new self(
            sellerRef: $scope->sellerRef,
            environment: $scope->environment,
            caseRef: $caseRef,
            billingAccountRef: null,
            obligationRef: null,
            componentRef: null,
            freshness: HostSnapshotFreshness::Unknown,
            caseStatus: null,
            caseRevision: null,
            latestStepStatus: null,
            paymentSettled: false,
            graceActive: false,
            readOnlyActive: false,
            backupChargeAllowed: false,
            signals: [],
            observedAt: $observedAt,
            authoritativeAsOf: null,
            stalenessReason: $reason,
        );
    }
}

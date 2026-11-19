<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts\Fiscal;

use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\Fiscal\FiscalActualEffect;
use Billium\Execution\Data\Fiscal\FiscalCallbackEnvelope;
use Billium\Execution\Data\Fiscal\FiscalCorrectionRequest;
use Billium\Execution\Data\Fiscal\FiscalEvidenceRecord;
use Billium\Execution\Data\Fiscal\FiscalIntentRecord;
use Billium\Execution\Data\Fiscal\FiscalPolicyConfig;
use Billium\Execution\Data\Fiscal\FiscalPolicyRecord;
use Billium\Execution\Data\Fiscal\FiscalProjectionInput;
use Billium\Execution\Data\Fiscal\FiscalProjectionRecord;
use Billium\Execution\Enums\Fiscal\FiscalOperationKind;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Fiscal orchestration owned by the original payment executor.
 *
 * Execution-owning profiles create and drive fiscal intents; embedded-commerz records projection-only outcomes and never dispatches.
 */
#[Api(since: '1.0')]
interface FiscalOrchestrator
{
    public function configurePolicy(FiscalPolicyConfig $config): FiscalPolicyRecord;

    public function activePolicy(ExecutionScope $scope, string $executionProfile, FiscalOperationKind $operationKind): ?FiscalPolicyRecord;

    public function prepareIntent(FiscalActualEffect $effect): ?FiscalIntentRecord;

    /** @return list<FiscalIntentRecord> */
    public function syncActualEffectsForRoot(ExecutionScope $scope, string $rootOperationRef, string $executionProfile): array;

    public function dispatchIntent(ExecutionScope $scope, string $intentRef, ?DateTimeImmutable $now = null): FiscalIntentRecord;

    public function lookupIntent(ExecutionScope $scope, string $intentRef, ?DateTimeImmutable $now = null): FiscalIntentRecord;

    /**
     * Bounded seller-scoped keyset claim of due unknown lookups backed by billium_fiscal_intent_due_lookup (COV-337).
     *
     * @return list<FiscalIntentRecord>
     */
    public function claimDueLookups(
        ExecutionScope $scope,
        DateTimeImmutable $asOf,
        int $limit = 50,
        int $afterId = 0,
        string $workerRef = 'fiscal-worker',
        int $leaseSeconds = 60,
    ): array;

    public function createCorrection(FiscalCorrectionRequest $request): FiscalIntentRecord;

    public function ingestCallback(FiscalCallbackEnvelope $envelope): FiscalIntentRecord;

    public function recordProjection(FiscalProjectionInput $input): FiscalProjectionRecord;

    /** @return list<FiscalProjectionRecord> */
    public function syncActualProjectionsForRoot(
        ExecutionScope $scope,
        string $rootOperationRef,
        string $sourceAuthority = 'embedded-commerz',
    ): array;

    public function findIntent(ExecutionScope $scope, string $intentRef): ?FiscalIntentRecord;

    public function findIntentByExternalId(ExecutionScope $scope, string $externalId): ?FiscalIntentRecord;

    /** @return list<FiscalIntentRecord> */
    public function intentsForRoot(ExecutionScope $scope, string $rootOperationRef): array;

    /** @return list<FiscalEvidenceRecord> */
    public function evidenceForIntent(ExecutionScope $scope, string $intentRef): array;

    /** @return list<FiscalProjectionRecord> */
    public function projectionsForRoot(ExecutionScope $scope, string $rootOperationRef): array;
}

<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Import;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Platform\Contracts\Import\ImportIdentityStore;
use Billium\Platform\Contracts\Import\ImportMonetaryPort;
use Billium\Platform\Contracts\Inbox\CustodyHandler;
use Billium\Platform\Data\EventContracts\PreparedInternalCommand;
use Billium\Platform\Data\Import\ImportMappingRegistrationResult;
use Billium\Platform\Data\Import\ImportMappingStatus;
use Billium\Platform\Data\Import\ImportObservationOutcome;
use Billium\Platform\Data\Import\ImportReceiptStatus;
use Billium\Platform\Data\Import\ImportRecordApplyOutcome;
use Billium\Platform\Data\Import\ImportRecordApplyStatus;
use Billium\Platform\Data\Import\ImportRecordKind;
use Billium\Platform\Data\Import\ImportSettlementIntentWrite;
use Billium\Platform\Data\Inbox\CustodyDisposition;
use Billium\Platform\Data\Inbox\HandlerOutcome;
use Billium\Platform\Data\Inbox\InboxRow;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Platform\Internal\CommandOutbox\CommandPersistence;
use Billium\Platform\Internal\Feed\PartitionStore;
use Billium\Platform\Internal\Inbox\CustodyProcessor;
use Closure;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

/**
 * Coordinates inline settlement intents, two-root observation custody queueing, partition reduction, and pure-query receipt adoption (P9.3, D60, D71).
 */
#[Internal]
final readonly class ImportObservationCoordinator
{
    /**
     * @param  Closure(): OperationRunner  $runner
     * @param  Closure(): ImportMonetaryPort  $monetaryPort
     */
    public function __construct(
        private DatabaseManager $databases,
        private ImportIdentityStore $store,
        private PartitionStore $partitions,
        private CustodyProcessor $custody,
        private ImportObservationEnvelopeBuilder $envelopeBuilder,
        private ImportStagedRecordHydrator $hydrator,
        private Closure $runner,
        private Closure $monetaryPort,
        private Codec $codec,
    ) {}

    /**
     * Declares a settlement intent when a proven payment record explicitly carries both billingAccountRef and proven target obligation ref(s).
     * Never fabricates missing billingAccountRef or missing target obligations.
     *
     * @param  array<string, string>  $obligationRefBySourceId
     */
    public function ensureInlinePaymentSettlementIntent(
        object $paymentRow,
        Caller $caller,
        array $obligationRefBySourceId,
        int &$processedSteps,
    ): void {
        $record = $this->hydrator->hydrateInputFromStagedRow($paymentRow);
        if ($record->money === null) {
            return;
        }
        $origRefs = $record->originalBusinessRefs;
        $billingAccountRef = ImportStagedRecordHydrator::nonEmptyString($origRefs['billingAccountRef'] ?? null);
        if ($billingAccountRef === null) {
            return;
        }
        $targetRefs = [];
        foreach (['targetObligationRef', 'obligationRef'] as $key) {
            $candidate = ImportStagedRecordHydrator::nonEmptyString($origRefs[$key] ?? null);
            if ($candidate !== null) {
                $resolved = $obligationRefBySourceId[$candidate] ?? $candidate;
                if (($this->monetaryPort)()->inspectObligation($resolved)['exists'] === true) {
                    $targetRefs[] = $resolved;
                }
            }
        }
        if (is_array($record->payload['settlementTargetObligationRefs'] ?? null)) {
            foreach ($record->payload['settlementTargetObligationRefs'] as $rawTarget) {
                if (is_string($rawTarget) && trim($rawTarget) !== '') {
                    $resolved = $obligationRefBySourceId[trim($rawTarget)] ?? trim($rawTarget);
                    if (($this->monetaryPort)()->inspectObligation($resolved)['exists'] === true) {
                        $targetRefs[] = $resolved;
                    }
                }
            }
        }
        $targetRefs = array_values(array_unique($targetRefs));
        if ($targetRefs === []) {
            return;
        }

        $businessIntentRef = $this->envelopeBuilder->businessIntentRef($record);
        $existingIntent = $this->hydrator->connection()->selectOne(
            "SELECT 1 FROM public.billium_import_mapping WHERE installation_ref = ? AND seller_ref = ? AND environment = ? AND billium_target_kind = 'settlement_intent' AND billium_target_ref = ? LIMIT 1",
            [$caller->installationRef, $caller->sellerRef, $caller->environment, $businessIntentRef],
        ) !== null;
        if ($existingIntent) {
            return;
        }
        $factRef = $record->sourceFactRef ?? $record->identity->stableSourceFactRef($record->sourceRecordRevision, $this->codec);
        $intentKey = ImportStagedRecordHydrator::boundedRef('imp-pay-sint:'.$factRef, 'imp-psint');
        $digest = $this->codec->digest([
            'billingAccountRef' => $billingAccountRef,
            'businessIntentRef' => $businessIntentRef,
            'currency' => $record->money->currency->code,
            'obligationRefs' => $targetRefs,
        ]);

        // Skip step increment if idempotency receipt already exists.
        $alreadyClaimed = $this->hydrator->connection()->selectOne(
            'SELECT 1 FROM public.billium_idempotency_receipt WHERE environment = ? AND seller_ref = ? AND command_kind = ? AND command_key = ?',
            [$caller->environment, $caller->sellerRef, 'platform.import.inline_settlement_intent.v1', $intentKey],
        ) !== null;
        if ($alreadyClaimed) {
            return;
        }

        $processedSteps++;
        $spec = new OperationSpec(
            commandKind: 'platform.import.inline_settlement_intent.v1',
            sellerRef: $caller->sellerRef,
            environment: $caller->environment,
            mode: FinancialMode::Active,
            operationClass: ModuleOperationClass::NewIntent,
            modules: ['ledger', 'platform'],
            commandKey: $intentKey,
            digest: $digest,
            commitKind: 'platform.import.settlement_intent',
        );

        ($this->runner)()->run($spec, function (TransactionContext $context) use (
            $caller,
            $intentKey,
            $billingAccountRef,
            $businessIntentRef,
            $record,
            $targetRefs,
        ): Receipt {
            ($this->runner)()->assertCurrent($context, 'platform');
            $money = $record->money ?? throw new LogicException('Missing payment money');
            ($this->monetaryPort)()->declareSettlementIntent($context, new ImportSettlementIntentWrite(
                commandKey: $intentKey,
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                billingAccountRef: $billingAccountRef,
                businessIntentRef: $businessIntentRef,
                currency: $money->currency->code,
                obligationRefs: $targetRefs,
                requestedMinor: $money->minor,
            ));

            return new PlatformReceipt('platform.import.settlement_intent', [
                'business_intent_ref' => $businessIntentRef,
            ]);
        });
    }

    /**
     * @param  array<string, string>  $chargeOpRefByLookup
     * @param  array<int, true>  $touchedPartitionIds
     */
    public function queueObservationRow(
        int $batchPk,
        object $row,
        Caller $caller,
        string $migrationSourceAuthority,
        array $chargeOpRefByLookup,
        array &$touchedPartitionIds,
        int &$processedSteps,
        bool &$quarantined,
    ): ImportRecordApplyOutcome {
        $record = $this->hydrator->hydrateInputFromStagedRow($row);
        $recordPk = ImportStagedRecordHydrator::intField($row, 'id');
        $identity = $record->identity;
        $revision = $record->sourceRecordRevision;
        $factRef = $record->sourceFactRef ?? $identity->stableSourceFactRef($revision, $this->codec);

        $existingMapping = $this->store->findMapping($identity, $revision);
        if ($existingMapping !== null) {
            if ($existingMapping->payloadDigest !== $record->payloadDigest || $existingMapping->status === ImportMappingStatus::Tombstoned) {
                $quarantined = true;
                $this->hydrator->markStagedRecordConflict($recordPk);

                return $this->hydrator->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
            }
            $existingReceipt = $this->store->findAppliedReceipt($identity, $revision);
            if ($existingReceipt !== null) {
                if ($existingMapping->billiumTargetRef !== null) {
                    $this->hydrator->markStagedRecordAlreadyApplied($recordPk, $existingMapping->sourceFactRef);

                    return new ImportRecordApplyOutcome(
                        identity: $identity,
                        sourceRecordRevision: $revision,
                        sourceFactRef: $existingMapping->sourceFactRef,
                        status: ImportRecordApplyStatus::AlreadyApplied,
                        mappingRef: $existingMapping->mappingRef,
                        importReceiptRef: $existingReceipt->receiptRef,
                        billiumTargetKind: $existingMapping->billiumTargetKind,
                        billiumTargetRef: $existingMapping->billiumTargetRef,
                    );
                }

                return new ImportRecordApplyOutcome(
                    identity: $identity,
                    sourceRecordRevision: $revision,
                    sourceFactRef: $existingMapping->sourceFactRef,
                    status: ImportRecordApplyStatus::ObservationQueued,
                    mappingRef: $existingMapping->mappingRef,
                    importReceiptRef: $existingReceipt->receiptRef,
                );
            }
        }

        // For proven refunds: verify original charge currency/capacity when already projected (IM-10).
        $resolvedOriginalPaymentRef = null;
        if ($identity->recordKind === ImportRecordKind::ProvenRefundEvidence) {
            $rawOrigRef = ImportStagedRecordHydrator::nonEmptyString($record->fundingRefs['originalOperationRef'] ?? null);
            if ($rawOrigRef === null || $record->money === null) {
                throw new LogicException('Unanchored refund reached observation queue stage');
            }
            $resolvedOriginalPaymentRef = $chargeOpRefByLookup[$rawOrigRef] ?? $rawOrigRef;
            $chargeCheck = ($this->monetaryPort)()->inspectOriginalChargeForRefund(
                $migrationSourceAuthority,
                $resolvedOriginalPaymentRef,
                $record->money,
            );
            if ($chargeCheck['exists']) {
                if (($chargeCheck['currency'] ?? '') !== $record->money->currency->code) {
                    $quarantined = true;
                    $this->hydrator->markStagedRecordConflict($recordPk);

                    return $this->hydrator->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
                }
                $hasExplicitDiscrepancy = is_array($record->fundingRefs['discrepancy'] ?? null);
                if (($chargeCheck['allowed'] ?? false) !== true && ! $hasExplicitDiscrepancy) {
                    $quarantined = true;
                    $this->hydrator->markStagedRecordConflict($recordPk);

                    return $this->hydrator->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
                }
                $chargeAccount = is_string($chargeCheck['providerAccountRef'] ?? null) ? $chargeCheck['providerAccountRef'] : '';
                $refundAccount = ImportStagedRecordHydrator::nonEmptyString($record->fundingRefs['providerAccountRef'] ?? null) ?? '';
                if ($chargeAccount !== '' && $refundAccount !== '' && $chargeAccount !== $refundAccount) {
                    $quarantined = true;
                    $this->hydrator->markStagedRecordConflict($recordPk);

                    return $this->hydrator->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
                }
            }
        }

        $processedSteps++;
        $scope = new FinancialScope(
            $caller->installationRef,
            $caller->sellerRef,
            $caller->environment,
            null,
            $migrationSourceAuthority,
        );
        $spec = new OperationSpec(
            commandKind: 'platform.import.queue_observation.v1',
            sellerRef: $caller->sellerRef,
            environment: $caller->environment,
            mode: FinancialMode::Active,
            operationClass: ModuleOperationClass::ObservedFactRecording,
            modules: ['platform'],
            commandKey: ImportStagedRecordHydrator::boundedRef($factRef, 'imp-obs-key'),
            digest: $record->payloadDigest,
            commitKind: 'platform.import.queue_observation',
            financialScope: $scope,
            inputDigest: $record->payloadDigest,
        );

        $noopCustodyHandler = new class implements CustodyHandler
        {
            public function handle(TransactionContext $context, InboxRow $row, stdClass $payload): HandlerOutcome
            {
                throw new LogicException('Import root Tx must never invoke ObservationApplier/CustodyHandler inline');
            }
        };

        /** @var ImportRecordApplyOutcome|null $captured */
        $captured = null;
        /** @var int|null $queuedPartitionId */
        $queuedPartitionId = null;

        try {
            $storedOutcome = ($this->runner)()->run($spec, function (TransactionContext $context) use (
                $batchPk,
                $recordPk,
                $record,
                $identity,
                $revision,
                $factRef,
                $migrationSourceAuthority,
                $resolvedOriginalPaymentRef,
                $noopCustodyHandler,
                &$captured,
                &$queuedPartitionId,
            ): Receipt {
                ($this->runner)()->assertCurrent($context, 'platform');

                $reg = $this->store->registerMapping(
                    identity: $identity,
                    sourceRecordRevision: $revision,
                    payloadDigest: $record->payloadDigest,
                    sourceFactRef: $factRef,
                    importPolicyVersion: $record->importPolicyVersion,
                );
                if (in_array($reg->outcome, [ImportMappingRegistrationResult::CONFLICT, ImportMappingRegistrationResult::TOMBSTONED_CONFLICT], true)) {
                    throw new ProblemException(ProblemCode::IdempotencyConflict, 'Observation mapping conflict');
                }

                $db = $this->partitions->connection($context);
                $partitionRef = $this->envelopeBuilder->partitionRef(
                    $identity->sourceInstallation,
                    $identity->sourceBook,
                    $identity->sellerRef,
                    $identity->environment,
                );
                $sourceLineage = $this->envelopeBuilder->sourceLineage(
                    $identity->installationRef,
                    $identity->sellerRef,
                    $identity->environment,
                    $identity->sourceInstallation,
                    $identity->sourceBook,
                );
                $eventId = $this->envelopeBuilder->deterministicEventId($reg->mapping->sourceFactRef);

                $this->partitions->lockNamespace(
                    $db,
                    'payment.observation',
                    $migrationSourceAuthority,
                    $identity->sellerRef,
                    $identity->environment,
                    $partitionRef,
                );
                $livePartition = $this->partitions->live(
                    $db,
                    'payment.observation',
                    $migrationSourceAuthority,
                    $identity->sellerRef,
                    $identity->environment,
                    $partitionRef,
                );
                if ($livePartition !== null) {
                    $livePartition = $this->partitions->lock($context, $livePartition);
                }

                $existingInbox = $db->selectOne(
                    'SELECT source_sequence, payload FROM public.billium_inbox_message '
                    .'WHERE channel = ? AND source_authority = ? AND source_lineage = ? '
                    .'AND seller_ref = ? AND environment = ? AND external_event_id = ?',
                    [
                        'payment.observation',
                        $migrationSourceAuthority,
                        $sourceLineage,
                        $identity->sellerRef,
                        $identity->environment,
                        $eventId,
                    ],
                );
                if (is_object($existingInbox)) {
                    $envelopeObj = json_decode(ImportStagedRecordHydrator::strField($existingInbox, 'payload'), false, 512, JSON_THROW_ON_ERROR);
                    if (! $envelopeObj instanceof stdClass) {
                        throw new LogicException('Corrupted stored inbox payload');
                    }
                    $sequence = ImportStagedRecordHydrator::intField($existingInbox, 'source_sequence');
                } else {
                    $maxInboxSeq = ImportStagedRecordHydrator::scalarInt($db->scalar(
                        'SELECT COALESCE(MAX(source_sequence), 0) FROM public.billium_inbox_message '
                        .'WHERE channel = ? AND source_authority = ? AND source_lineage = ? '
                        .'AND seller_ref = ? AND environment = ? AND partition_ref = ?',
                        [
                            'payment.observation',
                            $migrationSourceAuthority,
                            $sourceLineage,
                            $identity->sellerRef,
                            $identity->environment,
                            $partitionRef,
                        ],
                    ));
                    $sequence = max($livePartition->custodySequence ?? 0, $maxInboxSeq) + 1;
                    $envelopeObj = $this->envelopeBuilder->build(
                        record: $record,
                        sourceAuthority: $migrationSourceAuthority,
                        sourceFactRef: $reg->mapping->sourceFactRef,
                        sourceSequence: $sequence,
                        resolvedOriginalPaymentRef: $resolvedOriginalPaymentRef,
                    );
                }

                $custodyResult = $this->custody->enqueueCustody(
                    $context,
                    'payment.observation',
                    $envelopeObj,
                    $noopCustodyHandler,
                );
                if ($custodyResult->disposition === CustodyDisposition::Conflict) {
                    throw new ProblemException(ProblemCode::ObservationConflict, 'Conflicting observation delivery in custody');
                }
                $queuedPartitionId = $custodyResult->drainPartitionId;

                $operationRef = $this->envelopeBuilder->operationRef($record);
                $commandPersistence = new CommandPersistence($this->databases);
                $commandPersistence->record($context, new PreparedInternalCommand(
                    commandRef: ImportStagedRecordHydrator::boundedRef('imp-obs-cmd:'.$reg->mapping->sourceFactRef, 'imp-ocmd'),
                    businessRef: $operationRef,
                    ownerModuleId: 'platform',
                    commandType: 'import.observation.drain',
                    commandSchema: 'billium.command.v1',
                    payload: [
                        'channel' => 'payment.observation',
                        'eventId' => $eventId,
                        'operationRef' => $operationRef,
                        'partitionRef' => $partitionRef,
                        'sourceAuthority' => $migrationSourceAuthority,
                        'sourceFactRef' => $reg->mapping->sourceFactRef,
                        'sourceLineage' => $sourceLineage,
                        'sourceSequence' => $sequence,
                    ],
                    destinations: ['ledger.inbox.payment_observation'],
                ));

                $importReceipt = $this->store->findAppliedReceipt($identity, $revision)
                    ?? $this->store->recordReceipt(
                        batchPk: $batchPk,
                        receiptRef: ImportStagedRecordHydrator::boundedRef('imp-rcpt:obs:'.$reg->mapping->sourceFactRef, 'imp-rcpt'),
                        identity: $identity,
                        sourceRecordRevision: $revision,
                        payloadDigest: $record->payloadDigest,
                        status: ImportReceiptStatus::ObservationQueued,
                        recordPk: $recordPk,
                        mappingPk: $reg->mapping->id,
                        sourceFactRef: $reg->mapping->sourceFactRef,
                        details: [
                            'channel' => 'payment.observation',
                            'eventId' => $eventId,
                            'inboxId' => $custodyResult->inboxId,
                            'operationKind' => $identity->recordKind === ImportRecordKind::ProvenRefundEvidence ? 'refund' : 'charge',
                            'operationRef' => $operationRef,
                            'operationRevision' => max(1, $revision),
                            'partitionRef' => $partitionRef,
                            'sourceAuthority' => $migrationSourceAuthority,
                            'sourceSequence' => $sequence,
                        ],
                    );

                $captured = new ImportRecordApplyOutcome(
                    identity: $identity,
                    sourceRecordRevision: $revision,
                    sourceFactRef: $reg->mapping->sourceFactRef,
                    status: ImportRecordApplyStatus::ObservationQueued,
                    mappingRef: $reg->mapping->mappingRef,
                    importReceiptRef: $importReceipt->receiptRef,
                );

                return new PlatformReceipt('platform.import.queue_observation', [
                    'event_id' => $eventId,
                    'inbox_id' => $custodyResult->inboxId,
                    'mapping_ref' => $reg->mapping->mappingRef,
                    'operation_ref' => $operationRef,
                    'partition_ref' => $partitionRef,
                    'status' => ImportReceiptStatus::ObservationQueued->value,
                ]);
            });
            if ($storedOutcome->problem !== null) {
                throw new ProblemException($storedOutcome->problem, $storedOutcome->detail);
            }
        } catch (ProblemException $problem) {
            if (in_array($problem->problem, [ProblemCode::IdempotencyConflict, ProblemCode::ObservationConflict], true)) {
                $quarantined = true;
                $this->hydrator->markStagedRecordConflict($recordPk);

                return $this->hydrator->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
            }
            throw $problem;
        }

        if ($queuedPartitionId !== null) {
            $touchedPartitionIds[$queuedPartitionId] = true;
        }
        if ($captured !== null) {
            return $captured;
        }

        $committedMapping = $this->store->findMapping($identity, $revision)
            ?? throw new LogicException('Replayed observation queue without committed mapping');
        $committedReceipt = $this->store->findAppliedReceipt($identity, $revision);
        if ($committedMapping->billiumTargetRef !== null) {
            $this->hydrator->markStagedRecordAlreadyApplied($recordPk, $committedMapping->sourceFactRef);

            return new ImportRecordApplyOutcome(
                identity: $identity,
                sourceRecordRevision: $revision,
                sourceFactRef: $committedMapping->sourceFactRef,
                status: ImportRecordApplyStatus::AlreadyApplied,
                mappingRef: $committedMapping->mappingRef,
                importReceiptRef: $committedReceipt?->receiptRef,
                billiumTargetKind: $committedMapping->billiumTargetKind,
                billiumTargetRef: $committedMapping->billiumTargetRef,
            );
        }

        return new ImportRecordApplyOutcome(
            identity: $identity,
            sourceRecordRevision: $revision,
            sourceFactRef: $committedMapping->sourceFactRef,
            status: ImportRecordApplyStatus::ObservationQueued,
            mappingRef: $committedMapping->mappingRef,
            importReceiptRef: $committedReceipt?->receiptRef,
        );
    }

    /**
     * @param  array<int, true>  $touchedPartitionIds
     */
    public function reducePartitions(Caller $caller, string $migrationSourceAuthority, array $touchedPartitionIds): void
    {
        $monetary = ($this->monetaryPort)();
        if ($touchedPartitionIds === []) {
            $pendingPartitions = $this->hydrator->connection()->select(
                "SELECT id FROM public.billium_feed_partition WHERE channel = 'payment.observation' "
                ."AND seller_ref = ? AND environment = ? AND state = 'active' "
                .'AND accounted_sequence < custody_sequence AND source_authority = ? ORDER BY id ASC',
                [$caller->sellerRef, $caller->environment, $migrationSourceAuthority],
            );
            foreach ($pendingPartitions as $pRow) {
                $touchedPartitionIds[ImportStagedRecordHydrator::intField($pRow, 'id')] = true;
            }
        }
        if ($touchedPartitionIds !== []) {
            foreach (array_keys($touchedPartitionIds) as $pid) {
                $monetary->reduceObservationPartition($pid);
            }
        } else {
            $monetary->reduceObservationPartition();
        }
    }

    /**
     * Pure-query receipt adoption: recovers or binds the observation owner's AllocationReceipt without creating a new economic entity.
     */
    public function adoptObservationRow(
        object $row,
        string $migrationSourceAuthority,
        int &$processedSteps,
        bool &$quarantined,
    ): ?ImportRecordApplyOutcome {
        $record = $this->hydrator->hydrateInputFromStagedRow($row);
        $recordPk = ImportStagedRecordHydrator::intField($row, 'id');
        $identity = $record->identity;
        $revision = $record->sourceRecordRevision;

        $mapping = $this->store->findMapping($identity, $revision);
        if ($mapping === null) {
            return null;
        }
        $queuedReceipt = $this->store->findAppliedReceipt($identity, $revision);
        if ($mapping->billiumTargetRef !== null) {
            $this->hydrator->markStagedRecordAlreadyApplied($recordPk, $mapping->sourceFactRef);

            return new ImportRecordApplyOutcome(
                identity: $identity,
                sourceRecordRevision: $revision,
                sourceFactRef: $mapping->sourceFactRef,
                status: ImportRecordApplyStatus::AlreadyApplied,
                mappingRef: $mapping->mappingRef,
                importReceiptRef: $queuedReceipt?->receiptRef,
                billiumTargetKind: $mapping->billiumTargetKind,
                billiumTargetRef: $mapping->billiumTargetRef,
            );
        }

        $operationKind = $identity->recordKind === ImportRecordKind::ProvenRefundEvidence ? 'refund' : 'charge';
        $operationRef = $this->envelopeBuilder->operationRef($record);
        $obsOutcome = ($this->monetaryPort)()->findObservationOutcome(
            $migrationSourceAuthority,
            $operationRef,
            $operationKind,
            max(1, $revision),
        );

        if ($obsOutcome->status === ImportObservationOutcome::STATUS_APPLIED && $obsOutcome->receiptRef !== null) {
            $processedSteps++;
            $targetKind = $obsOutcome->receiptKind ?? 'allocation_receipt';
            $bound = $this->store->assignMappingTarget($identity, $revision, $targetKind, $obsOutcome->receiptRef);
            $this->hydrator->markStagedRecordAlreadyApplied($recordPk, $bound->sourceFactRef);

            return new ImportRecordApplyOutcome(
                identity: $identity,
                sourceRecordRevision: $revision,
                sourceFactRef: $bound->sourceFactRef,
                status: ImportRecordApplyStatus::Applied,
                mappingRef: $bound->mappingRef,
                importReceiptRef: $queuedReceipt?->receiptRef,
                billiumTargetKind: $bound->billiumTargetKind,
                billiumTargetRef: $bound->billiumTargetRef,
            );
        }

        if ($obsOutcome->status === ImportObservationOutcome::STATUS_HELD_DISCREPANCY) {
            $quarantined = true;
            if ($obsOutcome->receiptRef !== null) {
                $this->store->assignMappingTarget($identity, $revision, $obsOutcome->receiptKind ?? 'held_discrepancy', $obsOutcome->receiptRef);
            }

            return new ImportRecordApplyOutcome(
                identity: $identity,
                sourceRecordRevision: $revision,
                sourceFactRef: $mapping->sourceFactRef,
                status: ImportRecordApplyStatus::Conflict,
                mappingRef: $mapping->mappingRef,
                importReceiptRef: $queuedReceipt?->receiptRef,
                billiumTargetKind: $obsOutcome->receiptKind,
                billiumTargetRef: $obsOutcome->receiptRef,
                detail: 'observation_held_discrepancy',
            );
        }

        if ($obsOutcome->status === ImportObservationOutcome::STATUS_PARKED) {
            return new ImportRecordApplyOutcome(
                identity: $identity,
                sourceRecordRevision: $revision,
                sourceFactRef: $mapping->sourceFactRef,
                status: ImportRecordApplyStatus::ObservationParked,
                mappingRef: $mapping->mappingRef,
                importReceiptRef: $queuedReceipt?->receiptRef,
                detail: 'parked:'.($obsOutcome->projectionStatus ?? 'dependency'),
            );
        }

        return null;
    }
}

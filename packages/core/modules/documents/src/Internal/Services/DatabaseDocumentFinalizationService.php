<?php

declare(strict_types=1);

namespace Billium\Documents\Internal\Services;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Documents\Contracts\DocumentFinalizationService;
use Billium\Documents\Contracts\DocumentProfileRegistry;
use Billium\Documents\Contracts\DocumentTaxSnapshotPort;
use Billium\Documents\Data\AttachSnapshotResult;
use Billium\Documents\Data\AttachTaxSnapshot;
use Billium\Documents\Data\CreateDocumentDraft;
use Billium\Documents\Data\DocumentFinalizationEventView;
use Billium\Documents\Data\DocumentFinalizationView;
use Billium\Documents\Data\DocumentLineBasis;
use Billium\Documents\Data\DocumentScope;
use Billium\Documents\Data\DocumentTaxLineSnapshot;
use Billium\Documents\Data\DocumentTaxSnapshot;
use Billium\Documents\Data\ReviseDocumentDraft;
use Billium\Documents\Data\TaxSnapshotRequest;
use Billium\Documents\Enums\DiscountTaxOrder;
use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\FinalizationState;
use Billium\Documents\Enums\TaxConvention;
use Billium\Documents\Internal\Canonical\CanonicalJson;
use Billium\Documents\Internal\DocumentReceipt;
use Billium\Documents\Internal\Math\Dec;
use Billium\Documents\Internal\Support\RowCast;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;
use Throwable;

#[Internal]
final readonly class DatabaseDocumentFinalizationService implements DocumentFinalizationService
{
    public function __construct(
        private DatabaseManager $databases,
        private OperationRunner $runner,
        private CallerContext $callers,
        private DocumentProfileRegistry $profiles,
        private DocumentTaxSnapshotPort $taxSnapshotPort,
        private BilliumOptions $options,
    ) {}

    public function createDraft(CreateDocumentDraft $command): DocumentFinalizationView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->finalizationRef, 'finalizationRef');
        RowCast::bounded($command->economicRef, 'economicRef');
        RowCast::bounded($command->basisDigest, 'basisDigest');
        if ($command->basisRevision < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'basisRevision must be positive');
        }

        $currency = self::validateCurrency($command->currency, $command->currencyExponent);
        self::validateRoundingMode($command->roundingMode);
        self::validateLines($command->lines);

        $profile = $this->profiles->find($command->scope, $command->profileRef, $command->profileVersion);
        if ($profile === null) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.documents.profile_not_found');
        }
        if (! in_array($command->documentType, $profile->supportedTypes, true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.document_type_unsupported');
        }

        $linesPayload = self::serializeLines($command->lines);
        $inputDigest = self::computeInputDigest(
            sellerRef: $caller->sellerRef,
            environment: $caller->environment,
            finalizationRef: $command->finalizationRef,
            documentType: $command->documentType->value,
            economicRef: $command->economicRef,
            basisRevision: $command->basisRevision,
            basisDigest: $command->basisDigest,
            profileId: $profile->id,
            profileVersion: $profile->version,
            currency: $currency->code,
            currencyExponent: $command->currencyExponent,
            convention: $command->convention->value,
            roundingMode: $command->roundingMode,
            discountTaxOrder: $command->discountTaxOrder->value,
            lines: $linesPayload,
            buyerSnapshot: $command->buyerSnapshot,
            termsSnapshot: $command->termsSnapshot,
        );

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.finalization.create_draft.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Administrative,
                operationClass: ModuleOperationClass::NewIntent,
                modules: ['platform', 'ledger', 'documents'],
            ),
            body: function (TransactionContext $context) use ($caller, $command, $profile, $currency, $linesPayload, $inputDigest): DocumentReceipt {
                $this->runner->assertCurrent($context, 'documents');
                $db = $this->databases->connection($context->connectionName());
                $now = self::nowUtc();

                $existing = $db->table('billium_document_finalization')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('finalization_ref', $command->finalizationRef)
                    ->where('finalization_revision', 1)
                    ->first();

                if ($existing !== null) {
                    if (RowCast::str($existing->input_digest, 'input_digest') !== $inputDigest) {
                        throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.finalization_conflict');
                    }

                    return new DocumentReceipt('documents.finalization.draft_created', [
                        'id' => RowCast::integer($existing->id, 'id'),
                    ]);
                }

                $id = $db->table('billium_document_finalization')->insertGetId([
                    'seller_ref' => $caller->sellerRef,
                    'environment' => $caller->environment,
                    'finalization_ref' => $command->finalizationRef,
                    'document_type' => $command->documentType->value,
                    'economic_ref' => $command->economicRef,
                    'basis_revision' => $command->basisRevision,
                    'basis_digest' => $command->basisDigest,
                    'profile_id' => $profile->id,
                    'profile_ref' => $profile->profileRef,
                    'profile_version' => $profile->version,
                    'finalization_revision' => 1,
                    'state' => FinalizationState::Draft->value,
                    'currency' => $currency->code,
                    'currency_exponent' => $command->currencyExponent,
                    'tax_convention' => $command->convention->value,
                    'rounding_mode' => $command->roundingMode,
                    'discount_tax_order' => $command->discountTaxOrder->value,
                    'input_digest' => $inputDigest,
                    'buyer_snapshot' => CanonicalJson::encodeObject($command->buyerSnapshot),
                    'terms_snapshot' => CanonicalJson::encodeObject($command->termsSnapshot),
                    'lines_snapshot' => CanonicalJson::encode($linesPayload),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $db->table('billium_document_finalization_event')->insert([
                    'seller_ref' => $caller->sellerRef,
                    'environment' => $caller->environment,
                    'finalization_id' => $id,
                    'finalization_revision' => 1,
                    'event_kind' => 'draft_created',
                    'input_digest' => $inputDigest,
                    'details' => CanonicalJson::encodeObject(['basisRevision' => $command->basisRevision]),
                    'occurred_at' => $now,
                ]);

                return new DocumentReceipt('documents.finalization.draft_created', ['id' => $id]);
            },
        );

        $id = RowCast::integer($outcome->body['id'] ?? null, 'id');

        return $this->requireById($id, $caller->sellerRef, $caller->environment);
    }

    public function startFinalization(DocumentScope $scope, string $finalizationRef): DocumentFinalizationView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($finalizationRef, 'finalizationRef');

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.finalization.start.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Administrative,
                operationClass: ModuleOperationClass::NewIntent,
                modules: ['platform', 'ledger', 'documents'],
            ),
            body: function (TransactionContext $context) use ($caller, $finalizationRef): DocumentReceipt {
                $this->runner->assertCurrent($context, 'documents');
                $db = $this->databases->connection($context->connectionName());
                $now = self::nowUtc();

                $row = $db->table('billium_document_finalization')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('finalization_ref', $finalizationRef)
                    ->where('state', '<>', FinalizationState::Superseded->value)
                    ->first();

                if ($row === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.documents.finalization_not_found');
                }

                $rowId = RowCast::integer($row->id, 'id');
                $state = RowCast::str($row->state, 'state');
                $rev = RowCast::integer($row->finalization_revision, 'finalization_revision');
                $inputDigest = RowCast::str($row->input_digest, 'input_digest');

                if ($state === FinalizationState::SnapshotReady->value) {
                    throw new ProblemException(ProblemCode::InvoiceFrozen, 'billium.documents.finalization_already_ready');
                }
                if ($state === FinalizationState::Finalizing->value) {
                    return new DocumentReceipt('documents.finalization.started', ['id' => $rowId]);
                }

                $affected = $db->table('billium_document_finalization')
                    ->where('id', $rowId)
                    ->where('state', FinalizationState::Draft->value)
                    ->where('finalization_revision', $rev)
                    ->where('input_digest', $inputDigest)
                    ->update([
                        'state' => FinalizationState::Finalizing->value,
                        'updated_at' => $now,
                    ]);

                if ($affected === 0) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.finalization_state_conflict');
                }

                $db->table('billium_document_finalization_event')->insert([
                    'seller_ref' => $caller->sellerRef,
                    'environment' => $caller->environment,
                    'finalization_id' => $rowId,
                    'finalization_revision' => $rev,
                    'event_kind' => 'finalization_started',
                    'input_digest' => $inputDigest,
                    'details' => '{}',
                    'occurred_at' => $now,
                ]);

                return new DocumentReceipt('documents.finalization.started', ['id' => $rowId]);
            },
        );

        $id = RowCast::integer($outcome->body['id'] ?? null, 'id');

        return $this->requireById($id, $caller->sellerRef, $caller->environment);
    }

    public function reviseDraft(ReviseDocumentDraft $command): DocumentFinalizationView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->finalizationRef, 'finalizationRef');
        RowCast::bounded($command->basisDigest, 'basisDigest');
        if ($command->basisRevision < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'basisRevision must be positive');
        }

        $currency = self::validateCurrency($command->currency, $command->currencyExponent);
        self::validateRoundingMode($command->roundingMode);
        self::validateLines($command->lines);
        $linesPayload = self::serializeLines($command->lines);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.finalization.revise.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Administrative,
                operationClass: ModuleOperationClass::NewIntent,
                modules: ['platform', 'ledger', 'documents'],
            ),
            body: function (TransactionContext $context) use ($caller, $command, $currency, $linesPayload): DocumentReceipt {
                $this->runner->assertCurrent($context, 'documents');
                $db = $this->databases->connection($context->connectionName());
                $now = self::nowUtc();

                $current = $db->table('billium_document_finalization')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('finalization_ref', $command->finalizationRef)
                    ->where('state', '<>', FinalizationState::Superseded->value)
                    ->first();

                if ($current === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.documents.finalization_not_found');
                }

                $currentState = RowCast::str($current->state, 'state');
                if ($currentState === FinalizationState::SnapshotReady->value) {
                    throw new ProblemException(ProblemCode::InvoiceFrozen, 'billium.documents.finalization_frozen');
                }

                $currentId = RowCast::integer($current->id, 'id');
                $currentRev = RowCast::integer($current->finalization_revision, 'finalization_revision');
                $currentDigest = RowCast::str($current->input_digest, 'input_digest');
                $profileId = RowCast::integer($current->profile_id, 'profile_id');
                $profileVersion = RowCast::integer($current->profile_version, 'profile_version');
                $profileRef = RowCast::str($current->profile_ref, 'profile_ref');
                $docType = RowCast::str($current->document_type, 'document_type');
                $economicRef = RowCast::str($current->economic_ref, 'economic_ref');

                $newDigest = self::computeInputDigest(
                    sellerRef: $caller->sellerRef,
                    environment: $caller->environment,
                    finalizationRef: $command->finalizationRef,
                    documentType: $docType,
                    economicRef: $economicRef,
                    basisRevision: $command->basisRevision,
                    basisDigest: $command->basisDigest,
                    profileId: $profileId,
                    profileVersion: $profileVersion,
                    currency: $currency->code,
                    currencyExponent: $command->currencyExponent,
                    convention: $command->convention->value,
                    roundingMode: $command->roundingMode,
                    discountTaxOrder: $command->discountTaxOrder->value,
                    lines: $linesPayload,
                    buyerSnapshot: $command->buyerSnapshot,
                    termsSnapshot: $command->termsSnapshot,
                );

                $affected = $db->table('billium_document_finalization')
                    ->where('id', $currentId)
                    ->whereIn('state', [FinalizationState::Draft->value, FinalizationState::Finalizing->value])
                    ->where('finalization_revision', $currentRev)
                    ->where('input_digest', $currentDigest)
                    ->update([
                        'state' => FinalizationState::Superseded->value,
                        'updated_at' => $now,
                    ]);

                if ($affected === 0) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.finalization_revise_conflict');
                }

                $nextRev = $currentRev + 1;
                $newId = $db->table('billium_document_finalization')->insertGetId([
                    'seller_ref' => $caller->sellerRef,
                    'environment' => $caller->environment,
                    'finalization_ref' => $command->finalizationRef,
                    'document_type' => $docType,
                    'economic_ref' => $economicRef,
                    'basis_revision' => $command->basisRevision,
                    'basis_digest' => $command->basisDigest,
                    'profile_id' => $profileId,
                    'profile_ref' => $profileRef,
                    'profile_version' => $profileVersion,
                    'finalization_revision' => $nextRev,
                    'state' => FinalizationState::Draft->value,
                    'currency' => $currency->code,
                    'currency_exponent' => $command->currencyExponent,
                    'tax_convention' => $command->convention->value,
                    'rounding_mode' => $command->roundingMode,
                    'discount_tax_order' => $command->discountTaxOrder->value,
                    'input_digest' => $newDigest,
                    'buyer_snapshot' => CanonicalJson::encodeObject($command->buyerSnapshot),
                    'terms_snapshot' => CanonicalJson::encodeObject($command->termsSnapshot),
                    'lines_snapshot' => CanonicalJson::encode($linesPayload),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $db->table('billium_document_finalization_event')->insert([
                    [
                        'seller_ref' => $caller->sellerRef,
                        'environment' => $caller->environment,
                        'finalization_id' => $currentId,
                        'finalization_revision' => $currentRev,
                        'event_kind' => 'revision_superseded',
                        'input_digest' => $currentDigest,
                        'details' => CanonicalJson::encodeObject(['supersededByRevision' => $nextRev]),
                        'occurred_at' => $now,
                    ],
                    [
                        'seller_ref' => $caller->sellerRef,
                        'environment' => $caller->environment,
                        'finalization_id' => $newId,
                        'finalization_revision' => $nextRev,
                        'event_kind' => 'draft_created',
                        'input_digest' => $newDigest,
                        'details' => CanonicalJson::encodeObject(['basisRevision' => $command->basisRevision]),
                        'occurred_at' => $now,
                    ],
                ]);

                return new DocumentReceipt('documents.finalization.revised', ['id' => $newId]);
            },
        );

        $id = RowCast::integer($outcome->body['id'] ?? null, 'id');

        return $this->requireById($id, $caller->sellerRef, $caller->environment);
    }

    public function finalizeWithTaxPort(DocumentScope $scope, string $finalizationRef): AttachSnapshotResult
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($finalizationRef, 'finalizationRef');

        if ($this->databases->connection($this->options->connection)->transactionLevel() !== 0) {
            throw new LogicException('finalizeWithTaxPort must not be called inside an open database transaction');
        }

        // Tx 1: CAS draft -> finalizing (or idempotent reuse of finalizing revision).
        $finalizing = $this->startFinalization($scope, $finalizationRef);

        $row = $this->databases->connection($this->options->connection)->table('billium_document_finalization')
            ->where('id', $finalizing->id)
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->first();

        if ($row === null) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.documents.finalization_not_found');
        }

        $profile = $this->profiles->find($scope, RowCast::str($row->profile_ref, 'profile_ref'), $finalizing->profileVersion);
        if ($profile === null) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.documents.profile_not_found');
        }

        $request = new TaxSnapshotRequest(
            sellerRef: $finalizing->sellerRef,
            environment: $finalizing->environment,
            finalizationRef: $finalizing->finalizationRef,
            finalizationRevision: $finalizing->finalizationRevision,
            documentType: $finalizing->documentType,
            economicRef: $finalizing->economicRef,
            basisRevision: $finalizing->basisRevision,
            basisDigest: $finalizing->basisDigest,
            inputDigest: $finalizing->inputDigest,
            taxSnapshotSchema: $profile->taxSnapshotSchema,
            jurisdictionRef: $profile->jurisdictionRef,
            currency: $finalizing->currency,
            currencyExponent: $finalizing->currencyExponent,
            convention: TaxConvention::from(RowCast::str($row->tax_convention, 'tax_convention')),
            roundingMode: RowCast::str($row->rounding_mode, 'rounding_mode'),
            discountTaxOrder: DiscountTaxOrder::from(RowCast::str($row->discount_tax_order, 'discount_tax_order')),
            lines: $finalizing->lines,
            buyerSnapshot: $finalizing->buyerSnapshot,
            sellerSnapshot: $profile->sellerSnapshot,
        );

        // External port call strictly outside any DB transaction (INV-06).
        try {
            $snapshot = $this->taxSnapshotPort->resolve($request);
        } catch (Throwable $error) {
            $this->recordEvent(
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                finalizationId: $finalizing->id,
                revision: $finalizing->finalizationRevision,
                eventKind: 'tax_port_failed',
                inputDigest: $finalizing->inputDigest,
                details: ['exceptionClass' => $error::class],
            );
            if ($error instanceof ProblemException) {
                throw $error;
            }

            throw new ProblemException(ProblemCode::TransportUnavailable, 'billium.documents.tax_port_failed');
        }

        // Tx 2: CAS finalizing -> snapshot_ready guarded on finalization_revision + input_digest.
        return $this->attachTaxSnapshot(new AttachTaxSnapshot(
            scope: $scope,
            finalizationRef: $finalizationRef,
            finalizationRevision: $finalizing->finalizationRevision,
            inputDigest: $finalizing->inputDigest,
            snapshot: $snapshot,
        ));
    }

    public function attachTaxSnapshot(AttachTaxSnapshot $command): AttachSnapshotResult
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->finalizationRef, 'finalizationRef');
        RowCast::bounded($command->inputDigest, 'inputDigest');

        $taxSnapshotPayload = self::serializeSnapshot($command->snapshot);
        $taxSnapshotDigest = CanonicalJson::digest($taxSnapshotPayload);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.finalization.attach_snapshot.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Administrative,
                operationClass: ModuleOperationClass::NewIntent,
                modules: ['platform', 'ledger', 'documents'],
            ),
            body: function (TransactionContext $context) use ($caller, $command, $taxSnapshotPayload, $taxSnapshotDigest): DocumentReceipt {
                $this->runner->assertCurrent($context, 'documents');
                $db = $this->databases->connection($context->connectionName());
                $now = self::nowUtc();

                $target = $db->table('billium_document_finalization')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('finalization_ref', $command->finalizationRef)
                    ->where('finalization_revision', $command->finalizationRevision)
                    ->first();

                if ($target === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.documents.finalization_not_found');
                }

                $targetId = RowCast::integer($target->id, 'id');
                $targetState = RowCast::str($target->state, 'state');
                $targetInputDigest = RowCast::str($target->input_digest, 'input_digest');

                // Idempotent retry of identical snapshot on an already snapshot_ready revision.
                if (
                    $targetState === FinalizationState::SnapshotReady->value
                    && $targetInputDigest === $command->inputDigest
                    && $command->snapshot->inputDigest === $command->inputDigest
                    && RowCast::optStr($target->tax_snapshot_digest, 'tax_snapshot_digest') === $taxSnapshotDigest
                ) {
                    return new DocumentReceipt('documents.finalization.snapshot_idempotent', [
                        'attached' => false,
                        'staleIgnored' => false,
                        'viewId' => $targetId,
                    ]);
                }

                // Detect superseded / stale / digest-mismatched late result before snapshot conflict validation.
                $latest = $db->table('billium_document_finalization')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('finalization_ref', $command->finalizationRef)
                    ->orderByDesc('finalization_revision')
                    ->first();

                $latestId = $latest !== null ? RowCast::integer($latest->id, 'id') : $targetId;
                $latestRev = $latest !== null ? RowCast::integer($latest->finalization_revision, 'finalization_revision') : $command->finalizationRevision;
                $latestInputDigest = $latest !== null ? RowCast::str($latest->input_digest, 'input_digest') : $targetInputDigest;

                $isStale = $targetState === FinalizationState::Superseded->value
                    || $latestRev !== $command->finalizationRevision
                    || $targetInputDigest !== $command->inputDigest
                    || $command->snapshot->inputDigest !== $command->inputDigest;

                if ($isStale) {
                    $db->table('billium_document_finalization_event')->insert([
                        'seller_ref' => $caller->sellerRef,
                        'environment' => $caller->environment,
                        'finalization_id' => $latestId,
                        'finalization_revision' => $command->finalizationRevision,
                        'event_kind' => 'stale_snapshot_ignored',
                        'input_digest' => $command->inputDigest,
                        'details' => CanonicalJson::encode([
                            'expectedInputDigest' => $latestInputDigest,
                            'latestRevision' => $latestRev,
                            'taxSnapshotRef' => $command->snapshot->taxSnapshotRef,
                        ]),
                        'occurred_at' => $now,
                    ]);

                    return new DocumentReceipt('documents.finalization.snapshot_stale', [
                        'attached' => false,
                        'staleIgnored' => true,
                        'viewId' => $latestId,
                    ]);
                }

                if ($targetState !== FinalizationState::Finalizing->value) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.finalization_not_finalizing');
                }

                self::validateSnapshotAgainstDraft($target, $command->snapshot);

                $approvedSnapshotDigest = CanonicalJson::digest([
                    'finalizationRef' => $command->finalizationRef,
                    'finalizationRevision' => $command->finalizationRevision,
                    'basisRevision' => RowCast::integer($target->basis_revision, 'basis_revision'),
                    'basisDigest' => RowCast::str($target->basis_digest, 'basis_digest'),
                    'inputDigest' => $targetInputDigest,
                    'taxSnapshotDigest' => $taxSnapshotDigest,
                    'profileId' => RowCast::integer($target->profile_id, 'profile_id'),
                    'profileVersion' => RowCast::integer($target->profile_version, 'profile_version'),
                    'currency' => $command->snapshot->currency,
                    'subtotalMinor' => $command->snapshot->subtotalMinor,
                    'taxMinor' => $command->snapshot->taxMinor,
                    'totalMinor' => $command->snapshot->totalMinor,
                ]);

                $affected = $db->table('billium_document_finalization')
                    ->where('id', $targetId)
                    ->where('state', FinalizationState::Finalizing->value)
                    ->where('finalization_revision', $command->finalizationRevision)
                    ->where('input_digest', $command->inputDigest)
                    ->update([
                        'state' => FinalizationState::SnapshotReady->value,
                        'tax_snapshot_ref' => $command->snapshot->taxSnapshotRef,
                        'tax_snapshot_digest' => $taxSnapshotDigest,
                        'subtotal_minor' => $command->snapshot->subtotalMinor,
                        'tax_minor' => $command->snapshot->taxMinor,
                        'total_minor' => $command->snapshot->totalMinor,
                        'approved_snapshot_digest' => $approvedSnapshotDigest,
                        'tax_snapshot_payload' => CanonicalJson::encodeObject($taxSnapshotPayload),
                        'updated_at' => $now,
                    ]);

                if ($affected === 0) {
                    $db->table('billium_document_finalization_event')->insert([
                        'seller_ref' => $caller->sellerRef,
                        'environment' => $caller->environment,
                        'finalization_id' => $latestId,
                        'finalization_revision' => $command->finalizationRevision,
                        'event_kind' => 'stale_snapshot_ignored',
                        'input_digest' => $command->inputDigest,
                        'details' => CanonicalJson::encode(['reason' => 'cas_lost']),
                        'occurred_at' => $now,
                    ]);

                    return new DocumentReceipt('documents.finalization.snapshot_cas_lost', [
                        'attached' => false,
                        'staleIgnored' => true,
                        'viewId' => $latestId,
                    ]);
                }

                $db->table('billium_document_finalization_event')->insert([
                    'seller_ref' => $caller->sellerRef,
                    'environment' => $caller->environment,
                    'finalization_id' => $targetId,
                    'finalization_revision' => $command->finalizationRevision,
                    'event_kind' => 'snapshot_attached',
                    'input_digest' => $command->inputDigest,
                    'details' => CanonicalJson::encode([
                        'taxSnapshotRef' => $command->snapshot->taxSnapshotRef,
                        'approvedSnapshotDigest' => $approvedSnapshotDigest,
                    ]),
                    'occurred_at' => $now,
                ]);

                return new DocumentReceipt('documents.finalization.snapshot_attached', [
                    'attached' => true,
                    'staleIgnored' => false,
                    'viewId' => $targetId,
                ]);
            },
        );

        $attached = RowCast::boolean($outcome->body['attached'] ?? null, 'attached');
        $staleIgnored = RowCast::boolean($outcome->body['staleIgnored'] ?? null, 'staleIgnored');
        $viewId = RowCast::integer($outcome->body['viewId'] ?? null, 'viewId');

        return new AttachSnapshotResult(
            attached: $attached,
            staleIgnored: $staleIgnored,
            finalization: $this->requireById($viewId, $caller->sellerRef, $caller->environment),
        );
    }

    public function find(DocumentScope $scope, string $finalizationRef, ?int $finalizationRevision = null): ?DocumentFinalizationView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        $query = $this->databases->connection($this->options->connection)->table('billium_document_finalization')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('finalization_ref', $finalizationRef);

        if ($finalizationRevision !== null) {
            $query->where('finalization_revision', $finalizationRevision);
        } else {
            $query->orderByDesc('finalization_revision');
        }

        $row = $query->first();

        return $row === null ? null : self::hydrate($row);
    }

    /** @return list<DocumentFinalizationEventView> */
    public function events(DocumentScope $scope, string $finalizationRef): array
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        $db = $this->databases->connection($this->options->connection);

        $rows = $db->table('billium_document_finalization_event as e')
            ->join('billium_document_finalization as f', 'f.id', '=', 'e.finalization_id')
            ->where('e.seller_ref', $caller->sellerRef)
            ->where('e.environment', $caller->environment)
            ->where('f.finalization_ref', $finalizationRef)
            ->orderBy('e.id')
            ->select('e.*')
            ->get();

        $events = [];
        foreach ($rows as $row) {
            $events[] = new DocumentFinalizationEventView(
                id: RowCast::integer($row->id, 'id'),
                finalizationId: RowCast::integer($row->finalization_id, 'finalization_id'),
                finalizationRevision: RowCast::integer($row->finalization_revision, 'finalization_revision'),
                eventKind: RowCast::str($row->event_kind, 'event_kind'),
                inputDigest: RowCast::str($row->input_digest, 'input_digest'),
                details: RowCast::jsonObject($row->details, 'details'),
                occurredAt: RowCast::timestamp($row->occurred_at, 'occurred_at'),
            );
        }

        return $events;
    }

    public static function hydrate(stdClass $row): DocumentFinalizationView
    {
        $rawLines = RowCast::jsonList($row->lines_snapshot, 'lines_snapshot');
        $lines = [];
        foreach ($rawLines as $raw) {
            if (! is_array($raw)) {
                throw new LogicException('Expected line basis object');
            }
            $lines[] = new DocumentLineBasis(
                lineRef: RowCast::str($raw['lineRef'] ?? null, 'lineRef'),
                obligationRef: RowCast::str($raw['obligationRef'] ?? null, 'obligationRef'),
                componentRef: RowCast::str($raw['componentRef'] ?? null, 'componentRef'),
                quantity: RowCast::str($raw['quantity'] ?? null, 'quantity'),
                unitPriceDecimal: RowCast::str($raw['unitPriceDecimal'] ?? null, 'unitPriceDecimal'),
                netMinor: RowCast::integer($raw['netMinor'] ?? null, 'netMinor'),
                discountMinor: RowCast::integer($raw['discountMinor'] ?? null, 'discountMinor'),
                taxCode: RowCast::str($raw['taxCode'] ?? null, 'taxCode'),
                taxRate: RowCast::str($raw['taxRate'] ?? null, 'taxRate'),
                description: RowCast::str($raw['description'] ?? '', 'description'),
            );
        }

        $taxSnapshot = null;
        if ($row->tax_snapshot_payload !== null) {
            $taxSnapshot = self::hydrateSnapshot(RowCast::jsonObject($row->tax_snapshot_payload, 'tax_snapshot_payload'));
        }

        return new DocumentFinalizationView(
            id: RowCast::integer($row->id, 'id'),
            sellerRef: RowCast::str($row->seller_ref, 'seller_ref'),
            environment: RowCast::str($row->environment, 'environment'),
            finalizationRef: RowCast::str($row->finalization_ref, 'finalization_ref'),
            documentType: DocumentType::from(RowCast::str($row->document_type, 'document_type')),
            economicRef: RowCast::str($row->economic_ref, 'economic_ref'),
            basisRevision: RowCast::integer($row->basis_revision, 'basis_revision'),
            basisDigest: RowCast::str($row->basis_digest, 'basis_digest'),
            profileId: RowCast::integer($row->profile_id, 'profile_id'),
            profileVersion: RowCast::integer($row->profile_version, 'profile_version'),
            finalizationRevision: RowCast::integer($row->finalization_revision, 'finalization_revision'),
            state: FinalizationState::from(RowCast::str($row->state, 'state')),
            currency: RowCast::str($row->currency, 'currency'),
            currencyExponent: RowCast::integer($row->currency_exponent, 'currency_exponent'),
            inputDigest: RowCast::str($row->input_digest, 'input_digest'),
            taxSnapshotRef: RowCast::optStr($row->tax_snapshot_ref, 'tax_snapshot_ref'),
            taxSnapshotDigest: RowCast::optStr($row->tax_snapshot_digest, 'tax_snapshot_digest'),
            subtotalMinor: RowCast::optInt($row->subtotal_minor, 'subtotal_minor'),
            taxMinor: RowCast::optInt($row->tax_minor, 'tax_minor'),
            totalMinor: RowCast::optInt($row->total_minor, 'total_minor'),
            approvedSnapshotDigest: RowCast::optStr($row->approved_snapshot_digest, 'approved_snapshot_digest'),
            taxSnapshot: $taxSnapshot,
            buyerSnapshot: RowCast::jsonObject($row->buyer_snapshot, 'buyer_snapshot'),
            termsSnapshot: RowCast::jsonObject($row->terms_snapshot, 'terms_snapshot'),
            lines: $lines,
        );
    }

    /** @return array<string, mixed> */
    public static function serializeSnapshot(DocumentTaxSnapshot $snapshot): array
    {
        $lines = [];
        foreach ($snapshot->lines as $line) {
            $lines[] = [
                'lineRef' => $line->lineRef,
                'taxCode' => $line->taxCode,
                'rateDecimal' => $line->rateDecimal,
                'netMinor' => $line->netMinor,
                'taxMinor' => $line->taxMinor,
                'grossMinor' => $line->grossMinor,
            ];
        }

        return [
            'taxSnapshotRef' => $snapshot->taxSnapshotRef,
            'taxPolicyVersion' => $snapshot->taxPolicyVersion,
            'taxPoint' => $snapshot->taxPoint,
            'convention' => $snapshot->convention->value,
            'currency' => $snapshot->currency,
            'currencyExponent' => $snapshot->currencyExponent,
            'subtotalMinor' => $snapshot->subtotalMinor,
            'taxMinor' => $snapshot->taxMinor,
            'totalMinor' => $snapshot->totalMinor,
            'lines' => $lines,
            'inputDigest' => $snapshot->inputDigest,
            'evidenceDigest' => $snapshot->evidenceDigest,
            'resolver' => $snapshot->resolver,
            'resolverVersion' => $snapshot->resolverVersion,
            'noTaxReason' => $snapshot->noTaxReason,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function hydrateSnapshot(array $payload): DocumentTaxSnapshot
    {
        $rawLines = $payload['lines'] ?? null;
        if (! is_array($rawLines)) {
            throw new LogicException('Expected snapshot lines array');
        }
        $lines = [];
        foreach ($rawLines as $raw) {
            if (! is_array($raw)) {
                throw new LogicException('Expected snapshot line object');
            }
            $lines[] = new DocumentTaxLineSnapshot(
                lineRef: RowCast::str($raw['lineRef'] ?? null, 'lineRef'),
                taxCode: RowCast::str($raw['taxCode'] ?? null, 'taxCode'),
                rateDecimal: RowCast::str($raw['rateDecimal'] ?? null, 'rateDecimal'),
                netMinor: RowCast::integer($raw['netMinor'] ?? null, 'netMinor'),
                taxMinor: RowCast::integer($raw['taxMinor'] ?? null, 'taxMinor'),
                grossMinor: RowCast::integer($raw['grossMinor'] ?? null, 'grossMinor'),
            );
        }

        return new DocumentTaxSnapshot(
            taxSnapshotRef: RowCast::str($payload['taxSnapshotRef'] ?? null, 'taxSnapshotRef'),
            taxPolicyVersion: RowCast::str($payload['taxPolicyVersion'] ?? null, 'taxPolicyVersion'),
            taxPoint: RowCast::str($payload['taxPoint'] ?? null, 'taxPoint'),
            convention: TaxConvention::from(RowCast::str($payload['convention'] ?? null, 'convention')),
            currency: RowCast::str($payload['currency'] ?? null, 'currency'),
            currencyExponent: RowCast::integer($payload['currencyExponent'] ?? null, 'currencyExponent'),
            subtotalMinor: RowCast::integer($payload['subtotalMinor'] ?? null, 'subtotalMinor'),
            taxMinor: RowCast::integer($payload['taxMinor'] ?? null, 'taxMinor'),
            totalMinor: RowCast::integer($payload['totalMinor'] ?? null, 'totalMinor'),
            lines: $lines,
            inputDigest: RowCast::str($payload['inputDigest'] ?? null, 'inputDigest'),
            evidenceDigest: RowCast::str($payload['evidenceDigest'] ?? null, 'evidenceDigest'),
            resolver: RowCast::str($payload['resolver'] ?? null, 'resolver'),
            resolverVersion: RowCast::str($payload['resolverVersion'] ?? null, 'resolverVersion'),
            noTaxReason: RowCast::optStr($payload['noTaxReason'] ?? null, 'noTaxReason'),
        );
    }

    private function requireById(int $id, string $sellerRef, string $environment): DocumentFinalizationView
    {
        $row = $this->databases->connection($this->options->connection)->table('billium_document_finalization')
            ->where('id', $id)
            ->where('seller_ref', $sellerRef)
            ->where('environment', $environment)
            ->first();

        if ($row === null) {
            throw new LogicException('Committed finalization row not found');
        }

        return self::hydrate($row);
    }

    /** @param array<string, mixed> $details */
    private function recordEvent(
        string $sellerRef,
        string $environment,
        int $finalizationId,
        int $revision,
        string $eventKind,
        string $inputDigest,
        array $details,
    ): void {
        $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.finalization.record_event.v1',
                sellerRef: $sellerRef,
                environment: $environment,
                mode: FinancialMode::Administrative,
                operationClass: ModuleOperationClass::NewIntent,
                modules: ['platform', 'ledger', 'documents'],
            ),
            body: function (TransactionContext $context) use ($sellerRef, $environment, $finalizationId, $revision, $eventKind, $inputDigest, $details): DocumentReceipt {
                $this->runner->assertCurrent($context, 'documents');
                $this->databases->connection($context->connectionName())
                    ->table('billium_document_finalization_event')
                    ->insert([
                        'seller_ref' => $sellerRef,
                        'environment' => $environment,
                        'finalization_id' => $finalizationId,
                        'finalization_revision' => $revision,
                        'event_kind' => $eventKind,
                        'input_digest' => $inputDigest,
                        'details' => CanonicalJson::encode($details),
                        'occurred_at' => self::nowUtc(),
                    ]);

                return new DocumentReceipt('documents.finalization.event_recorded', ['eventKind' => $eventKind]);
            },
        );
    }

    private static function validateSnapshotAgainstDraft(stdClass $target, DocumentTaxSnapshot $snapshot): void
    {
        $draftCurrency = RowCast::str($target->currency, 'currency');
        $draftExponent = RowCast::integer($target->currency_exponent, 'currency_exponent');
        $draftConvention = TaxConvention::from(RowCast::str($target->tax_convention, 'tax_convention'));

        if ($snapshot->currency !== $draftCurrency || $snapshot->currencyExponent !== $draftExponent) {
            throw new ProblemException(ProblemCode::MoneyCurrencyMismatch, 'billium.documents.tax_snapshot_currency_conflict');
        }
        if ($snapshot->convention !== $draftConvention) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.tax_snapshot_convention_conflict');
        }
        if ($draftConvention === TaxConvention::NoneDeclared && ($snapshot->taxMinor !== 0 || $snapshot->noTaxReason === null || trim($snapshot->noTaxReason) === '')) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.none_declared_requires_zero_tax_and_reason');
        }
        if ($snapshot->subtotalMinor + $snapshot->taxMinor !== $snapshot->totalMinor) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.tax_snapshot_total_conflict');
        }

        $view = self::hydrate($target);
        $draftByLine = [];
        $draftNetSum = 0;
        foreach ($view->lines as $line) {
            $draftByLine[$line->lineRef] = $line;
            $draftNetSum += $line->netMinor;
        }

        if (count($snapshot->lines) !== count($draftByLine)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.tax_snapshot_line_count_conflict');
        }

        $sumNet = 0;
        $sumTax = 0;
        $sumGross = 0;
        $seen = [];
        foreach ($snapshot->lines as $snapLine) {
            if (isset($seen[$snapLine->lineRef]) || ! isset($draftByLine[$snapLine->lineRef])) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.tax_snapshot_line_ref_conflict');
            }
            $seen[$snapLine->lineRef] = true;
            if ($snapLine->netMinor + $snapLine->taxMinor !== $snapLine->grossMinor || $snapLine->netMinor < 0 || $snapLine->taxMinor < 0) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.tax_snapshot_line_math_conflict');
            }
            if ($draftConvention !== TaxConvention::Inclusive && $snapLine->netMinor !== $draftByLine[$snapLine->lineRef]->netMinor) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.tax_snapshot_line_amount_conflict');
            }
            if ($draftConvention === TaxConvention::Inclusive && $snapLine->grossMinor !== $draftByLine[$snapLine->lineRef]->netMinor && $snapLine->netMinor !== $draftByLine[$snapLine->lineRef]->netMinor) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.tax_snapshot_inclusive_amount_conflict');
            }
            $sumNet += $snapLine->netMinor;
            $sumTax += $snapLine->taxMinor;
            $sumGross += $snapLine->grossMinor;
        }

        if ($sumNet !== $snapshot->subtotalMinor || $sumTax !== $snapshot->taxMinor || $sumGross !== $snapshot->totalMinor) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.tax_snapshot_sum_conflict');
        }
        if ($draftConvention !== TaxConvention::Inclusive && $snapshot->subtotalMinor !== $draftNetSum) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.tax_snapshot_subtotal_conflict');
        }
    }

    private static function validateCurrency(string $code, int $exponent): Currency
    {
        $currency = Currency::of($code);
        if ($currency->scale !== $exponent) {
            throw new ProblemException(ProblemCode::MoneyScaleInvalid, 'currency exponent mismatch for '.$currency->code);
        }

        return $currency;
    }

    private static function validateRoundingMode(string $mode): void
    {
        if (! in_array($mode, ['down', 'up', 'half_up', 'half_even'], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'unsupported rounding mode: '.$mode);
        }
    }

    /** @param list<DocumentLineBasis> $lines */
    private static function validateLines(array $lines): void
    {
        if ($lines === []) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'document draft requires at least one line');
        }
        $seen = [];
        foreach ($lines as $line) {
            RowCast::bounded($line->lineRef, 'lineRef');
            RowCast::bounded($line->obligationRef, 'obligationRef');
            RowCast::bounded($line->componentRef, 'componentRef');
            if (isset($seen[$line->lineRef])) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'duplicate lineRef: '.$line->lineRef);
            }
            $seen[$line->lineRef] = true;
            if ($line->netMinor < 0 || $line->discountMinor < 0) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'line minor amounts must be non-negative');
            }
            Dec::parse($line->quantity);
            Dec::parse($line->unitPriceDecimal);
            Dec::parse($line->taxRate);
        }
    }

    /**
     * @param  list<DocumentLineBasis>  $lines
     * @return list<array<string, mixed>>
     */
    private static function serializeLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $out[] = [
                'lineRef' => $line->lineRef,
                'obligationRef' => $line->obligationRef,
                'componentRef' => $line->componentRef,
                'quantity' => $line->quantity,
                'unitPriceDecimal' => $line->unitPriceDecimal,
                'netMinor' => $line->netMinor,
                'discountMinor' => $line->discountMinor,
                'taxCode' => $line->taxCode,
                'taxRate' => $line->taxRate,
                'description' => $line->description,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $buyerSnapshot
     * @param  array<string, mixed>  $termsSnapshot
     */
    private static function computeInputDigest(
        string $sellerRef,
        string $environment,
        string $finalizationRef,
        string $documentType,
        string $economicRef,
        int $basisRevision,
        string $basisDigest,
        int $profileId,
        int $profileVersion,
        string $currency,
        int $currencyExponent,
        string $convention,
        string $roundingMode,
        string $discountTaxOrder,
        array $lines,
        array $buyerSnapshot,
        array $termsSnapshot,
    ): string {
        return CanonicalJson::digest([
            'sellerRef' => $sellerRef,
            'environment' => $environment,
            'finalizationRef' => $finalizationRef,
            'documentType' => $documentType,
            'economicRef' => $economicRef,
            'basisRevision' => $basisRevision,
            'basisDigest' => $basisDigest,
            'profileId' => $profileId,
            'profileVersion' => $profileVersion,
            'currency' => $currency,
            'currencyExponent' => $currencyExponent,
            'convention' => $convention,
            'roundingMode' => $roundingMode,
            'discountTaxOrder' => $discountTaxOrder,
            'lines' => $lines,
            'buyerSnapshot' => $buyerSnapshot,
            'termsSnapshot' => $termsSnapshot,
        ]);
    }

    private static function nowUtc(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}

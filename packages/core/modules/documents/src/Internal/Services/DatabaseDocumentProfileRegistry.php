<?php

declare(strict_types=1);

namespace Billium\Documents\Internal\Services;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Documents\Contracts\DocumentProfileRegistry;
use Billium\Documents\Data\DocumentProfile;
use Billium\Documents\Data\DocumentScope;
use Billium\Documents\Data\RegisterDocumentProfile;
use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\SeriesPeriodPolicy;
use Billium\Documents\Internal\Canonical\CanonicalJson;
use Billium\Documents\Internal\DocumentReceipt;
use Billium\Documents\Internal\Support\RowCast;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class DatabaseDocumentProfileRegistry implements DocumentProfileRegistry
{
    public function __construct(
        private DatabaseManager $databases,
        private OperationRunner $runner,
        private CallerContext $callers,
        private BilliumOptions $options,
    ) {}

    public function register(RegisterDocumentProfile $command): DocumentProfile
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->profileRef, 'profileRef');
        if ($command->version < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'profile version must be positive');
        }

        $supportedValues = array_map(
            static fn (DocumentType $type): string => $type->value,
            $command->supportedTypes,
        );
        $requiredFields = $command->requiredFields;
        $isComplete = self::evaluateCompleteness($command, $supportedValues, $requiredFields);

        $payload = [
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'profileRef' => $command->profileRef,
            'version' => $command->version,
            'jurisdictionRef' => $command->jurisdictionRef,
            'supportedTypes' => $supportedValues,
            'numberingPolicy' => $command->numberingPolicy,
            'taxSnapshotSchema' => $command->taxSnapshotSchema,
            'correctionPolicy' => $command->correctionPolicy,
            'requiredFields' => $requiredFields,
            'retentionPolicyRef' => $command->retentionPolicyRef,
            'sellerSnapshot' => $command->sellerSnapshot,
            'isComplete' => $isComplete,
        ];
        $digest = CanonicalJson::digest($payload);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.profile.register.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Administrative,
                operationClass: ModuleOperationClass::NewIntent,
                modules: ['platform', 'ledger', 'documents'],
            ),
            body: function (TransactionContext $context) use ($caller, $command, $supportedValues, $requiredFields, $isComplete, $digest): DocumentReceipt {
                $this->runner->assertCurrent($context, 'documents');
                $db = $this->databases->connection($context->connectionName());
                $scopeWhere = [
                    'seller_ref' => $caller->sellerRef,
                    'environment' => $caller->environment,
                    'profile_ref' => $command->profileRef,
                    'version' => $command->version,
                ];
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
                $db->table('billium_document_profile')->insertOrIgnore([
                    ...$scopeWhere,
                    'jurisdiction_ref' => $command->jurisdictionRef,
                    'supported_types' => CanonicalJson::encode($supportedValues),
                    'numbering_policy' => CanonicalJson::encodeObject($command->numberingPolicy),
                    'tax_snapshot_schema' => $command->taxSnapshotSchema,
                    'correction_policy' => CanonicalJson::encodeObject($command->correctionPolicy),
                    'required_fields' => CanonicalJson::encode($requiredFields),
                    'retention_policy_ref' => $command->retentionPolicyRef,
                    'seller_snapshot' => CanonicalJson::encodeObject($command->sellerSnapshot),
                    'is_complete' => $isComplete,
                    'profile_digest' => $digest,
                    'created_at' => $now,
                ]);
                $row = $db->table('billium_document_profile')->where($scopeWhere)->first();
                if ($row === null || RowCast::str($row->profile_digest, 'profile_digest') !== $digest) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.profile_conflict');
                }

                return new DocumentReceipt('documents.profile.registered', [
                    'profileId' => RowCast::integer($row->id, 'id'),
                    'profileRef' => $command->profileRef,
                    'version' => $command->version,
                ]);
            },
        );

        $profileId = RowCast::integer($outcome->body['profileId'] ?? null, 'profileId');

        return new DocumentProfile(
            id: $profileId,
            sellerRef: $caller->sellerRef,
            environment: $caller->environment,
            profileRef: $command->profileRef,
            version: $command->version,
            jurisdictionRef: $command->jurisdictionRef,
            supportedTypes: $command->supportedTypes,
            numberingPolicy: $command->numberingPolicy,
            taxSnapshotSchema: $command->taxSnapshotSchema,
            correctionPolicy: $command->correctionPolicy,
            requiredFields: $requiredFields,
            retentionPolicyRef: $command->retentionPolicyRef,
            sellerSnapshot: $command->sellerSnapshot,
            isComplete: $isComplete,
            profileDigest: $digest,
        );
    }

    public function find(DocumentScope $scope, string $profileRef, int $version): ?DocumentProfile
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        $db = $this->databases->connection($this->options->connection);

        $row = $db->table('billium_document_profile')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('profile_ref', $profileRef)
            ->where('version', $version)
            ->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function requireComplete(DocumentScope $scope, string $profileRef, int $version, DocumentType $documentType): DocumentProfile
    {
        $profile = $this->find($scope, $profileRef, $version);
        if ($profile === null) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.documents.profile_not_found');
        }
        if (! $profile->isComplete) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.profile_incomplete');
        }
        if (! in_array($documentType, $profile->supportedTypes, true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.document_type_unsupported');
        }

        return $profile;
    }

    public static function hydrate(stdClass $row): DocumentProfile
    {
        $rawTypes = RowCast::jsonList($row->supported_types, 'supported_types');
        $supportedTypes = [];
        foreach ($rawTypes as $item) {
            $supportedTypes[] = DocumentType::from(RowCast::str($item, 'supported_types[]'));
        }
        $rawRequired = RowCast::jsonList($row->required_fields, 'required_fields');
        $requiredFields = [];
        foreach ($rawRequired as $item) {
            $requiredFields[] = RowCast::str($item, 'required_fields[]');
        }

        return new DocumentProfile(
            id: RowCast::integer($row->id, 'id'),
            sellerRef: RowCast::str($row->seller_ref, 'seller_ref'),
            environment: RowCast::str($row->environment, 'environment'),
            profileRef: RowCast::str($row->profile_ref, 'profile_ref'),
            version: RowCast::integer($row->version, 'version'),
            jurisdictionRef: RowCast::str($row->jurisdiction_ref, 'jurisdiction_ref'),
            supportedTypes: $supportedTypes,
            numberingPolicy: RowCast::jsonObject($row->numbering_policy, 'numbering_policy'),
            taxSnapshotSchema: RowCast::str($row->tax_snapshot_schema, 'tax_snapshot_schema'),
            correctionPolicy: RowCast::jsonObject($row->correction_policy, 'correction_policy'),
            requiredFields: $requiredFields,
            retentionPolicyRef: RowCast::str($row->retention_policy_ref, 'retention_policy_ref'),
            sellerSnapshot: RowCast::jsonObject($row->seller_snapshot, 'seller_snapshot'),
            isComplete: RowCast::boolean($row->is_complete, 'is_complete'),
            profileDigest: RowCast::str($row->profile_digest, 'profile_digest'),
        );
    }

    /**
     * @param  list<string>  $supportedTypes
     * @param  list<string>  $requiredFields
     */
    private static function evaluateCompleteness(
        RegisterDocumentProfile $command,
        array $supportedTypes,
        array $requiredFields,
    ): bool {
        if (
            trim($command->jurisdictionRef) === ''
            || $supportedTypes === []
            || trim($command->taxSnapshotSchema) === ''
            || trim($command->retentionPolicyRef) === ''
            || $command->correctionPolicy === []
            || $requiredFields === []
        ) {
            return false;
        }

        $seriesRef = $command->numberingPolicy['seriesRef'] ?? null;
        $periodPolicy = $command->numberingPolicy['periodPolicy'] ?? null;
        if (! is_string($seriesRef) || trim($seriesRef) === ''
            || ! is_string($periodPolicy) || SeriesPeriodPolicy::tryFrom($periodPolicy) === null) {
            return false;
        }

        foreach (['legalName', 'taxId'] as $mandatorySellerKey) {
            $val = $command->sellerSnapshot[$mandatorySellerKey] ?? null;
            if (! is_string($val) || trim($val) === '') {
                return false;
            }
        }

        foreach ($requiredFields as $field) {
            if (trim($field) === '') {
                return false;
            }
            if (str_starts_with($field, 'seller.')) {
                $key = substr($field, 7);
                $val = $command->sellerSnapshot[$key] ?? null;
                if (! is_string($val) || trim($val) === '') {
                    return false;
                }
            }
        }

        return true;
    }
}

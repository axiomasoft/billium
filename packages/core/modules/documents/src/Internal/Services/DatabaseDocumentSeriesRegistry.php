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
use Billium\Documents\Contracts\DocumentSeriesRegistry;
use Billium\Documents\Data\CreateDocumentSeries;
use Billium\Documents\Data\DocumentScope;
use Billium\Documents\Data\DocumentSeriesView;
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
use Throwable;

#[Internal]
final readonly class DatabaseDocumentSeriesRegistry implements DocumentSeriesRegistry
{
    public function __construct(
        private DatabaseManager $databases,
        private OperationRunner $runner,
        private CallerContext $callers,
        private BilliumOptions $options,
    ) {}

    public function createSeries(CreateDocumentSeries $command): DocumentSeriesView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->seriesRef, 'seriesRef');
        RowCast::bounded($command->periodKey, 'periodKey', 32);
        RowCast::bounded($command->prefix, 'prefix', 40);
        if ($command->padding < 1 || $command->padding > 18) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'series padding must be between 1 and 18');
        }
        if ($command->legalEntityRevision < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'legalEntityRevision must be positive');
        }
        self::validateTimezone($command->timezone);
        self::validatePeriodKey($command->periodPolicy, $command->periodKey);

        $digest = CanonicalJson::digest([
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'seriesRef' => $command->seriesRef,
            'documentType' => $command->documentType->value,
            'periodKey' => $command->periodKey,
            'periodPolicy' => $command->periodPolicy->value,
            'timezone' => $command->timezone,
            'prefix' => $command->prefix,
            'padding' => $command->padding,
            'legalEntityRevision' => $command->legalEntityRevision,
        ]);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.series.create.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Administrative,
                operationClass: ModuleOperationClass::NewIntent,
                modules: ['platform', 'ledger', 'documents'],
            ),
            body: function (TransactionContext $context) use ($caller, $command, $digest): DocumentReceipt {
                $this->runner->assertCurrent($context, 'documents');
                $db = $this->databases->connection($context->connectionName());
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
                $where = [
                    'seller_ref' => $caller->sellerRef,
                    'environment' => $caller->environment,
                    'series_ref' => $command->seriesRef,
                    'period_key' => $command->periodKey,
                ];

                $db->table('billium_document_series')->insertOrIgnore([
                    ...$where,
                    'document_type' => $command->documentType->value,
                    'period_policy' => $command->periodPolicy->value,
                    'timezone' => $command->timezone,
                    'prefix' => $command->prefix,
                    'padding' => $command->padding,
                    'legal_entity_revision' => $command->legalEntityRevision,
                    'next_number' => 1,
                    'active' => true,
                    'series_digest' => $digest,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $row = $db->table('billium_document_series')->where($where)->first();
                if ($row === null || RowCast::str($row->series_digest, 'series_digest') !== $digest) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.series_conflict');
                }

                return new DocumentReceipt('documents.series.created', ['view' => self::toArray($row)]);
            },
        );

        /** @var array<string, mixed> $viewData */
        $viewData = $outcome->body['view'];

        return new DocumentSeriesView(
            id: RowCast::integer($viewData['id'], 'id'),
            sellerRef: RowCast::str($viewData['sellerRef'], 'sellerRef'),
            environment: RowCast::str($viewData['environment'], 'environment'),
            seriesRef: RowCast::str($viewData['seriesRef'], 'seriesRef'),
            documentType: DocumentType::from(RowCast::str($viewData['documentType'], 'documentType')),
            periodKey: RowCast::str($viewData['periodKey'], 'periodKey'),
            periodPolicy: SeriesPeriodPolicy::from(RowCast::str($viewData['periodPolicy'], 'periodPolicy')),
            timezone: RowCast::str($viewData['timezone'], 'timezone'),
            prefix: RowCast::str($viewData['prefix'], 'prefix'),
            padding: RowCast::integer($viewData['padding'], 'padding'),
            legalEntityRevision: RowCast::integer($viewData['legalEntityRevision'], 'legalEntityRevision'),
            nextNumber: RowCast::integer($viewData['nextNumber'], 'nextNumber'),
            active: RowCast::boolean($viewData['active'], 'active'),
        );
    }

    public function findSeries(DocumentScope $scope, string $seriesRef, string $periodKey): ?DocumentSeriesView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        $row = $this->databases->connection($this->options->connection)->table('billium_document_series')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('series_ref', $seriesRef)
            ->where('period_key', $periodKey)
            ->first();

        return $row === null ? null : self::hydrate($row);
    }

    public static function hydrate(stdClass $row): DocumentSeriesView
    {
        return new DocumentSeriesView(
            id: RowCast::integer($row->id, 'id'),
            sellerRef: RowCast::str($row->seller_ref, 'seller_ref'),
            environment: RowCast::str($row->environment, 'environment'),
            seriesRef: RowCast::str($row->series_ref, 'series_ref'),
            documentType: DocumentType::from(RowCast::str($row->document_type, 'document_type')),
            periodKey: RowCast::str($row->period_key, 'period_key'),
            periodPolicy: SeriesPeriodPolicy::from(RowCast::str($row->period_policy, 'period_policy')),
            timezone: RowCast::str($row->timezone, 'timezone'),
            prefix: RowCast::str($row->prefix, 'prefix'),
            padding: RowCast::integer($row->padding, 'padding'),
            legalEntityRevision: RowCast::integer($row->legal_entity_revision, 'legal_entity_revision'),
            nextNumber: RowCast::integer($row->next_number, 'next_number'),
            active: RowCast::boolean($row->active, 'active'),
        );
    }

    /** @return array<string, mixed> */
    private static function toArray(stdClass $row): array
    {
        return [
            'id' => RowCast::integer($row->id, 'id'),
            'sellerRef' => RowCast::str($row->seller_ref, 'seller_ref'),
            'environment' => RowCast::str($row->environment, 'environment'),
            'seriesRef' => RowCast::str($row->series_ref, 'series_ref'),
            'documentType' => RowCast::str($row->document_type, 'document_type'),
            'periodKey' => RowCast::str($row->period_key, 'period_key'),
            'periodPolicy' => RowCast::str($row->period_policy, 'period_policy'),
            'timezone' => RowCast::str($row->timezone, 'timezone'),
            'prefix' => RowCast::str($row->prefix, 'prefix'),
            'padding' => RowCast::integer($row->padding, 'padding'),
            'legalEntityRevision' => RowCast::integer($row->legal_entity_revision, 'legal_entity_revision'),
            'nextNumber' => RowCast::integer($row->next_number, 'next_number'),
            'active' => RowCast::boolean($row->active, 'active'),
        ];
    }

    private static function validateTimezone(string $timezone): void
    {
        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'invalid series timezone: '.$timezone);
        }
    }

    private static function validatePeriodKey(SeriesPeriodPolicy $policy, string $periodKey): void
    {
        $valid = match ($policy) {
            SeriesPeriodPolicy::None => $periodKey === 'NONE',
            SeriesPeriodPolicy::Yearly => preg_match('/^\d{4}$/D', $periodKey) === 1,
            SeriesPeriodPolicy::Monthly => preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/D', $periodKey) === 1,
        };
        if (! $valid) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'invalid periodKey for policy '.$policy->value);
        }
    }
}

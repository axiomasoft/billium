<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Import;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Data\Runtime\Caller;
use Billium\Platform\Contracts\Import\ImportBatchApplier;
use Billium\Platform\Contracts\Import\ImportReconciler;
use Billium\Platform\Data\Import\ImportApplyOptions;
use Billium\Platform\Data\Import\ImportReconciliationReport;
use Billium\Platform\Internal\Import\Sources\GenericExportReader;
use Billium\Platform\Internal\Import\Sources\RecurringExportProfile;
use Billium\Platform\Tests\PlatformTestCase;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\Group;

/**
 * Reconciliation resolves mappings and applied receipts per bounded chunk of staged rows, never per row.
 */
#[Group('P9.3')]
#[Group('pg')]
final class ImportReconcileQueryBudgetTest extends PlatformTestCase
{
    private const string SELLER = 'seller-recurring-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
        $this->callers->set(new Caller(
            sellerRef: self::SELLER,
            environment: 'test',
            installationRef: self::INSTALLATION,
            actorRef: 'actor-import-op',
            sourceAuthorities: [ImportApplyOptions::DEFAULT_MIGRATION_SOURCE_AUTHORITY],
        ));
    }

    public function test_a_small_batch_resolves_mappings_and_receipts_in_one_query_each(): void
    {
        $batchId = $this->applyReferenceBatch();

        [$report, $lookups] = $this->reconcileCountingLookups($batchId);

        self::assertTrue($report->reconciled);
        self::assertSame(['mapping' => 1, 'receipt' => 1], $lookups);
    }

    public function test_a_batch_spanning_several_chunks_keeps_every_row_matched_to_its_own_lookup(): void
    {
        $batchId = $this->applyReferenceBatch();
        $baseline = $this->reconciler()->reconcileBatch($batchId);
        self::assertCount(3, $baseline->accountingFacts);

        // 11 staged rows x (1 + 49 copies) = 550 rows: two lookup chunks. Every copy keeps a mapping with a target,
        // so each payment/refund copy must still yield its own accounting fact after crossing the chunk boundary.
        $this->stageMappedCopies(49);

        [$report, $lookups] = $this->reconcileCountingLookups($batchId);

        self::assertSame(['mapping' => 2, 'receipt' => 2], $lookups);
        self::assertCount(3 * 50, $report->accountingFacts);
        $applied = 0;
        foreach ($report->buckets as $bucket) {
            $applied += $bucket->appliedCount;
        }
        $baselineApplied = 0;
        foreach ($baseline->buckets as $bucket) {
            $baselineApplied += $bucket->appliedCount;
        }
        self::assertSame($baselineApplied * 50, $applied);
    }

    private function applyReferenceBatch(): string
    {
        $fixturePath = dirname(__DIR__, 6).'/fixtures/integration/import/reference-recurring/complete-manifest.json';
        $reader = GenericExportReader::fromFile($fixturePath, new RecurringExportProfile(version: '1.0.0'), 'import-policy-v1', new Codec);
        $this->application()->make(ImportBatchApplier::class)->apply(
            $reader->manifest(),
            $reader->records(),
            new ImportApplyOptions(
                capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                expectedManifestDigest: $reader->manifest()->manifestDigest,
                approvalRef: 'approve-complete-001',
            ),
        );

        return $reader->manifest()->batchId;
    }

    private function stageMappedCopies(int $copies): void
    {
        $recordColumns = 'batch_pk, installation_ref, environment, seller_ref, source_system, source_installation, source_book, '
            .'source_record_revision, record_kind, source_identity_digest, payload_digest, event_business_date, currency, '
            .'amount_minor, source_fact_ref, import_policy_version, validation_status, reason_codes, missing_evidence, '
            .'rejection_code, payload';
        $mappingColumns = 'installation_ref, environment, seller_ref, source_system, source_installation, source_book, '
            .'record_kind, source_record_revision, source_identity_digest, payload_digest, billium_target_kind, '
            .'billium_target_ref, original_issuer, original_source_number, import_policy_version, status, '
            .'tombstone_reason, tombstoned_at, superseded_by_revision';
        $owner = $this->owner();
        $owner->statement(
            "INSERT INTO public.billium_import_record (source_record_id, {$recordColumns}) "
            ."SELECT source_record_id || '-copy-' || g, {$recordColumns} "
            .'FROM public.billium_import_record, generate_series(1, ?) AS g',
            [$copies],
        );
        $owner->statement(
            "INSERT INTO public.billium_import_mapping (mapping_ref, source_record_id, source_fact_ref, {$mappingColumns}) "
            ."SELECT mapping_ref || '-copy-' || g, source_record_id || '-copy-' || g, source_fact_ref || '-copy-' || g, {$mappingColumns} "
            .'FROM public.billium_import_mapping, generate_series(1, ?) AS g',
            [$copies],
        );
    }

    /**
     * @return array{0: ImportReconciliationReport, 1: array{mapping: int, receipt: int}}
     */
    private function reconcileCountingLookups(string $batchId): array
    {
        $lookups = ['mapping' => 0, 'receipt' => 0];
        $this->application()->make(Dispatcher::class)->listen(
            QueryExecuted::class,
            static function (QueryExecuted $query) use (&$lookups): void {
                if (str_contains($query->sql, 'billium_import_mapping')) {
                    $lookups['mapping']++;
                }
                if (str_contains($query->sql, 'billium_import_receipt')) {
                    $lookups['receipt']++;
                }
            },
        );

        return [$this->reconciler()->reconcileBatch($batchId), $lookups];
    }

    private function reconciler(): ImportReconciler
    {
        return $this->application()->make(ImportReconciler::class);
    }
}

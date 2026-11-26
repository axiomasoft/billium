<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6;

use Billium\Core\Tests\P6\Support\DocumentsTestCase;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/** The orphan blob sweep reads only orphaned and half-deleted blobs, never every retained blob of the seller. */
#[Group('pg')]
final class RenderBlobCleanupPlanTest extends DocumentsTestCase
{
    public function test_cleanup_candidates_are_read_through_the_partial_cleanup_index(): void
    {
        // The candidate read of DatabaseDocumentRenderService::cleanupOrphanBlobs.
        $source = file_get_contents(dirname(__DIR__, 2).'/modules/documents/src/Internal/Services/DatabaseDocumentRenderService.php');
        self::assertIsString($source);
        self::assertMatchesRegularExpression("/table\\('billium_document_render_blob'\\)\\s*->where\\('seller_ref', [^)]+\\)\\s*->where\\('environment', [^)]+\\)\\s*->whereIn\\('status', \\['orphaned', 'deleting'\\]\\)\\s*->orderBy\\('id'\\)/", $source);

        $owner = $this->owner();
        $query = $owner->table('billium_document_render_blob')
            ->where('seller_ref', 'seller-a')->where('environment', 'test')->whereIn('status', ['orphaned', 'deleting'])->orderBy('id');
        $owner->beginTransaction();
        try {
            // The fixture table is empty; forbidding the sequential scan shows whether a sweep index exists at all.
            $owner->statement('SET LOCAL enable_seqscan = off');
            $explain = $owner->selectOne('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings());
        } finally {
            $owner->rollBack();
        }
        self::assertInstanceOf(stdClass::class, $explain);
        $plan = self::text($explain->{'QUERY PLAN'});
        self::assertStringContainsString('"Index Name": "billium_document_render_blob_cleanup_idx"', $plan);
        self::assertStringNotContainsString('"Node Type": "Seq Scan"', $plan);
        self::assertStringNotContainsString('"Node Type": "Sort"', $plan);
    }
}

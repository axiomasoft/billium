<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6;

use Billium\Core\Tests\P6\Support\DocumentsTestCase;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/** A document correction reads the prior credit note lines of one original invoice by key, never every line of the seller. */
#[Group('pg')]
final class CreditNoteLineLookupPlanTest extends DocumentsTestCase
{
    public function test_prior_credit_lines_are_read_through_the_original_document_index(): void
    {
        // The read DatabaseDocumentCorrectionService issues inside the correction transaction before it caps the credit.
        $source = file_get_contents(dirname(__DIR__, 2).'/modules/documents/src/Internal/Services/DatabaseDocumentCorrectionService.php');
        self::assertIsString($source);
        self::assertMatchesRegularExpression("/table\\('billium_document_credit_note_line'\\)\\s*->where\\('seller_ref', [^)]+\\)\\s*->where\\('environment', [^)]+\\)\\s*->where\\('original_legal_document_id', /", $source);

        $owner = $this->owner();
        $query = $owner->table('billium_document_credit_note_line')
            ->where('seller_ref', 'seller-a')->where('environment', 'test')->where('original_legal_document_id', 1);
        $owner->beginTransaction();
        try {
            // The fixture table is empty; forbidding the sequential scan shows whether a lookup index exists at all.
            $owner->statement('SET LOCAL enable_seqscan = off');
            $explain = $owner->selectOne('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings());
        } finally {
            $owner->rollBack();
        }
        self::assertInstanceOf(stdClass::class, $explain);
        $plan = self::text($explain->{'QUERY PLAN'});
        self::assertStringContainsString('"Index Name": "billium_document_credit_note_line_original_idx"', $plan);
        self::assertStringNotContainsString('"Node Type": "Seq Scan"', $plan);
    }
}

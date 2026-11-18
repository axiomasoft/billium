<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Feature;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Rating\Data\Rating\BookingReceipt;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Data\Rating\RatedBasisDraft;
use Billium\Rating\Data\Rating\RatedLineDraft;
use Billium\Rating\Enums\BookingOutcome;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Storage\RatingStore;
use Billium\Rating\Tests\RatingTestCase;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\Group;

/** A rated basis stores all of its lines in one statement, in line order. */
#[Group('pg')]
final class RatedLineBatchWriteTest extends RatingTestCase
{
    public function test_a_basis_writes_every_rated_line_in_one_statement(): void
    {
        $single = $this->prepared();
        $lines = [];
        foreach ([1, 2, 3] as $n) {
            $lines[] = FixtureArguments::construct(class: RatedLineDraft::class, arguments: [...get_object_vars($single->draft->lines[0]), 'lineNo' => $n]);
        }
        $prepared = new PreparedRating(inputs: $single->inputs,
            draft: FixtureArguments::construct(class: RatedBasisDraft::class, arguments: [...get_object_vars($single->draft), 'lines' => $lines]));
        $writes = 0;
        $this->application()->make(Dispatcher::class)->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$writes): void {
            if (str_starts_with($query->sql, 'insert into "billium_rating_rated_line"')) {
                $writes++;
            }
        });

        $in = $prepared->inputs;
        $this->runner()->run(spec: new OperationSpec(commandKind: 'rating.book.v1', sellerRef: $in->sellerRef, environment: $in->environment,
            mode: FinancialMode::Active, operationClass: ModuleOperationClass::NewIntent, modules: ['platform', 'usage', 'schedule', 'rating'],
            commandKey: 'lines-batch', digest: $in->digest(), commitKind: 'rating.book.v1'),
            body: function (TransactionContext $context) use ($prepared, $in): BookingReceipt {
                $this->application()->make(RatingStore::class)->insertBasis(context: $context, prepared: $prepared);

                return new BookingReceipt(basisRef: $prepared->draft->basisRef, outcome: BookingOutcome::Unposted, obligationRef: null,
                    grossMinor: $prepared->draft->grossMinor, currency: $in->currency);
            });

        self::assertSame(1, $writes);
        $stored = $this->owner()->table('billium_rating_rated_line')->orderBy('line_no')->get(['line_no', 'line_snapshot']);
        self::assertSame([1, 2, 3], $stored->pluck('line_no')->all());
        foreach ($prepared->draft->lines as $index => $line) {
            self::assertSame(CanonicalJson::encode($line), CanonicalJson::encode(json_decode(self::text($stored[$index]->line_snapshot ?? null), false, 64, JSON_THROW_ON_ERROR)));
        }
    }
}

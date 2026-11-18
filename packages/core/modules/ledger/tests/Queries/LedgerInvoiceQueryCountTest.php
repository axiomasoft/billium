<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Queries;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\Group;

/** An invoice read resolves its line obligations in one statement, not one per line. */
#[Group('pg')]
final class LedgerInvoiceQueryCountTest extends QueryTestCase
{
    private int $queries = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->application()->make(Dispatcher::class)->listen(QueryExecuted::class, function (): void {
            $this->queries++;
        });
    }

    public function test_an_invoice_resolves_its_line_obligations_in_one_statement(): void
    {
        foreach (['a', 'b', 'c'] as $suffix) {
            $this->debt('obligation-'.$suffix, 1000);
        }
        $this->draft(['obligation-a' => 0], 'invoice-single')->orThrow();
        $this->draft(['obligation-b' => 0, 'obligation-c' => 0], 'invoice-double')->orThrow();

        $this->queries = 0;
        $single = $this->queries()->invoice('invoice-single');
        $one = $this->queries;
        $this->queries = 0;
        $double = $this->queries()->invoice('invoice-double');

        self::assertSame($one, $this->queries);
        self::assertCount(1, self::arr($single['lines']));
        self::assertSame(['obligation-b', 'obligation-c'], array_map(
            static fn (mixed $line): mixed => self::arr(self::arr($line)['obligation'])['obligationRef'], self::arr($double['lines'])));
    }
}

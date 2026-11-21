<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Queries;

use Billium\Contracts\Data\Money;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Ledger\Data\Obligations\AdjustObligation;
use Billium\Ledger\Internal\Snapshot\LedgerSnapshotSource;
use Billium\Platform\Contracts\Feed\EventFeed;
use Billium\Platform\Contracts\Feed\FeedRecoveryQueries;
use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Data\Feed\FeedSelection;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\Group;

/** Snapshot streams resolve child rows and references per table, never per parent row. */
#[Group('pg')]
final class LedgerSnapshotQueryCountTest extends QueryTestCase
{
    private int $queries = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->application()->make(Dispatcher::class)->listen(QueryExecuted::class, function (): void {
            $this->queries++;
        });
    }

    public function test_snapshot_streams_issue_the_same_statements_for_one_or_many_parents(): void
    {
        $this->seedQueryState();
        $this->creditNote('note-a', ['obligation-a' => '10.00'])->orThrow();
        $selection = $this->selection();
        $few = $this->statements($selection);
        foreach (['b', 'c', 'd'] as $suffix) {
            $this->debt('obligation-'.$suffix, 1000);
            $this->ledger()->adjustObligation(new AdjustObligation('adjust-'.$suffix, $this->account(), 'adjustment-'.$suffix,
                'obligation-'.$suffix, Money::parse('1.00', 'RUB'), 'basis-adjustment-'.$suffix))->orThrow();
            $this->draft(['obligation-'.$suffix => 0], 'invoice-'.$suffix)->orThrow();
            $this->issue('invoice-'.$suffix)->orThrow();
            $this->intent('intent-'.$suffix, ['obligation-'.$suffix]);
            $this->creditNote('note-'.$suffix, ['obligation-'.$suffix => '0.50'], invoice: 'invoice-'.$suffix)->orThrow();
        }
        $many = $this->statements($selection);

        self::assertSame($few['counts'], $many['counts']);
        self::assertSame(4, $many['records']['ledger.invoices']);
        self::assertSame($few['records']['ledger.obligations'] + 3, $many['records']['ledger.obligations']);
    }

    private function selection(): FeedSelection
    {
        config(['billium.feed.signing_key' => str_repeat('k', 32)]);
        $this->application()->forgetInstance(BilliumOptions::class);

        return $this->application()->make(EventFeed::class)
            ->openFeed(new FeedScope(self::INSTALLATION, 'seller-a', 'test', '1'), 'events-2026-10-v1', ['billium.event.v1'], ['obligation.created.v1']);
    }

    /** @return array{counts: array<string, int>, records: array<string, int>} */
    private function statements(FeedSelection $selection): array
    {
        $databases = $this->application()->make(DatabaseManager::class);
        $db = $databases->connection();
        $name = self::text($db->getName());
        $counts = [];
        $records = [];
        $db->beginTransaction();
        try {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            foreach (['ledger.obligations', 'ledger.invoices', 'ledger.allocations', 'ledger.discrepancies'] as $key) {
                $source = new LedgerSnapshotSource($databases, $this->application()->make(CallerContext::class), $key, $this->application()->make(FeedRecoveryQueries::class));
                $this->queries = 0;
                $records[$key] = count($source->snapshot($selection, $name));
                $counts[$key] = $this->queries;
            }
        } finally {
            $db->rollBack();
        }

        return ['counts' => $counts, 'records' => $records];
    }
}

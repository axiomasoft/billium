<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Concurrency;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Tests\Commands\InvoiceFixtures;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * INV-13: a race between issue and metadata updates never leaves an edit outside the allow-list on an issued invoice,
 * and an allow-list key passes in either order.
 */
#[Group('ACC-CON-17')]
#[Group('pg')]
final class InvoiceIssueMetadataTest extends LedgerTestCase
{
    use InvoiceFixtures;

    public function test_issue_races_two_metadata_updates_and_only_the_allow_list_survives(): void
    {
        $rounds = 3;
        for ($round = 1; $round <= $rounds; $round++) {
            $this->obligation('obligation-'.$round, 1000);
            self::assertTrue($this->draft(['obligation-'.$round => 0], 'invoice-'.$round)->accepted);
        }
        $results = ConcurrencyStand::run(InvoiceIssueMetadataScenario::class, 3, ['rounds' => $rounds]);
        for ($round = 1; $round <= $rounds; $round++) {
            $issue = $results[0]['ok']['rounds'][$round - 1] ?? null;
            $note = $results[1]['ok']['rounds'][$round - 1] ?? null;
            $foo = $results[2]['ok']['rounds'][$round - 1] ?? null;
            self::assertIsArray($issue);
            self::assertIsArray($note);
            self::assertIsArray($foo);
            self::assertTrue($issue['accepted'] ?? false, 'issue: '.json_encode($issue));
            self::assertTrue($note['accepted'] ?? false, 'note passes before and after issue: '.json_encode($note));
            // `foo` is invalid in a draft (a validation exception, no stored result) and frozen after the issue (stored refusal).
            $refusal = $foo['problem'] ?? null;
            self::assertContains($refusal, [ProblemCode::ValidationInvalid->value, ProblemCode::InvoiceFrozen->value], 'foo: '.json_encode($foo));
            self::assertFalse($foo['accepted'] ?? false);

            $row = $this->invoiceRow('invoice-'.$round);
            self::assertSame('issued', $row['status']);
            self::assertSame(['note' => 'note-'.$round], json_decode(self::text($row['metadata']), true, 8, JSON_THROW_ON_ERROR));
            self::assertSame(1, $this->rows('billium_outbox_event', "type='invoice.issued.v1' AND aggregate_ref='invoice-{$round}'"));
        }
    }

    public function test_after_the_issue_foo_is_frozen_note_passes_and_raw_sql_hits_the_trigger(): void
    {
        $this->obligation('obligation-a', 1000);
        $this->draft();
        $this->issue();
        self::assertSame(ProblemCode::InvoiceFrozen, $this->updateMetadata('u-foo', ['foo' => 'bar'])->problem);
        self::assertTrue($this->updateMetadata('u-note', ['note' => 'ok'])->accepted);
        [$state, $message] = self::refusal(TestEnvironment::pdo(owner: true), 'UPDATE billium_invoice SET total_minor=total_minor+1,subtotal_minor=subtotal_minor+1');
        self::assertSame('55000', $state);
        self::assertStringContainsString(ProblemCode::InvoiceFrozen->value, $message);
        self::assertSame(1000, self::integer($this->invoiceRow()['total_minor']));
        self::assertSame(['note' => 'ok'], json_decode(self::text($this->invoiceRow()['metadata']), true, 8, JSON_THROW_ON_ERROR));
    }
}

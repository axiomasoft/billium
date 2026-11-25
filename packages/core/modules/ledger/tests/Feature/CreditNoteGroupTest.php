<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\Receipt;
use Billium\Ledger\Contracts\CorrectionCreditParticipants;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Data\Invoices\CreditNoteLine;
use Billium\Ledger\Data\Invoices\CreditNoteMode;
use Billium\Ledger\Data\Invoices\CreditNotePart;
use Billium\Ledger\Data\Invoices\IssueCreditNoteGroup;
use Billium\Ledger\Tests\Commands\InvoiceFixtures;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use PHPUnit\Framework\Attributes\Group;

/** A credit spanning several invoices: ledger names the parts and resolves the group ref, whoever the caller is. */
#[Group('pg')]
final class CreditNoteGroupTest extends LedgerTestCase
{
    use InvoiceFixtures;

    public function test_group_ref_of_any_caller_resolves_to_every_part_and_replays(): void
    {
        $this->fixture();
        $receipt = $this->creditGroup(key: 'k-group', parts: [$this->part('invoice-b', 'o-b'), $this->part('invoice-a', 'o-a')]);
        $group = self::text($receipt['creditNoteRef']);
        self::assertStringStartsWith('host-credit:group:', $group);
        $partRefs = $receipt['partRefs'];
        self::assertIsArray($partRefs);
        self::assertCount(2, $partRefs);
        self::assertSame(2, $this->rows('billium_credit_note'));
        $queries = $this->application()->make(LedgerQueries::class);
        $resolved = $queries->receipt('credit-note:'.$group);
        self::assertSame(array_map(static fn (mixed $ref): string => 'credit-note:'.self::text($ref), $partRefs),
            array_map(static fn (mixed $part): string => self::text(self::arr($part)['receiptRef']), self::arr($resolved['parts'])));
        $replay = $this->creditGroup(key: 'k-group-replay', parts: [$this->part('invoice-a', 'o-a'), $this->part('invoice-b', 'o-b')]);
        self::assertSame($receipt, $replay);
        self::assertSame(2, $this->rows('billium_credit_note'));
        foreach (['credit-note:'.$group.'.0000000000000000', 'credit-note:host-credit:group:'.substr($group, -16), 'host-credit:group:x.y'] as $unknown) {
            try {
                $queries->receipt($unknown);
                self::fail($unknown.' resolved');
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::ReferenceUnknown, $error->problem);
            }
        }
    }

    public function test_one_invoice_twice_in_a_group_is_refused_before_any_credit(): void
    {
        $this->fixture();
        try {
            $this->runner()->run($this->spec(key: 'k-duplicate'), fn (TransactionContext $context): Receipt => self::receipt([
                'ref' => $this->participant()->issue($context, $this->group([$this->part('invoice-a', 'o-a'), $this->part('invoice-a', 'o-a')]))->creditNoteRef]));
            self::fail('One invoice was credited twice in a group');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
        }
        self::assertSame(0, $this->rows('billium_credit_note'));
    }

    public function test_an_empty_group_is_refused(): void
    {
        $this->fixture();
        try {
            $this->runner()->run($this->spec(key: 'k-empty'), fn (TransactionContext $context): Receipt => self::receipt([
                'ref' => $this->participant()->issue($context, $this->group([]))->creditNoteRef]));
            self::fail('An empty credit-note group was accepted');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
        }
        self::assertSame(0, $this->rows('billium_credit_note'));
    }

    private function fixture(): void
    {
        foreach (['a', 'b'] as $suffix) {
            $this->obligation('o-'.$suffix, 10000);
            $this->draft(['o-'.$suffix => 0], 'invoice-'.$suffix);
            $this->issue('invoice-'.$suffix);
        }
    }

    /**
     * @param  list<CreditNotePart>  $parts
     * @return array<string, mixed>
     */
    private function creditGroup(string $key, array $parts): array
    {
        $outcome = $this->runner()->run($this->spec(key: $key), function (TransactionContext $context) use ($parts): Receipt {
            $receipt = $this->participant()->issue($context, $this->group($parts));

            return self::receipt(['creditNoteRef' => $receipt->creditNoteRef, 'partRefs' => $receipt->partRefs]);
        });
        self::assertTrue($outcome->accepted, json_encode($outcome->body, JSON_THROW_ON_ERROR));

        return $outcome->body;
    }

    /** @param list<CreditNotePart> $parts */
    private function group(array $parts): IssueCreditNoteGroup
    {
        return new IssueCreditNoteGroup(scope: $this->account(), creditNoteRef: 'host-credit', mode: CreditNoteMode::OpenOnly,
            parts: $parts, reason: 'goodwill');
    }

    private function part(string $invoiceRef, string $obligationRef): CreditNotePart
    {
        return new CreditNotePart(invoiceRef: $invoiceRef, lines: [new CreditNoteLine($obligationRef, Money::parse('25.00', 'RUB'))]);
    }

    private function participant(): CorrectionCreditParticipants
    {
        return $this->application()->make(CorrectionCreditParticipants::class);
    }
}

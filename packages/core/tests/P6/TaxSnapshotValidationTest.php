<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\P6\Support\DocumentsTestCase;
use Billium\Documents\Data\AttachTaxSnapshot;
use Billium\Documents\Data\CreateDocumentDraft;
use Billium\Documents\Data\DocumentFinalizationView;
use Billium\Documents\Data\DocumentLineBasis;
use Billium\Documents\Data\DocumentTaxLineSnapshot;
use Billium\Documents\Data\DocumentTaxSnapshot;
use Billium\Documents\Enums\DiscountTaxOrder;
use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\TaxConvention;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Obligations\RecordObligation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * A tax snapshot comes from an outside resolver, so finalization checks it against the draft before it is attached:
 * every refusal below names the exact conflict, and a draft with an inconsistent currency or a negative line never starts.
 */
#[Group('pg')]
final class TaxSnapshotValidationTest extends DocumentsTestCase
{
    private const int NET = 10000;

    /** @return iterable<string, array{TaxConvention, array<string, int>, list<array<string, int|string>>, ProblemCode, string}> */
    public static function conflicts(): iterable
    {
        $line = ['lineRef' => 'line-1', 'netMinor' => self::NET, 'taxMinor' => 2000, 'grossMinor' => self::NET + 2000];
        yield 'currency exponent' => [TaxConvention::Exclusive, ['currencyExponent' => 3], [$line], ProblemCode::MoneyCurrencyMismatch, 'tax_snapshot_currency_conflict'];
        yield 'subtotal plus tax differs from total' => [TaxConvention::Exclusive, ['totalMinor' => self::NET + 2001], [$line], ProblemCode::ValidationInvalid, 'tax_snapshot_total_conflict'];
        yield 'extra line' => [TaxConvention::Exclusive, ['subtotalMinor' => 2 * self::NET, 'taxMinor' => 4000, 'totalMinor' => 2 * self::NET + 4000],
            [$line, ['lineRef' => 'line-2'] + $line], ProblemCode::ValidationInvalid, 'tax_snapshot_line_count_conflict'];
        yield 'negative line net' => [TaxConvention::Exclusive, ['subtotalMinor' => -1, 'taxMinor' => 1, 'totalMinor' => 0],
            [['netMinor' => -1, 'taxMinor' => 1, 'grossMinor' => 0] + $line], ProblemCode::ValidationInvalid, 'tax_snapshot_line_math_conflict'];
        yield 'negative line tax' => [TaxConvention::Exclusive, ['taxMinor' => -1, 'totalMinor' => self::NET - 1],
            [['taxMinor' => -1, 'grossMinor' => self::NET - 1] + $line], ProblemCode::ValidationInvalid, 'tax_snapshot_line_math_conflict'];
        yield 'line net differs from the draft' => [TaxConvention::Exclusive, ['subtotalMinor' => self::NET + 1, 'totalMinor' => self::NET + 2001],
            [['netMinor' => self::NET + 1, 'grossMinor' => self::NET + 2001] + $line], ProblemCode::ValidationInvalid, 'tax_snapshot_line_amount_conflict'];
        yield 'inclusive line matching neither draft amount' => [TaxConvention::Inclusive, ['subtotalMinor' => 9000, 'taxMinor' => 1800, 'totalMinor' => 10800],
            [['netMinor' => 9000, 'taxMinor' => 1800, 'grossMinor' => 10800] + $line], ProblemCode::ValidationInvalid, 'tax_snapshot_inclusive_amount_conflict'];
        yield 'tax on a none-declared draft' => [TaxConvention::NoneDeclared, [], [$line], ProblemCode::ValidationInvalid, 'none_declared_requires_zero_tax_and_reason'];
    }

    /**
     * @param  array<string, int>  $totals  overrides of the consistent snapshot totals
     * @param  list<array<string, int|string>>  $lines
     */
    #[DataProvider('conflicts')]
    public function test_an_inconsistent_snapshot_is_refused_with_its_conflict(TaxConvention $convention, array $totals, array $lines, ProblemCode $problem, string $detail): void
    {
        $started = $this->started($convention);
        $snapshot = new DocumentTaxSnapshot(
            taxSnapshotRef: 'snap-a',
            taxPolicyVersion: 'v1',
            taxPoint: '2026-11-19',
            convention: $convention,
            currency: 'RUB',
            currencyExponent: $totals['currencyExponent'] ?? 2,
            subtotalMinor: $totals['subtotalMinor'] ?? self::NET,
            taxMinor: $totals['taxMinor'] ?? 2000,
            totalMinor: $totals['totalMinor'] ?? self::NET + 2000,
            lines: array_map(static fn (array $l): DocumentTaxLineSnapshot => new DocumentTaxLineSnapshot(
                lineRef: (string) $l['lineRef'], taxCode: 'VAT20', rateDecimal: '0.20',
                netMinor: (int) $l['netMinor'], taxMinor: (int) $l['taxMinor'], grossMinor: (int) $l['grossMinor'],
            ), $lines),
            inputDigest: $started->inputDigest,
            evidenceDigest: self::digest('d'),
            resolver: 'fixture-tax-port',
            resolverVersion: '1.0',
            noTaxReason: $convention === TaxConvention::NoneDeclared ? 'zero_rated_supply' : null,
        );
        try {
            $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot($this->docScope(), 'fin-a', $started->finalizationRevision, $started->inputDigest, $snapshot));
            self::fail('inconsistent snapshot attached');
        } catch (ProblemException $e) {
            self::assertSame([$problem, 'billium.documents.'.$detail], [$e->problem, $e->detail]);
        }
    }

    public function test_a_draft_with_a_foreign_exponent_or_a_negative_line_never_starts(): void
    {
        $this->registerCompleteProfile();
        foreach ([
            'exponent' => [3, self::NET, ProblemCode::MoneyScaleInvalid],
            'negative line' => [2, -1, ProblemCode::ValidationInvalid],
        ] as $case => [$exponent, $net, $problem]) {
            try {
                $this->createDraftOf('fin-'.$case, TaxConvention::Exclusive, $exponent, $net);
                self::fail($case);
            } catch (ProblemException $e) {
                self::assertSame($problem, $e->problem, $case);
            }
        }
        self::assertSame(0, $this->rows('billium_document_finalization'));
    }

    private function started(TaxConvention $convention): DocumentFinalizationView
    {
        $this->registerCompleteProfile();
        $this->ledger()->recordObligation(new RecordObligation('rec-ob-a', $this->account(), 'ob-a', 'basis-a', 'cust-a', 'comp-1',
            new Money(self::NET, Currency::of('RUB')), self::digest('b')));
        $this->ledger()->createInvoice(new CreateInvoice('create-inv-a', $this->account(), 'inv-a', 'cust-a',
            [new InvoiceLineInput('ob-a', 0, ['name' => 'Service'])], ['buyer' => 'Buyer Corp']));
        $this->createDraftOf('fin-a', $convention, 2, self::NET);

        return $this->finalizations()->startFinalization($this->docScope(), 'fin-a');
    }

    private function createDraftOf(string $ref, TaxConvention $convention, int $exponent, int $net): void
    {
        $this->finalizations()->createDraft(new CreateDocumentDraft(
            scope: $this->docScope(),
            finalizationRef: $ref,
            documentType: DocumentType::Invoice,
            economicRef: 'inv-a',
            basisRevision: 1,
            basisDigest: self::digest('c'),
            profileRef: 'prof-default',
            profileVersion: 1,
            currency: 'RUB',
            currencyExponent: $exponent,
            convention: $convention,
            roundingMode: 'half_up',
            discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
            lines: [new DocumentLineBasis(lineRef: 'line-1', obligationRef: 'ob-a', componentRef: 'comp-1', quantity: '1', unitPriceDecimal: '100.00',
                netMinor: $net, discountMinor: 0, taxCode: 'VAT20', taxRate: '0.20', description: 'Service charge')],
            buyerSnapshot: ['legalName' => 'Buyer Corp', 'taxId' => 'BUYER-TAX-1'],
            termsSnapshot: ['paymentTerm' => 'NET30'],
        ));
    }
}

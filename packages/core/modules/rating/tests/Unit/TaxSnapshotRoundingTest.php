<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Unit;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Data\Pricing\CommitmentTerms;
use Billium\Rating\Internal\Tax\TaxCalculator;
use PHPUnit\Framework\TestCase;

final class TaxSnapshotRoundingTest extends TestCase
{
    public function test_tax_rounding_conserves_signed_residual_and_breaks_ties_by_key(): void
    {
        $calculator = new TaxCalculator;
        foreach (['0.10' => '1', '-0.10' => '-1'] as $amount => $expectedTax) {
            $lines = $calculator->calculate(amounts: ['c' => $amount, 'b' => $amount, 'a' => $amount], mode: 'exclusive', rate: '0.0725', exp: 2, rounding: 'half_up');
            self::assertSame($expectedTax, $lines['a']['tax']);
            self::assertSame($expectedTax, $lines['b']['tax']);
            self::assertSame('0', $lines['c']['tax']);
            foreach ($lines as $line) {
                self::assertTrue(is_numeric($line['net']));
                self::assertTrue(is_numeric($line['tax']));
                self::assertSame($line['gross'], bcadd($line['net'], $line['tax'], 0));
            }
        }
    }

    public function test_tax_inclusive_and_currency_exponents(): void
    {
        $calculator = new TaxCalculator;
        self::assertSame(['net' => '935', 'tax' => '65', 'gross' => '1000', 'residual' => '1'], $calculator->calculate(amounts: ['a' => '10.00'], mode: 'inclusive', rate: '0.07', exp: 2, rounding: 'half_up')['a']);
        self::assertSame('1000', $calculator->calculate(amounts: ['a' => '1.0005'], mode: 'exclusive', rate: '0.05', exp: 3, rounding: 'half_even')['a']['net']);
        self::assertSame('1001', $calculator->calculate(amounts: ['a' => '1.0005'], mode: 'exclusive', rate: '0.05', exp: 3, rounding: 'half_up')['a']['net']);
        $jpy = $calculator->calculate(amounts: ['a' => '1', 'b' => '1', 'c' => '1'], mode: 'exclusive', rate: '0.5', exp: 0, rounding: 'half_up');
        self::assertSame(['1', '1', '0'], array_column($jpy, 'tax'));
    }

    public function test_tax_refuses_mixed_signs(): void
    {
        $this->expectException(ProblemException::class);
        $this->expectExceptionMessage('billium.rating.mixed_sign_allocation');
        (new TaxCalculator)->calculate(amounts: ['a' => '1', 'b' => '-1'], mode: 'exclusive', rate: '0.2', exp: 2, rounding: 'half_up');
    }

    public function test_tax_refuses_no_tax_without_reason(): void
    {
        $this->expectExceptionMessage('billium.rating.terms_invalid');
        (new TaxCalculator)->calculate(amounts: ['a' => '1'], mode: 'none_declared', rate: '0', exp: 2, rounding: 'half_up');
    }

    public function test_tax_refuses_inclusive_commitment_composition(): void
    {
        $commitment = new CommitmentTerms(sellerRef: 'seller', billingAccountRef: 'customer', componentRef: 'api', currency: 'USD', periodRef: 'p1', minimumMinor: 1, maximumMinor: null, consumedByAlreadyBilled: true, appliesAt: 'period_final');
        $this->expectExceptionMessage('billium.rating.unsupported_combination');
        (new TaxCalculator)->calculate(amounts: ['a' => '1'], mode: 'inclusive', rate: '0.2', exp: 2, rounding: 'half_up', commitment: $commitment);
    }

    public function test_tax_fixture_preserves_positive_and_negative_allocations(): void
    {
        $path = dirname(__DIR__, 6).'/fixtures/vectors/rating/tax/residual-corrections.json';
        /** @var array{given: array{example: array{jurisdiction: string, taxCode: string, resolver: string, resolverVersion: string, inputDigest: string, rate: string, mode: string, exponent: int, rounding: string, cases: list<array{lines: array{a: string, b: string, c: string}, tax: list<string>}>}}} $vector */
        $vector = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $example = $vector['given']['example'];
        foreach ($example['cases'] as $case) {
            $result = (new TaxCalculator)->calculate(amounts: $case['lines'], mode: $example['mode'], rate: $example['rate'], exp: $example['exponent'], rounding: $example['rounding']);
            self::assertSame($case['tax'], array_column($result, 'tax'));
        }
    }
}

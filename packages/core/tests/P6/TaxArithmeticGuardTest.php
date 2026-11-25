<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Documents\Data\CreditNoteLineRequest;
use Billium\Documents\Data\DocumentTaxCorrectionPolicy;
use Billium\Documents\Data\TaxArithmeticLineInput;
use Billium\Documents\Enums\DiscountTaxOrder;
use Billium\Documents\Enums\TaxConvention;
use Billium\Documents\Enums\TaxCorrectionTreatment;
use Billium\Documents\Internal\Tax\BcDocumentTaxArithmetic;
use Billium\Documents\Internal\Tax\PartialCreditAllocator;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Line arithmetic and partial-credit allocation refuse inputs that would produce a negative or empty money line. */
final class TaxArithmeticGuardTest extends TestCase
{
    /** @return iterable<string, array{Closure(): mixed}> */
    public static function refused(): iterable
    {
        $line = static fn (string $amount, string $discount = '0', string $rate = '0.20'): TaxArithmeticLineInput => new TaxArithmeticLineInput('line-1', $amount, $discount, $rate, 'VAT20');
        $calculate = static fn (TaxArithmeticLineInput $input, TaxConvention $convention = TaxConvention::Exclusive, DiscountTaxOrder $order = DiscountTaxOrder::DiscountBeforeTax, ?string $reason = null): mixed => (new BcDocumentTaxArithmetic)->calculate([$input], $convention, 'RUB', 'half_up', $order, $reason);
        yield 'discount above the amount' => [static fn (): mixed => $calculate($line('1.00', '1.01'))];
        yield 'no-tax reason on a taxed convention' => [static fn (): mixed => $calculate($line('1.00'), reason: 'zero_rated_supply')];
        yield 'pre-discount inclusive tax above the payable gross' => [static fn (): mixed => $calculate($line('10.00', '9.99'), TaxConvention::Inclusive, DiscountTaxOrder::TaxBeforeDiscount)];
        yield 'correction rate beyond eight decimals' => [static fn (): mixed => self::allocate(new CreditNoteLineRequest('line-1', '1', 100), '0.123456789')];
        yield 'zero credit on an exhausted line' => [static fn (): mixed => self::allocate(new CreditNoteLineRequest('line-1', '1', 0), '0.20', prior: 1200)];
    }

    /** @param Closure(): mixed $call */
    #[DataProvider('refused')]
    public function test_an_impossible_money_line_is_refused(Closure $call): void
    {
        try {
            $call();
            self::fail('impossible money line accepted');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }
    }

    /** @return array{netMinor: int, taxMinor: int, grossMinor: int} */
    private static function allocate(CreditNoteLineRequest $request, string $rate, int $prior = 0): array
    {
        $policy = new DocumentTaxCorrectionPolicy(policyRef: 'pol', policyVersion: 'v1', treatment: TaxCorrectionTreatment::OriginalRate,
            selectedRateDecimal: $rate, taxPoint: '2026-11-19', roundingPolicyVersion: 'half_up/1',
            inputDigest: 'sha256:'.str_repeat('1', 64), outputDigest: 'sha256:'.str_repeat('2', 64), resolverVersion: '1.0');

        return (new PartialCreditAllocator(new BcDocumentTaxArithmetic))->allocateLine($request, 1000, 200, 1200,
            $prior === 0 ? 0 : 1000, $prior === 0 ? 0 : 200, $prior, 'half_up/1', 'RUB', 2, $policy);
    }
}

<?php

declare(strict_types=1);

namespace Billium\Documents\Internal\Tax;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Documents\Contracts\DocumentTaxArithmetic;
use Billium\Documents\Data\CreditNoteLineRequest;
use Billium\Documents\Data\DocumentTaxCorrectionPolicy;
use Billium\Documents\Data\TaxArithmeticLineInput;
use Billium\Documents\Enums\DiscountTaxOrder;
use Billium\Documents\Enums\TaxConvention;
use Billium\Documents\Enums\TaxCorrectionTreatment;
use Billium\Documents\Internal\Math\Dec;
use Billium\Documents\Internal\Math\IntegerRounding;
use Billium\Documents\Internal\Support\RowCast;
use InvalidArgumentException;
use Packman\Attributes\Internal;

/**
 * Pure zero-float partial credit line allocator honoring frozen original policy and exact final remainder conservation.
 */
#[Internal]
final readonly class PartialCreditAllocator
{
    public function __construct(private DocumentTaxArithmetic $arithmetic) {}

    public function validatePolicy(DocumentTaxCorrectionPolicy $policy): void
    {
        RowCast::bounded($policy->policyRef, 'policyRef');
        RowCast::bounded($policy->policyVersion, 'policyVersion');
        RowCast::bounded($policy->taxPoint, 'taxPoint', 32);
        RowCast::bounded($policy->roundingPolicyVersion, 'roundingPolicyVersion', 64);
        RowCast::bounded($policy->inputDigest, 'inputDigest');
        RowCast::bounded($policy->outputDigest, 'outputDigest');
        RowCast::bounded($policy->resolverVersion, 'resolverVersion', 64);

        try {
            $rate = Dec::parse($policy->selectedRateDecimal);
        } catch (InvalidArgumentException $error) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'invalid correction policy rate: '.$error->getMessage());
        }
        if ($rate->sign() < 0 || $rate->scale > 8) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'invalid correction policy rate scale or sign');
        }
        self::roundingModeFromPolicy($policy->roundingPolicyVersion);
    }

    /**
     * @return array{netMinor: int, taxMinor: int, grossMinor: int}
     */
    public function allocateLine(
        CreditNoteLineRequest $request,
        int $origNetMinor,
        int $origTaxMinor,
        int $origGrossMinor,
        int $priorNetMinor,
        int $priorTaxMinor,
        int $priorGrossMinor,
        string $originalRoundingPolicyVersion,
        string $currency,
        int $currencyExponent,
        DocumentTaxCorrectionPolicy $policy,
    ): array {
        $this->validatePolicy($policy);
        if ($request->requestedGrossMinor <= 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'credit note line amount must be positive');
        }
        Dec::parse($request->creditedQuantity);

        $remGross = $origGrossMinor - $priorGrossMinor;
        $remNet = $origNetMinor - $priorNetMinor;
        $remTax = $origTaxMinor - $priorTaxMinor;

        if ($request->requestedGrossMinor > $remGross) {
            throw new ProblemException(ProblemCode::RefundCapacityExceeded, 'billium.documents.credit_exceeds_line_basis');
        }

        if ($policy->treatment === TaxCorrectionTreatment::OriginalRate) {
            // Final partial credit exhausting the remaining line receives the exact remainder.
            if ($request->requestedGrossMinor === $remGross) {
                return [
                    'netMinor' => $remNet,
                    'taxMinor' => $remTax,
                    'grossMinor' => $remGross,
                ];
            }

            $roundingMode = self::roundingModeFromPolicy($originalRoundingPolicyVersion);
            $gross = $request->requestedGrossMinor;
            if ($origGrossMinor <= 0) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'invalid original line gross basis');
            }
            $num = bcmul((string) $gross, (string) $origTaxMinor, 0);
            $den = (string) $origGrossMinor;
            $tax = (int) IntegerRounding::divide($num, $den, $roundingMode);
            if ($tax > $remTax) {
                $tax = $remTax;
            }
            $net = $gross - $tax;
            if ($net > $remNet) {
                $net = $remNet;
                $tax = $gross - $net;
            }

            return [
                'netMinor' => $net,
                'taxMinor' => $tax,
                'grossMinor' => $gross,
            ];
        }

        // CurrentLegalRate / OtherSupported uses host policy selectedRateDecimal + roundingPolicyVersion.
        $scalePow = bcpow('10', (string) $currencyExponent, 0);
        $amountDecimal = bcdiv((string) $request->requestedGrossMinor, $scalePow, $currencyExponent);
        $roundingMode = self::roundingModeFromPolicy($policy->roundingPolicyVersion);
        $calc = $this->arithmetic->calculate(
            lines: [new TaxArithmeticLineInput(
                lineRef: $request->originalInvoiceLineId,
                amountDecimal: $amountDecimal,
                discountDecimal: '0',
                rateDecimal: $policy->selectedRateDecimal,
                taxCode: $request->taxCode,
            )],
            convention: TaxConvention::Inclusive,
            currency: $currency,
            roundingMode: $roundingMode,
            order: DiscountTaxOrder::DiscountBeforeTax,
        );
        $line = $calc->lines[0];

        return [
            'netMinor' => $line->netMinor,
            'taxMinor' => $line->taxMinor,
            'grossMinor' => $line->grossMinor,
        ];
    }

    private static function roundingModeFromPolicy(string $policyVersion): string
    {
        $candidate = explode('/', strtolower(trim($policyVersion)))[0];
        if (! in_array($candidate, ['down', 'up', 'half_up', 'half_even'], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'unsupported rounding policy version: '.$policyVersion);
        }

        return $candidate;
    }
}

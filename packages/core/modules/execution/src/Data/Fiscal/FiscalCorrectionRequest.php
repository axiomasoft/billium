<?php

declare(strict_types=1);

namespace Billium\Execution\Data\Fiscal;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Data\ExecutionScope;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FiscalCorrectionRequest
{
    /**
     * @param  array<string, mixed>  $receiptPayload
     * @param  array<string, mixed>  $exposureProvenance
     */
    public function __construct(
        public ExecutionScope $scope,
        public string $parentFiscalIntentRef,
        public Money $correctionAmount,
        public string $reason,
        public string $correctionBasisRef,
        public array $receiptPayload = [],
        public bool $exceptional = false,
        public array $exposureProvenance = [],
    ) {
        foreach ([$parentFiscalIntentRef, $reason, $correctionBasisRef] as $field) {
            if ($field === '' || strlen($field) > 200) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Fiscal correction references and reason must be non-empty bounded strings');
            }
        }
        if ($correctionAmount->minor <= 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Fiscal correction amount must be positive');
        }
        if ($exceptional && $exposureProvenance === []) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Exceptional fiscal correction requires exposure provenance');
        }
    }
}

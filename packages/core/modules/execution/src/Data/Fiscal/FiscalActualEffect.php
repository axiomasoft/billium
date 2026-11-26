<?php

declare(strict_types=1);

namespace Billium\Execution\Data\Fiscal;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Enums\Fiscal\FiscalOperationKind;
use Billium\Execution\Enums\Fiscal\FiscalReceiptKind;
use Packman\Attributes\Api;

/** Authenticated actual provider effect used as fiscal input; never bounded applied totals. */
#[Api(since: '1.0')]
final readonly class FiscalActualEffect
{
    /**
     * @param  array<string, mixed>  $exposureProvenance
     * @param  array<string, mixed>  $receiptPayload
     */
    public function __construct(
        public ExecutionScope $scope,
        public string $executionProfile,
        public string $economicEffectRef,
        public string $rootOperationRef,
        public string $operationRef,
        public FiscalOperationKind $operationKind,
        public FiscalReceiptKind $receiptKind,
        public Money $actualAmount,
        public string $merchantRevisionRef,
        public string $merchantAccountRef,
        public string $providerEvidenceRef,
        public bool $exceptional = false,
        public array $exposureProvenance = [],
        public array $receiptPayload = [],
        public bool $confirmedActual = true,
    ) {
        if (! in_array($executionProfile, ['standalone-billium', 'remote-billium', 'embedded-commerz'], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Unsupported execution profile');
        }
        foreach ([$economicEffectRef, $rootOperationRef, $operationRef, $merchantRevisionRef, $merchantAccountRef, $providerEvidenceRef] as $field) {
            if ($field === '' || strlen($field) > 200) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Fiscal actual effect references must be non-empty bounded strings');
            }
        }
        if ($actualAmount->minor <= 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Fiscal actual effect amount must be positive');
        }
        if ($exceptional && $exposureProvenance === []) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Exceptional fiscal effect requires non-empty exposure provenance');
        }
    }
}

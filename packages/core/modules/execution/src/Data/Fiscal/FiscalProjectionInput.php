<?php

declare(strict_types=1);

namespace Billium\Execution\Data\Fiscal;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Enums\Fiscal\FiscalReceiptKind;
use Packman\Attributes\Api;

/** Projection-only fiscal outcome recorded for embedded-commerz; never dispatches to a fiscal provider. */
#[Api(since: '1.0')]
final readonly class FiscalProjectionInput
{
    /**
     * @param  array<string, mixed>  $exposureProvenance
     * @param  array<string, mixed>  $sourcePayload
     */
    public function __construct(
        public ExecutionScope $scope,
        public string $sourceAuthority,
        public string $economicEffectRef,
        public string $rootOperationRef,
        public string $operationRef,
        public FiscalReceiptKind $receiptKind,
        public int $correctionRevision,
        public Money $actualAmount,
        public string $fiscalStatus,
        public ?string $externalFiscalRef = null,
        public bool $exceptional = false,
        public array $exposureProvenance = [],
        public array $sourcePayload = [],
    ) {
        foreach ([$sourceAuthority, $economicEffectRef, $rootOperationRef, $operationRef] as $field) {
            if ($field === '' || strlen($field) > 200) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Fiscal projection references must be non-empty bounded strings');
            }
        }
        if ($correctionRevision < 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'correctionRevision must be non-negative');
        }
        if ($actualAmount->minor <= 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Fiscal projection amount must be positive');
        }
        if (! in_array($fiscalStatus, ['pending', 'registered', 'rejected_terminal', 'unknown'], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Unsupported fiscal projection status');
        }
        if ($exceptional && $exposureProvenance === []) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Exceptional fiscal projection requires exposure provenance');
        }
    }
}

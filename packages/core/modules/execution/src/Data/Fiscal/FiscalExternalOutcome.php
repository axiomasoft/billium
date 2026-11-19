<?php

declare(strict_types=1);

namespace Billium\Execution\Data\Fiscal;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Enums\Fiscal\FiscalReceiptKind;
use Packman\Attributes\Api;

/** Authenticated external fiscal outcome evidence supplied by an execution-owning host for embedded-commerz projection. */
#[Api(since: '1.0')]
final readonly class FiscalExternalOutcome
{
    /** @param array<string, mixed> $sourcePayload */
    public function __construct(
        public string $operationRef,
        public string $economicEffectRef,
        public FiscalReceiptKind $receiptKind,
        public string $fiscalStatus,
        public string $evidenceDigest,
        public ?string $externalFiscalRef = null,
        public int $correctionRevision = 0,
        public array $sourcePayload = [],
    ) {
        foreach ([$operationRef, $economicEffectRef] as $field) {
            if ($field === '' || strlen($field) > 200) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'External fiscal outcome references must be non-empty bounded strings');
            }
        }
        if ($correctionRevision < 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'correctionRevision must be non-negative');
        }
        if ($correctionRevision === 0 && ! in_array($receiptKind, [FiscalReceiptKind::Sale, FiscalReceiptKind::Refund], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Base external fiscal outcome requires sale or refund receiptKind');
        }
        if ($correctionRevision > 0 && ! in_array($receiptKind, [FiscalReceiptKind::CorrectionSale, FiscalReceiptKind::CorrectionRefund], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Correction external fiscal outcome requires a correction receiptKind');
        }
        if (! in_array($fiscalStatus, ['pending', 'registered', 'rejected_terminal', 'unknown'], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Unsupported external fiscal outcome status');
        }
        if (preg_match('/^sha256:[0-9a-f]{64}$/', $evidenceDigest) !== 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'External fiscal outcome requires a valid sha256 evidenceDigest');
        }
        if ($fiscalStatus === 'registered') {
            if ($externalFiscalRef === null || trim($externalFiscalRef) === '' || strlen($externalFiscalRef) > 200) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Registered external fiscal outcome requires a non-empty bounded externalFiscalRef');
            }
        } elseif ($externalFiscalRef !== null && (trim($externalFiscalRef) === '' || strlen($externalFiscalRef) > 200)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'externalFiscalRef must be null or a non-empty bounded string');
        }
    }
}

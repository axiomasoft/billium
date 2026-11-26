<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Enums\CaptureMode;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class CapabilityDetails
{
    public function __construct(
        public string $version,
        public bool $initiateWithIdempotency,
        public bool $initiateWithoutIdempotency,
        public ?string $idempotencyKeyScope,
        public ?int $idempotencyRetentionSeconds,
        public bool $lookupByProviderId,
        public string $lookupVisibility,
        public ?int $lookupRetentionSeconds,
        public CaptureMode $captureMode,
        public bool $multipleRefund,
        public bool $refundLookup,
        public bool $signedCallbacks,
        public bool $callbackEventIdentity,
        public bool $callbackEventLookup,
        public bool $asynchronousStates,
        public bool $settlementListing,
        public bool $settlementExport,
        public bool $disputes,
        public bool $savedMethod,
        public bool $recurringConsent,
        public bool $fiscalPassthrough,
        public string $lookupNamespace,
        public string $referenceSemantics,
        public string $qualificationManifestRef,
    ) {
        if ($version === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'version must not be empty');
        }
        if ($idempotencyKeyScope === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'idempotencyKeyScope must be null or nonempty');
        }
        if ($lookupVisibility === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'lookupVisibility must not be empty');
        }
        if ($lookupNamespace === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'lookupNamespace must not be empty');
        }
        if ($referenceSemantics === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'referenceSemantics must not be empty');
        }
        if ($qualificationManifestRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'qualificationManifestRef must not be empty');
        }
        if (($initiateWithIdempotency && ($idempotencyKeyScope === null || $idempotencyRetentionSeconds === null)) || (! $initiateWithIdempotency && ($idempotencyKeyScope !== null || $idempotencyRetentionSeconds !== null))) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Create idempotency requires explicit scope and retention');
        }
        if (($idempotencyRetentionSeconds !== null && $idempotencyRetentionSeconds <= 0) || ($lookupRetentionSeconds !== null && $lookupRetentionSeconds <= 0)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Qualified retention must be positive');
        }
        if (! in_array($lookupVisibility, ['none', 'eventual', 'complete'], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Lookup visibility is none, eventual or complete');
        }
        if ($callbackEventLookup && ! $callbackEventIdentity) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Callback event lookup requires event identity');
        }
    }
}

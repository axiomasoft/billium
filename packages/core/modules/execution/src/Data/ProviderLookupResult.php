<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Enums\ProviderLookupState;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ProviderLookupResult
{
    public function __construct(
        public ProviderLookupState $state,
        public ?ProviderEvidence $evidence,
        public ?MaterialSnapshot $snapshot,
        public ?AbsenceCertificate $absence,
        public ?string $reasonCode,
        public ?DateTimeImmutable $retryAt,
    ) {
        if ($reasonCode === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'reasonCode must be null or nonempty');
        }
        $material = in_array($state, [ProviderLookupState::Found, ProviderLookupState::Pending], true);
        if ($material && ($evidence === null || $snapshot === null || $absence !== null)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Found and pending require evidence and material snapshot without absence');
        }
        if (! $material && $snapshot !== null) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Only found and pending carry material snapshots');
        }
        if (($state === ProviderLookupState::CertifiedAbsent) !== ($absence !== null)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Certified absence requires a certificate, which still needs host verification');
        }
        if (in_array($state, [ProviderLookupState::Unavailable, ProviderLookupState::Ambiguous, ProviderLookupState::Unsupported], true) && $reasonCode === null) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Nonmaterial lookup result requires a reason code');
        }
        if ($snapshot !== null && $snapshot->merchantAccountRef !== $evidence->merchantAccountRef) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Evidence and snapshot account differ');
        }
    }
}

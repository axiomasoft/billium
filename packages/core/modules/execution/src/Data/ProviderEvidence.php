<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ProviderEvidence
{
    /**
     * @param  array<string,mixed>  $sanitizedPayload
     */
    public function __construct(
        public string $evidenceRef,
        public ExecutionScope $scope,
        public string $providerId,
        public string $merchantAccountRef,
        public ?string $providerEventId,
        public string $payloadDigest,
        public string $signatureVerdict,
        public string $handlerContractVersion,
        public DateTimeImmutable $receivedAt,
        public array $sanitizedPayload,
        public ?string $securePayloadRef,
    ) {
        if ($evidenceRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'evidenceRef must not be empty');
        }
        if ($providerId === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'providerId must not be empty');
        }
        if ($merchantAccountRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'merchantAccountRef must not be empty');
        }
        if ($providerEventId === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'providerEventId must be null or nonempty');
        }
        if ($payloadDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'payloadDigest must not be empty');
        }
        if ($signatureVerdict === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'signatureVerdict must not be empty');
        }
        if ($handlerContractVersion === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'handlerContractVersion must not be empty');
        }
        if ($securePayloadRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'securePayloadRef must be null or nonempty');
        }
    }
}

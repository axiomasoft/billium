<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use LogicException;
use Packman\Attributes\Api;
use SensitiveParameter;

/** Exact first-egress bytes from the qualified historical adapter. Persist only the commitment. */
#[Api(since: '1.0')]
final readonly class OriginalProviderRequest
{
    public function __construct(
        public ExecutionScope $scope,
        public string $operationRef,
        public string $attemptRef,
        public string $providerReference,
        public string $providerId,
        public string $merchantAccountRef,
        public string $driverId,
        public string $contractVersion,
        public string $providerKey,
        #[SensitiveParameter] private string $body,
        public string $originalEgressRef,
    ) {
        foreach ([$operationRef, $attemptRef, $providerReference, $providerId, $merchantAccountRef, $driverId, $contractVersion, $providerKey, $originalEgressRef] as $field) {
            if ($field === '') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Missing original transport identity');
            }
        }
    }

    public function body(): string
    {
        return $this->body;
    }

    public function digest(): string
    {
        return (new Codec)->digest(['schema' => 'billium.original-provider-request.v1', 'scope' => get_object_vars($this->scope),
            'operation' => $this->operationRef, 'attempt' => $this->attemptRef, 'reference' => $this->providerReference,
            'provider' => $this->providerId, 'account' => $this->merchantAccountRef, 'driver' => $this->driverId,
            'contract' => $this->contractVersion, 'key' => $this->providerKey, 'bodySha256' => hash('sha256', $this->body),
            'originalEgress' => $this->originalEgressRef]);
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['originalEgressRef' => $this->originalEgressRef, 'requestDigest' => $this->digest()];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Original provider request bytes are ephemeral');
    }
}

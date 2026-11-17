<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class DriverRequest
{
    public function __construct(
        public DispatchDecision $permit,
        public FrozenBinding $binding,
        public CapabilitySnapshot $capabilities,
        public Money $amount,
        public string $providerReference,
        public ResolvedCredentials $credentials,
    ) {
        if ($providerReference === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'providerReference must not be empty');
        }
    }
}

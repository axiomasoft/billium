<?php

declare(strict_types=1);

namespace Billium\Execution\Data\Fiscal;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Enums\Fiscal\FiscalCallbackTrustStrategy;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FiscalCallbackEnvelope
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public ExecutionScope $scope,
        public string $externalId,
        public string $driverId,
        public string $driverContractVersion,
        public string $externalEventId,
        public FiscalCallbackTrustStrategy $trustStrategy,
        public string $signature,
        public array $payload,
        public DateTimeImmutable $receivedAt,
    ) {
        foreach ([$externalId, $driverId, $driverContractVersion, $externalEventId] as $field) {
            if ($field === '' || strlen($field) > 200) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Fiscal callback references must be non-empty bounded strings');
            }
        }
    }
}

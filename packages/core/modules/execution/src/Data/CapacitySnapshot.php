<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class CapacitySnapshot
{
    public function __construct(
        public string $rootOperationRef,
        public int $version,
        public Money $authorizedLimit,
        public Money $confirmedCapture,
        public Money $confirmedRefund,
        public Money $releasedAuthorization,
        public Money $heldCapture,
        public Money $heldVoid,
        public Money $heldRefund,
        public bool $financialHold,
    ) {
        if ($rootOperationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'rootOperationRef must not be empty');
        }
    }
}

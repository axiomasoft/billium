<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Reducers;

use Packman\Attributes\Internal;

#[Internal]
final readonly class CustodyArrivalDecision
{
    public function __construct(
        public string $status,
        public ?string $requireRecoveryReason,
    ) {}
}

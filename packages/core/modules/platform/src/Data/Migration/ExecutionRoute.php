<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Migration;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ExecutionRoute
{
    public function __construct(public string $executorRef, public int $routingEpoch)
    {
        if (trim($executorRef) === '' || strlen($executorRef) > 200 || $routingEpoch < 0 || $routingEpoch > 9007199254740991) {
            throw new InvalidArgumentException('Invalid execution route');
        }
    }

    public function matches(self $other): bool
    {
        return $this->executorRef === $other->executorRef && $this->routingEpoch === $other->routingEpoch;
    }
}

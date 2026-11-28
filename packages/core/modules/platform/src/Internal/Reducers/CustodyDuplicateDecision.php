<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Reducers;

use Billium\Platform\Data\Inbox\CustodyResult;
use Packman\Attributes\Internal;

#[Internal]
final readonly class CustodyDuplicateDecision
{
    public function __construct(
        public bool $recordConflict,
        public CustodyResult $result,
    ) {}
}

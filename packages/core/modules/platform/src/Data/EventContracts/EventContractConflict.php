<?php

declare(strict_types=1);

namespace Billium\Platform\Data\EventContracts;

use Billium\Contracts\Attributes\Api;

#[Api]
final readonly class EventContractConflict
{
    public const string OWNER_UNKNOWN = 'owner_unknown';

    public const string CHANGED_CONTRACT = 'changed_contract';

    public function __construct(public string $key, public string $ownerModuleId, public string $reason) {}
}

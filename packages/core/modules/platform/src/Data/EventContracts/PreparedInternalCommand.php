<?php

declare(strict_types=1);

namespace Billium\Platform\Data\EventContracts;

use Billium\Contracts\Attributes\Api;
use InvalidArgumentException;

#[Api]
final readonly class PreparedInternalCommand
{
    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $destinations
     */
    public function __construct(
        public string $commandRef,
        public string $businessRef,
        public string $ownerModuleId,
        public string $commandType,
        public string $commandSchema,
        public array $payload,
        public array $destinations,
    ) {
        foreach ([$commandRef, $businessRef, $ownerModuleId, $commandType] as $value) {
            if ($value === '' || strlen($value) > 200) {
                throw new InvalidArgumentException('Command identity must be bounded and nonempty');
            }
        }
        if (preg_match('/^billium\.command\.v[0-9]+$/D', $commandSchema) !== 1 || $destinations === []) {
            throw new InvalidArgumentException('Internal commands need a command schema and destinations');
        }
    }
}

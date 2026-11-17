<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use InvalidArgumentException;
use Packman\Attributes\Api;

/** Everything boot compares with the descriptor set: desired config, port capabilities, installed checkpoints. */
#[Api(since: '1.0')]
final readonly class CompatibilityInput
{
    /**
     * @param  list<string>  $enabled  desired module ids
     * @param  list<string>  $providedPorts  host port bindings present
     * @param  array<string, InstalledCheckpoint>  $checkpoints
     * @param  list<string>  $modulesWithHistory
     * @param  list<string>  $historicalReaders  modules whose historical readers are installed
     */
    public function __construct(
        public string $composition,
        public array $enabled,
        public array $providedPorts = [],
        public array $checkpoints = [],
        public array $modulesWithHistory = [],
        public array $historicalReaders = [],
    ) {}

    /**
     * @param  array<string, mixed>  $config  the `billium.modules` config array
     * @param  list<string>  $providedPorts
     * @param  array<string, InstalledCheckpoint>  $checkpoints
     */
    public static function fromConfig(array $config, array $providedPorts = [], array $checkpoints = []): self
    {
        $composition = $config['composition'] ?? null;
        $enabled = $config['enabled'] ?? null;
        if (! is_string($composition) || ! is_array($enabled) || ! array_is_list($enabled)) {
            throw new InvalidArgumentException('module.descriptor_invalid: billium.modules needs composition and a list enabled');
        }

        $ids = [];
        foreach ($enabled as $id) {
            $ids[] = is_string($id) ? $id : throw new InvalidArgumentException('module.descriptor_invalid: enabled holds module ids');
        }

        return new self($composition, $ids, $providedPorts, $checkpoints);
    }
}

<?php

declare(strict_types=1);

namespace Billium\Core\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Compiled configuration for the desired module composition and enabled module set.
 */
#[Api(since: '1.0')]
final readonly class ModulesOptions
{
    /** @param list<string> $enabled */
    public function __construct(
        public string $composition = 'embedded-commerz',
        public array $enabled = ['platform', 'ledger'],
    ) {}

    public static function fromSection(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (! is_array($raw)) {
            $validator->add($path, 'must be an associative array');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['composition', 'enabled'], $path);

        $composition = $raw['composition'] ?? 'embedded-commerz';
        if (! is_string($composition) || trim($composition) === '') {
            $validator->add($path.'.composition', 'must be a non-empty composition id');
            $composition = 'embedded-commerz';
        }

        $enabled = $raw['enabled'] ?? ['platform', 'ledger'];
        $ids = [];

        if (! is_array($enabled) || ! array_is_list($enabled)) {
            $validator->add($path.'.enabled', 'must be a list of module id strings');
            $ids = ['platform', 'ledger'];
        } else {
            foreach ($enabled as $index => $moduleId) {
                if (! is_string($moduleId) || trim($moduleId) === '') {
                    $validator->add("{$path}.enabled.{$index}", 'must be a non-empty module id string');

                    continue;
                }

                $ids[] = $moduleId;
            }
        }

        return new self(composition: $composition, enabled: $ids);
    }
}

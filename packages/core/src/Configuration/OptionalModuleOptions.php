<?php

declare(strict_types=1);

namespace Billium\Core\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/** Compiled opt-in for an optional commercial module. */
#[Api(since: '1.0')]
final readonly class OptionalModuleOptions
{
    public function __construct(public bool $enabled = false) {}

    public static function fromSection(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (! is_array($raw)) {
            $validator->add($path, 'must be an associative array');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['enabled'], $path);
        $enabled = $raw['enabled'] ?? false;
        if (! is_bool($enabled)) {
            $validator->add($path.'.enabled', 'must be a boolean');
            $enabled = false;
        }

        return new self(enabled: $enabled);
    }
}

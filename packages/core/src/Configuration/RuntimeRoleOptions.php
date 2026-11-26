<?php

declare(strict_types=1);

namespace Billium\Core\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Compiled configuration for PostgreSQL D60 runtime-role preflight verification.
 */
#[Api(since: '1.0')]
final readonly class RuntimeRoleOptions
{
    public function __construct(
        public bool $preflight = true,
    ) {}

    public static function fromSection(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (! is_array($raw)) {
            $validator->add($path, 'must be an associative array');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['preflight'], $path);

        $preflight = $raw['preflight'] ?? true;
        if (! is_bool($preflight)) {
            $validator->add($path.'.preflight', 'must be a boolean');
            $preflight = true;
        }

        return new self(preflight: $preflight);
    }
}

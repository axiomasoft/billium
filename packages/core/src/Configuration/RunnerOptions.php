<?php

declare(strict_types=1);

namespace Billium\Core\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Compiled configuration for the root operation runner.
 */
#[Api(since: '1.0')]
final readonly class RunnerOptions
{
    public function __construct(
        public int $maxAttempts = 3,
        public int $rootDeadlineMs = 30_000,
    ) {}

    public static function fromSection(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (! is_array($raw)) {
            $validator->add($path, 'must be an associative array');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['max_attempts', 'root_deadline_ms'], $path);

        $attempts = $raw['max_attempts'] ?? 3;
        if (! is_int($attempts) || $attempts < 1) {
            $validator->add($path.'.max_attempts', 'must be an integer >= 1');
            $attempts = 3;
        }

        $deadline = $raw['root_deadline_ms'] ?? 30_000;
        if (! is_int($deadline) || $deadline < 1) {
            $validator->add($path.'.root_deadline_ms', 'must be an integer >= 1');
            $deadline = 30_000;
        }

        return new self(maxAttempts: $attempts, rootDeadlineMs: $deadline);
    }
}

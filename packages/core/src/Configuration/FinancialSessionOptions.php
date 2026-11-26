<?php

declare(strict_types=1);

namespace Billium\Core\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Compiled configuration for financial session lock boundaries.
 */
#[Api(since: '1.0')]
final readonly class FinancialSessionOptions
{
    public function __construct(
        public int $sealLockTimeoutMs = 5_000,
    ) {}

    public static function fromSection(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (! is_array($raw)) {
            $validator->add($path, 'must be an associative array');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['seal_lock_timeout_ms'], $path);

        $timeout = $raw['seal_lock_timeout_ms'] ?? 5_000;
        if (! is_int($timeout) || $timeout < 1) {
            $validator->add($path.'.seal_lock_timeout_ms', 'must be an integer >= 1');
            $timeout = 5_000;
        }

        return new self(sealLockTimeoutMs: $timeout);
    }
}

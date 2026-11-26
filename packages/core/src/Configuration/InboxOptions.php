<?php

declare(strict_types=1);

namespace Billium\Core\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Compiled configuration for inbox custody retries and retention pruning.
 */
#[Api(since: '1.0')]
final readonly class InboxOptions
{
    /** @param array<string, mixed> $handlerCompatibility */
    public function __construct(
        public int $retryBackoffSeconds = 5,
        public int $retryBudget = 10,
        public array $handlerCompatibility = [],
        public ?int $retentionFloorSeconds = null,
    ) {}

    public static function fromSection(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (! is_array($raw)) {
            $validator->add($path, 'must be an associative array');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, [
            'retry_backoff_seconds',
            'retry_budget',
            'handler_compatibility',
            'retention_floor_seconds',
        ], $path);

        $backoff = $raw['retry_backoff_seconds'] ?? 5;
        if (! is_int($backoff) || $backoff < 0) {
            $validator->add($path.'.retry_backoff_seconds', 'must be an integer >= 0');
            $backoff = 5;
        }

        $budget = $raw['retry_budget'] ?? 10;
        if (! is_int($budget) || $budget < 1) {
            $validator->add($path.'.retry_budget', 'must be an integer >= 1');
            $budget = 10;
        }

        $compatibility = $raw['handler_compatibility'] ?? [];
        if (! is_array($compatibility)) {
            $validator->add($path.'.handler_compatibility', 'must be an array');
            $compatibility = [];
        } else {
            foreach (array_keys($compatibility) as $key) {
                if (! is_string($key)) {
                    $validator->add($path.'.handler_compatibility', 'must be keyed by string handler identifiers');
                    $compatibility = [];

                    break;
                }
            }
        }

        $floor = $raw['retention_floor_seconds'] ?? null;
        if ($floor !== null && (! is_int($floor) || $floor < 0)) {
            $validator->add($path.'.retention_floor_seconds', 'must be null or an integer >= 0');
            $floor = null;
        }

        /** @var array<string, mixed> $compatibility */
        return new self(
            retryBackoffSeconds: $backoff,
            retryBudget: $budget,
            handlerCompatibility: $compatibility,
            retentionFloorSeconds: $floor,
        );
    }
}

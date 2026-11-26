<?php

declare(strict_types=1);

namespace Billium\Core\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Compiled configuration for outbox relay and retention pruning.
 */
#[Api(since: '1.0')]
final readonly class OutboxOptions
{
    public function __construct(
        public int $relayBatch = 100,
        public int $claimSeconds = 60,
        public ?int $retentionFloorSeconds = null,
        public int $deliveryBackoffSeconds = 5,
        public int $deliveryBudget = 10,
        public ?string $maintenanceConnection = null,
    ) {}

    public static function fromSection(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (! is_array($raw)) {
            $validator->add($path, 'must be an associative array');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, [
            'relay_batch',
            'claim_seconds',
            'retention_floor_seconds',
            'delivery_backoff_seconds',
            'delivery_budget',
            'maintenance_connection',
        ], $path);

        $relayBatch = self::positiveInt($raw['relay_batch'] ?? 100, 100, $path.'.relay_batch', $validator);
        $claimSeconds = self::positiveInt($raw['claim_seconds'] ?? 60, 60, $path.'.claim_seconds', $validator);
        $backoff = self::nonNegativeInt($raw['delivery_backoff_seconds'] ?? 5, 5, $path.'.delivery_backoff_seconds', $validator);
        $budget = self::positiveInt($raw['delivery_budget'] ?? 10, 10, $path.'.delivery_budget', $validator);

        $floor = $raw['retention_floor_seconds'] ?? null;
        if ($floor !== null && (! is_int($floor) || $floor < 0)) {
            $validator->add($path.'.retention_floor_seconds', 'must be null or an integer >= 0');
            $floor = null;
        }

        $maintenance = $raw['maintenance_connection'] ?? null;
        if ($maintenance !== null && (! is_string($maintenance) || trim($maintenance) === '')) {
            $validator->add($path.'.maintenance_connection', 'must be null or a non-empty string');
            $maintenance = null;
        }

        return new self(
            relayBatch: $relayBatch,
            claimSeconds: $claimSeconds,
            retentionFloorSeconds: $floor,
            deliveryBackoffSeconds: $backoff,
            deliveryBudget: $budget,
            maintenanceConnection: $maintenance,
        );
    }

    private static function positiveInt(mixed $value, int $default, string $path, ConfigurationValidator $validator): int
    {
        if (! is_int($value) || $value < 1) {
            $validator->add($path, 'must be an integer >= 1');

            return $default;
        }

        return $value;
    }

    private static function nonNegativeInt(mixed $value, int $default, string $path, ConfigurationValidator $validator): int
    {
        if (! is_int($value) || $value < 0) {
            $validator->add($path, 'must be an integer >= 0');

            return $default;
        }

        return $value;
    }
}

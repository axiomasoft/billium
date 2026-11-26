<?php

declare(strict_types=1);

namespace Billium\Core\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Data\SecretRef;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Compiled configuration for event feed bootstrap, draining, and token signing.
 */
#[Api(since: '1.0')]
final readonly class FeedOptions
{
    public function __construct(
        public int $drainBatch = 100,
        public int $bootstrapLeaseSeconds = 60,
        public int $bootstrapBatch = 100,
        public SecretRef $signingKey = new SecretRef(''),
    ) {}

    public static function fromSection(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (! is_array($raw)) {
            $validator->add($path, 'must be an associative array');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, [
            'drain_batch',
            'bootstrap_lease_seconds',
            'bootstrap_batch',
            'signing_key',
        ], $path);

        $drainBatch = self::positiveInt($raw['drain_batch'] ?? 100, 100, $path.'.drain_batch', $validator);
        $leaseSeconds = self::positiveInt($raw['bootstrap_lease_seconds'] ?? 60, 60, $path.'.bootstrap_lease_seconds', $validator);
        $bootstrapBatch = self::positiveInt($raw['bootstrap_batch'] ?? 100, 100, $path.'.bootstrap_batch', $validator);

        $rawKey = $raw['signing_key'] ?? null;
        if ($rawKey instanceof SecretRef) {
            $signingKey = $rawKey;
        } elseif ($rawKey === null) {
            $signingKey = new SecretRef('');
        } elseif (is_string($rawKey)) {
            $signingKey = new SecretRef($rawKey);
        } else {
            $validator->add($path.'.signing_key', 'must be null, string, or SecretRef');
            $signingKey = new SecretRef('');
        }

        return new self(
            drainBatch: $drainBatch,
            bootstrapLeaseSeconds: $leaseSeconds,
            bootstrapBatch: $bootstrapBatch,
            signingKey: $signingKey,
        );
    }

    public function decodedSigningKey(?string $fallbackAppKey = null): string
    {
        $raw = $this->signingKey->isPresent() ? $this->signingKey->reveal() : ($fallbackAppKey ?? '');

        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);

            return is_string($decoded) ? $decoded : '';
        }

        return $raw;
    }

    private static function positiveInt(mixed $value, int $default, string $path, ConfigurationValidator $validator): int
    {
        if (! is_int($value) || $value < 1) {
            $validator->add($path, 'must be an integer >= 1');

            return $default;
        }

        return $value;
    }
}

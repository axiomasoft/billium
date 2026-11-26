<?php

declare(strict_types=1);

namespace Billium\Core\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Compiled configuration for ledger refund reconciliation timing.
 */
#[Api(since: '1.0')]
final readonly class LedgerOptions
{
    public function __construct(
        public int $refundReconcileAfterSeconds = 3_600,
    ) {}

    public static function fromSection(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (! is_array($raw)) {
            $validator->add($path, 'must be an associative array');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['refund_reconcile_after_seconds'], $path);

        $seconds = $raw['refund_reconcile_after_seconds'] ?? 3_600;
        if (! is_int($seconds) || $seconds < 0) {
            $validator->add($path.'.refund_reconcile_after_seconds', 'must be an integer >= 0');
            $seconds = 3_600;
        }

        return new self(refundReconcileAfterSeconds: $seconds);
    }
}

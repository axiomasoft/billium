<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Attributes\Api;
use InvalidArgumentException;

/** Frozen root account identity; credential reference may rotate only with independently verified continuity (D26). */
#[Api]
final readonly class ExecutionBinding
{
    public function __construct(
        public string $installationRef,
        public string $sellerRef,
        public string $environment,
        public string $merchantAccountRef,
        public string $bindingRevision,
        public string $driver,
        public string $driverVersion,
        public string $providerApiVersion,
        public string $credentialRevisionRef,
        public string $capabilityDigest,
    ) {
        foreach (get_object_vars($this) as $value) {
            if ($value === '') {
                throw new InvalidArgumentException('Binding members must be nonempty');
            }
        }
        if (preg_match('/^sha256:[0-9a-f]{64}$/D', $capabilityDigest) !== 1) {
            throw new InvalidArgumentException('Invalid capability digest');
        }
    }
}

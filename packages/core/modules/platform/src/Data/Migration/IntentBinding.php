<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Migration;

use Billium\Platform\Internal\Migration\MigrationDigest;
use InvalidArgumentException;
use Packman\Attributes\Api;

/** Persisted before dispatch. A refund records its own command and inherits the original binding. */
#[Api(since: '1.0')]
final readonly class IntentBinding
{
    public function __construct(
        public string $sourceContext,
        public string $businessIntentRef,
        public string $operationRef,
        public ExecutionRoute $route,
        public string $merchantAccountRef,
        public string $bindingRevision,
        public string $commandKey,
        public string $payloadDigest,
        public ?string $originalChargeRef = null,
    ) {
        foreach ([$sourceContext, $businessIntentRef, $operationRef, $merchantAccountRef, $bindingRevision, $commandKey, $originalChargeRef ?? $operationRef] as $value) {
            if (trim($value) === '' || strlen($value) > 200) {
                throw new InvalidArgumentException('Incomplete intent binding');
            }
        }
        if (! MigrationDigest::valid($payloadDigest) || $originalChargeRef === $operationRef) {
            throw new InvalidArgumentException('Invalid intent digest or original charge');
        }
    }

    public function matches(self $other): bool
    {
        return [...get_object_vars($this), 'route' => get_object_vars($this->route)]
            === [...get_object_vars($other), 'route' => get_object_vars($other->route)];
    }
}

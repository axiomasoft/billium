<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Data\Money;
use Billium\Execution\Enums\EffectKind;
use InvalidArgumentException;

/** Provider key + semantic digest are frozen for this logical effect, never regenerated for recovery. */
#[Api]
final readonly class DriverCommand
{
    public function __construct(
        public ExecutionBinding $binding,
        public EffectKind $kind,
        public string $operationRef,
        public string $providerKey,
        public string $digest,
        public Money $money,
        public ?string $originalOperationRef = null,
    ) {
        if ($money->minor <= 0 || $operationRef === '' || $providerKey === '' || preg_match('/^sha256:[0-9a-f]{64}$/D', $digest) !== 1) {
            throw new InvalidArgumentException('Invalid effect identity');
        }
        $child = in_array($kind, [EffectKind::Capture, EffectKind::VoidAuthorization, EffectKind::Refund], true);
        if ($child && ($originalOperationRef === null || $originalOperationRef === '' || $originalOperationRef === $operationRef)) {
            throw new InvalidArgumentException('Child requires a distinct original root');
        }
    }
}

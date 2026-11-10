<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Execution\Enums\EffectKind;

#[Api]
final readonly class WriterAdmissionRequest
{
    public function __construct(
        public ExecutionBinding $binding,
        public string $operationRef,
        public EffectKind $action,
        public bool $recovery,
    ) {}
}

<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Contracts\Attributes\Api;
use Billium\Core\Enums\Modules\ModuleLifecycleState;
use Billium\Core\Enums\Modules\ModuleOperationClass;

/**
 * Verdict of the lifecycle gate for one operation class. `conditions` are checks the caller must still enforce
 * (e.g. original owner only for a refund after the module stopped accepting).
 */
#[Api]
final readonly class ModuleAdmission
{
    /** @param list<string> $conditions */
    public function __construct(
        public string $moduleId,
        public ModuleLifecycleState $state,
        public ModuleOperationClass $operationClass,
        public bool $allowed,
        public string $reason,
        public array $conditions = [],
    ) {}
}

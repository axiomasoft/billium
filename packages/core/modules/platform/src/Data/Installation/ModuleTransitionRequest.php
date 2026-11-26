<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Installation;

use Billium\Core\Data\Modules\ModuleTransitionFacts;
use Billium\Core\Enums\Modules\ModuleLifecycleState;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * One lifecycle step of a module (D55). `facts` are preview input, never proof of drained work. recovery_only and
 * retired require ModuleTransitionEvidence to obtain current owning-module facts under the exclusive barrier;
 * an evidence reference alone cannot authorize them. Platform recomputes active dependents under the same locks.
 */
#[Api(since: '1.0')]
final readonly class ModuleTransitionRequest
{
    public function __construct(
        public string $transitionRef,
        public string $moduleId,
        public ModuleLifecycleState $target,
        public string $reason,
        public ?string $evidenceRef = null,
        public ModuleTransitionFacts $facts = new ModuleTransitionFacts,
    ) {
        if ($transitionRef === '' || strlen($transitionRef) > 150 || $reason === '' || preg_match('/^[a-z][a-z0-9_]*$/D', $moduleId) !== 1) {
            throw new InvalidArgumentException('Module transition needs a bounded ref, a module id and a reason');
        }
        if ($evidenceRef === '') {
            throw new InvalidArgumentException('Evidence reference is null or nonempty');
        }
    }

    public function digest(): string
    {
        return 'sha256:'.hash('sha256', json_encode([
            $this->transitionRef, $this->moduleId, $this->target->value, $this->reason, $this->evidenceRef, get_object_vars($this->facts),
        ], JSON_THROW_ON_ERROR));
    }
}

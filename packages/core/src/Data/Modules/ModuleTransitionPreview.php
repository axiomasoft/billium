<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Core\Enums\Modules\ModuleLifecycleState;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Modules\ModuleTransitionKind;
use Packman\Attributes\Api;

/**
 * What a disable/retire/uninstall would do. Read-only: applying it is a P3.3 root-Tx operation that re-checks the
 * facts under the installation lock. A preview never purges data (`purgesData` is always false).
 */
#[Api(since: '1.0')]
final readonly class ModuleTransitionPreview
{
    /**
     * @param  list<ModuleBlocker>  $blockers
     * @param  list<string>  $cascade  dependents that must move to at least the same state
     * @param  list<ModuleOperationClass>  $stillServed
     */
    public function __construct(
        public string $moduleId,
        public ModuleTransitionKind $kind,
        public ?ModuleLifecycleState $from,
        public ?ModuleLifecycleState $to,
        public array $blockers,
        public array $cascade,
        public array $stillServed,
        public bool $purgesData = false,
    ) {}

    public function allowed(): bool
    {
        return $this->blockers === [];
    }
}

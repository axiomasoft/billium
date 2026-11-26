<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Modules;

use Billium\Core\Data\Modules\ModuleState;
use Billium\Core\Data\Modules\ModuleTransitionFacts;
use Billium\Core\Data\Modules\ModuleTransitionPreview;
use Billium\Core\Enums\Modules\ModuleLifecycleState;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Read-only preview of disable/retire/uninstall; the applying mutator and its re-check belong to P3.3. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface ModuleTransitionPlanner
{
    public function previewTransition(ModuleState $current, ModuleLifecycleState $target, ModuleTransitionFacts $facts): ModuleTransitionPreview;

    /** Binary removal. Never a schema purge; with history it needs a read/recovery bundle or is refused. */
    public function previewUninstall(ModuleState $current, ModuleTransitionFacts $facts): ModuleTransitionPreview;
}

<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Modules;

use Billium\Core\Data\Modules\CompatibilityInput;
use Billium\Core\Data\Modules\CompatibilityReport;
use Billium\Core\Data\Modules\ModuleDescriptor;
use Billium\Core\Data\Modules\ModuleManifest;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Read access to the packaged module descriptors and the boot-time composition check. Billium registers only its
 * own modules; a host cannot add a descriptor (REQ-OUT-19).
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface ModuleRegistry
{
    public function manifest(): ModuleManifest;

    public function descriptor(string $moduleId): ?ModuleDescriptor;

    /** Pure comparison of desired config, descriptor set, port capabilities and installed checkpoints; no I/O. */
    public function check(CompatibilityInput $input): CompatibilityReport;
}

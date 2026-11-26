<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Core\Enums\Modules\CompatibilityFindingCode;
use Packman\Attributes\Api;

/**
 * Outcome of boot validation. Any finding keeps effect-capable boot closed: liveness may stay green, readiness is
 * false and a doctor finding names the cause. `bootOrder` is the dependency order of the enabled modules.
 */
#[Api(since: '1.0')]
final readonly class CompatibilityReport
{
    /**
     * @param  list<CompatibilityFinding>  $findings
     * @param  list<string>  $bootOrder
     */
    public function __construct(public array $findings, public array $bootOrder) {}

    public function isReady(): bool
    {
        return $this->findings === [];
    }

    public function has(CompatibilityFindingCode $code, ?string $moduleId = null): bool
    {
        return array_any($this->findings, fn (CompatibilityFinding $finding): bool => $finding->code === $code && ($moduleId === null || $finding->moduleId === $moduleId));
    }
}

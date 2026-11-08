<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Contracts\Attributes\Api;
use Billium\Core\Enums\Modules\CompatibilityFindingCode;

#[Api]
final readonly class CompatibilityFinding
{
    public function __construct(
        public CompatibilityFindingCode $code,
        public ?string $moduleId,
        public string $message,
    ) {}
}

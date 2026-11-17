<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Core\Enums\Modules\CompatibilityFindingCode;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class CompatibilityFinding
{
    public function __construct(
        public CompatibilityFindingCode $code,
        public ?string $moduleId,
        public string $message,
    ) {}
}

<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Correction;

use Packman\Attributes\Api;

#[Api]
final readonly class ApplyCorrection
{
    public function __construct(public PreparedCorrection $prepared, public string $operationKey) {}
}

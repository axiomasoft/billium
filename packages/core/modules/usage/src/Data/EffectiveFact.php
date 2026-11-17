<?php

declare(strict_types=1);

namespace Billium\Usage\Data;

use Packman\Attributes\Api;

#[Api]
final readonly class EffectiveFact
{
    public function __construct(public FactRevision $revision) {}
}

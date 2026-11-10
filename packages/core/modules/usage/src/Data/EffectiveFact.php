<?php

declare(strict_types=1);

namespace Billium\Usage\Data;

final readonly class EffectiveFact
{
    public function __construct(public FactRevision $revision) {}
}

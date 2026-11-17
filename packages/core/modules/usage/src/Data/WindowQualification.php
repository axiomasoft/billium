<?php

declare(strict_types=1);

namespace Billium\Usage\Data;

use Packman\Attributes\Api;

#[Api]
final readonly class WindowQualification
{
    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $repairRefs
     */
    public function __construct(public bool $qualified, public array $reasons, public array $repairRefs) {}
}

<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Core\Enums\Modules\TransitionBlockerCode;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ModuleBlocker
{
    /** @param list<string> $detail */
    public function __construct(public TransitionBlockerCode $code, public int $count, public array $detail = []) {}
}

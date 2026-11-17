<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Rating\Enums\ProrationPolicyId;
use Packman\Attributes\Api;

#[Api]
interface ProrationPolicyRegistry
{
    public function resolve(string $id): ProrationPolicyId;
}

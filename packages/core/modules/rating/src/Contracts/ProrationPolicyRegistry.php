<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Rating\Enums\ProrationPolicyId;

#[Api]
interface ProrationPolicyRegistry
{
    public function resolve(string $id): ProrationPolicyId;
}

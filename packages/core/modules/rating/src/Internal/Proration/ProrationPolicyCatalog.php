<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Proration;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Contracts\ProrationPolicyRegistry;
use Billium\Rating\Enums\ProrationPolicyId;

final class ProrationPolicyCatalog implements ProrationPolicyRegistry
{
    public function resolve(string $id): ProrationPolicyId
    {
        return ProrationPolicyId::tryFrom($id)
            ?? throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.amendment_unsupported');
    }
}

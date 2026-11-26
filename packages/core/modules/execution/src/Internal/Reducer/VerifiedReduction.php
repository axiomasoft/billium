<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Reducer;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ProviderWorkClaim;
use Billium\Execution\Data\ReductionResult;
use Billium\Execution\Internal\Custody\VerifiedDelivery;
use Billium\Execution\Internal\Custody\VerifyDelivery;
use Closure;
use Packman\Attributes\Internal;
use WeakMap;

#[Internal]
final readonly class VerifiedReduction
{
    /** @var WeakMap<VerifiedDelivery,true> */
    private WeakMap $verified;

    /** @var WeakMap<TransactionContext,array{ProviderWorkClaim,VerifiedDelivery}> */
    private WeakMap $active;

    /** @param Closure():VerifyDelivery $verifier */
    public function __construct(private Closure $verifier)
    {
        $this->verified = new WeakMap;
        $this->active = new WeakMap;
    }

    public function authenticate(OperationRecord $op, ProviderEvidence $evidence): VerifiedDelivery
    {
        $delivery = ($this->verifier)()->verify($op->binding->scope, $op->binding->merchantRevisionRef, $op->operationRef, $evidence, false);
        $this->verified[$delivery] = true;

        return $delivery;
    }

    /** @param Closure():ReductionResult $apply */
    public function run(TransactionContext $ctx, ProviderWorkClaim $claim, VerifiedDelivery $delivery, Closure $apply): ReductionResult
    {
        if (! isset($this->verified[$delivery]) || isset($this->active[$ctx]) || $claim->evidenceRef !== $delivery->evidence->evidenceRef) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Current authenticated provider work is required');
        }
        $this->active[$ctx] = [$claim, $delivery];
        try {
            return $apply();
        } finally {
            unset($this->active[$ctx]);
        }
    }

    public function verifiedIn(TransactionContext $ctx, ProviderWorkClaim $claim, MaterialSnapshot $snapshot): VerifiedDelivery
    {
        $prepared = $this->active[$ctx] ?? null;
        if ($prepared === null || $prepared[0] !== $claim || $prepared[1]->snapshot !== $snapshot) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Reduction requires independently authenticated work in this invocation');
        }

        return $prepared[1];
    }
}

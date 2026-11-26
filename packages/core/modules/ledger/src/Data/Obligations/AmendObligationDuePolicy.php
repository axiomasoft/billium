<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Obligations;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Api;

/**
 * Keyed due-policy replacement, including explicit disabling with null dueAt (D60, BC-ENV-28).
 */
#[Api(since: '1.0')]
final readonly class AmendObligationDuePolicy
{
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $obligationRef,
        public ObligationDuePolicy $duePolicy,
    ) {
        if (! $duePolicy->hasProvenance()) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'a due-policy amendment needs provenance even when disabling automatic collection');
        }
    }
}

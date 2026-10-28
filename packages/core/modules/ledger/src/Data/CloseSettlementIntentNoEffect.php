<?php

declare(strict_types=1);

namespace Billium\Ledger\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;

/**
 * No-effect closure of a settlement intent (D63/F04). Binds the intent and its expected revision, the collection step
 * authorization, the durable not-admitted/not-dispatched proof, the account scope/currency and the cause. The ledger
 * participant validates the proof under its predeclared locks; it never clears a sent/unknown intent by timeout.
 */
#[Api]
final readonly class CloseSettlementIntentNoEffect
{
    public function __construct(
        public BillingAccountScope $scope,
        public string $settlementIntentRef,
        public int $expectedRevision,
        public string $stepAuthorizationRef,
        public string $notDispatchedProofRef,
        public string $currency,
        public string $cause,
    ) {
        if ($settlementIntentRef === '' || $stepAuthorizationRef === '' || $notDispatchedProofRef === '' || $cause === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'intent, step authorization, not-dispatched proof and cause are required');
        }
        if ($expectedRevision < 1 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'expected revision and ISO currency are required');
        }
    }
}

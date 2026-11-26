<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Settlements;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Api;

/**
 * No-effect closure of a settlement intent (D63/F04, FA02). Binds the intent and its expected revision, the collection
 * step authorization, the closure identity, the account scope/currency, the cause and the typed same-root NoEffectProof.
 * The ledger participant validates the proof and the ledger state under its predeclared locks; it never clears a
 * sent/unknown intent by timeout. A missing proof is refused by the participant.
 */
#[Api(since: '1.0')]
final readonly class CloseSettlementIntentNoEffect
{
    public function __construct(
        public BillingAccountScope $scope,
        public string $settlementIntentRef,
        public int $expectedRevision,
        public string $stepAuthorizationRef,
        public string $closureRef,
        public string $currency,
        public string $cause,
        public ?NoEffectProof $proof,
    ) {
        if ($settlementIntentRef === '' || $stepAuthorizationRef === '' || $closureRef === '' || $cause === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'intent, step authorization, closure and cause are required');
        }
        if ($expectedRevision < 1 || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'expected revision and ISO currency are required');
        }
    }
}

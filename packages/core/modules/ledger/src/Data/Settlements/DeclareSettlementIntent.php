<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Settlements;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Api;

/**
 * Ordered target identities are frozen here; account/currency ownership is checked under ledger locks (D60).
 */
#[Api(since: '1.0')]
final readonly class DeclareSettlementIntent
{
    /**
     * @param  list<string>  $obligationRefs
     */
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $businessIntentRef,
        public string $currency,
        public array $obligationRefs,
        public string $policyVersion = 'pro_rata_open/1',
    ) {
        self::validateTargets($obligationRefs);
    }

    /**
     * Check native PHP array values even when a caller does not obey the public list<string> contract.
     *
     * @param  array<array-key, mixed>  $references
     */
    private static function validateTargets(array $references): void
    {
        if (! array_is_list($references)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'settlement targets are an ordered list');
        }
        foreach ($references as $ref) {
            if (! is_string($ref) || $ref === '') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'settlement target references are nonempty strings');
            }
        }
    }
}

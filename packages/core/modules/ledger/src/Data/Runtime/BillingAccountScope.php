<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Runtime;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;

/**
 * Authenticated selectors are checked by the ledger boundary; the DTO grants no authority (D60, INV-14).
 */
#[Api(since: '1.0')]
final readonly class BillingAccountScope
{
    public function __construct(
        public string $sellerRef,
        public string $environment,
        public string $billingAccountRef,
    ) {
        if ($sellerRef === '' || $environment === '' || $billingAccountRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'seller, environment and billing account are required');
        }
    }
}

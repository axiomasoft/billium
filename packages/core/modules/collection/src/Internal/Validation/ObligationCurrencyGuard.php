<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Validation;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Ledger\Contracts\CollectionObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Internal;

/** A dunning case collects its obligation in the obligation's own currency; an unknown obligation is left to admission. */
#[Internal]
final readonly class ObligationCurrencyGuard
{
    public function __construct(private CollectionObligation $obligations) {}

    public function assertMatches(TransactionContext $context, Caller $caller, string $billingAccountRef, string $obligationRef, string $currency): void
    {
        $scope = new BillingAccountScope($caller->sellerRef, $caller->environment, $billingAccountRef);
        $obligationCurrency = $this->obligations->obligationCurrency($context, $scope, $obligationRef);
        if ($obligationCurrency !== null && $obligationCurrency !== $currency) {
            throw new ProblemException(ProblemCode::MoneyCurrencyMismatch, 'billium.collection.obligation_currency_mismatch');
        }
    }
}

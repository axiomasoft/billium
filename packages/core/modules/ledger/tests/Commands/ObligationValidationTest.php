<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Commands;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Ledger\Data\Obligations\AdjustObligation;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class ObligationValidationTest extends LedgerTestCase
{
    public function test_auth_money_and_unknown_reference_errors_leave_no_claim_or_business_receipt(): void
    {
        $badScale = new RecordObligation('bad-scale', $this->account(), 'o', 'basis', 'customer', 'component', new Money(1000, new Currency('RUB', 9)), self::digest());
        $wrongSeller = new RecordObligation('foreign', new BillingAccountScope('seller-b', 'test', 'account-a'), 'o', 'basis', 'customer', 'component', Money::parse('1.00', 'RUB'), self::digest());
        foreach ([[fn () => $this->ledger()->recordObligation($badScale), ProblemCode::MoneyScaleInvalid],
            [fn () => $this->ledger()->recordObligation($wrongSeller), ProblemCode::AuthDenied],
            [fn () => $this->ledger()->adjustObligation(new AdjustObligation('missing', $this->account(), 'adjust', 'missing', Money::parse('1.00', 'RUB'), 'cause')), ProblemCode::ReferenceUnknown]] as [$invoke,$code]) {
            try {
                $invoke();
                self::fail('none-policy input refusal');
            } catch (ProblemException $e) {
                self::assertSame($code, $e->problem);
            }
        }
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind LIKE 'ledger.%'"));
        self::assertSame(0, $this->rows('billium_allocation_receipt'));
    }
}

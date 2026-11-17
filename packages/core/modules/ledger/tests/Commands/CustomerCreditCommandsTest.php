<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Commands;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Ledger\Data\Credits\RecordCustomerCredit;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class CustomerCreditCommandsTest extends LedgerTestCase
{
    public function test_monetary_credit_replays_its_source_and_refuses_changed_amount(): void
    {
        $cmd = new RecordCustomerCredit('credit', $this->account(), 'credit-ref', 'source', Money::parse('10.00', 'RUB'));
        $first = $this->ledger()->recordCustomerCredit($cmd);
        self::assertSame($first->body, $this->ledger()->recordCustomerCredit($cmd)->body);
        self::assertSame($first->body, $this->ledger()->recordCustomerCredit(new RecordCustomerCredit('another', $this->account(), 'credit-ref', 'source', Money::parse('10.00', 'RUB')))->body);
        self::assertSame(1000, self::integer($this->value('SELECT remaining_minor FROM billium_customer_credit')));
        try {
            $this->ledger()->recordCustomerCredit(new RecordCustomerCredit('conflict', $this->account(), 'credit-ref', 'source', Money::parse('11.00', 'RUB')));
            self::fail('source conflict');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
        }
        self::assertSame(1, $this->rows('billium_customer_credit'));
    }

    public function test_zero_or_negative_credit_is_validation_none(): void
    {
        foreach (['0.00', '-1.00'] as $amount) {
            try {
                $this->ledger()->recordCustomerCredit(new RecordCustomerCredit('invalid', $this->account(), 'credit', 'source', Money::parse($amount, 'RUB')));
                self::fail('invalid credit');
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            }
        }
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind='ledger.record_customer_credit'"));
    }
}

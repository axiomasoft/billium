<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Schema;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\BillingAccountScope;
use Billium\Ledger\Data\RecordCustomerCredit;
use Billium\Ledger\Data\RecordObligation;
use Billium\Platform\Tests\PlatformTestCase;
use PDO;

/** D50: real committed fixtures; all raw writes use the separate fixture owner, routines use runtime. */
abstract class LedgerTestCase extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
    }

    protected function ledger(): Ledger
    {
        return $this->application()->make(Ledger::class);
    }

    protected function account(string $account = 'account-a'): BillingAccountScope
    {
        $c = $this->callers->current();

        return new BillingAccountScope($c->sellerRef, $c->environment, $account);
    }

    protected function obligation(string $ref = 'obligation-a', int $amount = 1000, string $account = 'account-a'): int
    {
        $this->ledger()->recordObligation(new RecordObligation('key-'.$ref, $this->account($account), $ref, 'basis-'.$ref, 'customer-a', 'component-a', new Money($amount, Currency::of('RUB')), self::digest()));

        return self::integer($this->value('SELECT id FROM billium_obligation WHERE obligation_ref=?', [$ref]));
    }

    protected function credit(string $ref = 'credit-a', int $amount = 1000, string $account = 'account-a'): int
    {
        $this->ledger()->recordCustomerCredit(new RecordCustomerCredit('key-'.$ref, $this->account($account), $ref, 'source-'.$ref, new Money($amount, Currency::of('RUB'))));

        return self::integer($this->value('SELECT id FROM billium_customer_credit WHERE credit_ref=?', [$ref]));
    }

    protected function payment(int $capture = 1000, string $account = 'account-a', ?int $intent = null): int
    {
        return self::integer($this->value(<<<'SQL'
            INSERT INTO billium_payment(installation_ref,seller_ref,environment,billing_account_ref,currency,payment_ref,source_authority,source_lineage,operation_ref,kind,captured_minor,settlement_intent_id)
            VALUES('inst-test','seller-a','test',?,'RUB','payment-a','authority-a','lineage-a','operation-a','charge',?,?) RETURNING id
            SQL, [$account, $capture, $intent]));
    }

    protected function invoice(int $obligation, string $status = 'issued'): int
    {
        $id = self::integer($this->value(<<<'SQL'
            INSERT INTO billium_invoice(installation_ref,seller_ref,environment,billing_account_ref,currency,invoice_ref,customer_ref,status,subtotal_minor,total_minor,issued_at)
            VALUES('inst-test','seller-a','test','account-a','RUB','invoice-a','customer-a',?,1000,1000,CASE WHEN ?='draft' THEN NULL ELSE now() END) RETURNING id
            SQL, [$status, $status]));
        $this->owner()->update('UPDATE billium_obligation SET billed_invoice_id=?,revision=revision+1 WHERE id=?', [$id, $obligation]);

        return $id;
    }

    public static function digest(string $char = 'a'): string
    {
        return 'sha256:'.str_repeat($char, 64);
    }

    /** @param list<mixed> $arguments
     * @return array<string,mixed>
     */
    protected function routine(string $name, array $arguments, ?PDO $pdo = null): array
    {
        $pdo ??= TestEnvironment::pdo();
        $statement = $pdo->prepare('SELECT public.billium_'.$name.'('.implode(',', array_fill(0, count($arguments), '?')).')');
        $statement->execute($arguments);
        $data = json_decode(self::text($statement->fetchColumn()), true, 64, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            self::fail('routine did not return an object');
        }
        $result = [];
        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                self::fail('routine object keys must be strings');
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /** @return list<mixed> */
    protected function scopeArguments(string $account = 'account-a'): array
    {
        return ['inst-test', 'seller-a', 'test', $account, 'RUB'];
    }

    /** @return array<string,mixed> */
    protected function allocate(int $obligation, ?int $payment, ?int $credit, int $amount, string $receipt = 'allocation-a', ?int $reverse = null, ?int $intent = null): array
    {
        return $this->routine('allocate', [...$this->scopeArguments(), $obligation, $payment, $credit, $intent, $amount, $reverse, 'test', 'pro_rata_open/1', $receipt, self::digest()]);
    }
}

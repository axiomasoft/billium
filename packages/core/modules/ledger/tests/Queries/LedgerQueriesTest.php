<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Queries;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Data\Caller;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Data\Views\LedgerView;
use Billium\Ledger\Internal\Queries\ReadSession;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use LogicException;

final class LedgerQueriesTest extends QueryTestCase
{
    public function test_refund_capacity_subtracts_confirmed_refunds_and_includes_existing_release_deficit(): void
    {
        $this->seedQueryState();
        $this->observe(ObservationFactory::charge('op-1', 2, 10000, 5000, sequence: 2));
        $advice = $this->queries()->refundAuthorization('op-1', Money::parse('20.00', 'RUB'));
        self::assertSame('50.00', self::arr($advice['normalCapacity'])['amount']);
        self::assertSame('30.00', self::arr($advice['requiredReallocation'])['amount']);
        self::assertTrue($this->queries()->paymentStatus('op-1')['reallocationRequired']);
        self::assertSame('0.00', self::arr($this->queries()->paymentStatus('op-1')['spendable'])['amount']);
        self::assertFalse($this->queries()->refundAuthorization('op-1', Money::parse('60.00', 'RUB'))['allowed']);
    }

    public function test_queries_use_billium_connection_when_the_host_default_is_unavailable(): void
    {
        $this->obligation();
        config(['database.default' => 'unavailable_host', 'database.connections.unavailable_host' => [
            ...TestEnvironment::connection(), 'database' => 'absent_host_database',
        ]]);
        self::assertSame('obligation-a', $this->queries()->obligation('obligation-a')['obligationRef']);
        self::assertArrayNotHasKey('unavailable_host', app(DatabaseManager::class)->getConnections());
    }

    public function test_queries_render_scoped_money_snapshots_and_original_receipts(): void
    {
        $this->seedQueryState();
        $q = $this->queries();
        self::assertSame('50.00', self::arr($q->obligation('obligation-a')['open'])['amount']);
        $invoice = $q->invoice('invoice-a');
        self::assertSame('partially_paid', $invoice['status']);
        self::assertSame('50.00', self::arr($invoice['open'])['amount']);
        self::assertNull($invoice['legalNumber']);
        self::assertCount(1, self::arr($invoice['lines']));
        $allocations = $q->allocationByOperation('op-1');
        self::assertCount(2, self::arr($allocations['receipts']));
        $body = $q->receipt('allocation:allocation-a');
        self::assertSame('allocation:allocation-a', $body['receipt_ref']);
        self::assertContains($body, self::arr($allocations['receipts']));
        $status = $q->paymentStatus('op-1');
        self::assertSame('current', $status['projectionStatus']);
        self::assertSame('40.00', self::arr($status['spendable'])['amount']);
        (new Codec)->decode(self::request('obligation', ['obligationRef' => 'obligation-a']));
        (new Codec)->decode(json_encode($q->dispatch(self::request('paymentStatus', ['paymentRef' => 'op-1'])), JSON_THROW_ON_ERROR));
    }

    public function test_every_reference_query_hides_missing_and_foreign_seller_environment_installation(): void
    {
        $this->seedQueryState();
        $q = $this->queries();
        foreach (['absent', 'foreign-seller', 'foreign-environment', 'foreign-installation'] as $case) {
            $foreign = $case !== 'absent';
            $this->callers->set(new Caller($case === 'foreign-seller' ? 'seller-b' : 'seller-a', $case === 'foreign-environment' ? 'live' : 'test',
                $case === 'foreign-installation' ? 'inst-other' : self::INSTALLATION, 'actor'));
            $reads = [
                fn () => $q->paymentStatus($foreign ? 'op-1' : 'missing'),
                fn () => $q->obligation($foreign ? 'obligation-a' : 'missing'),
                fn () => $q->invoice($foreign ? 'invoice-a' : 'missing'),
                fn () => $q->allocationByOperation($foreign ? 'op-1' : 'missing'),
                fn () => $q->receipt($foreign ? 'allocation:allocation-a' : 'missing'),
                fn () => $q->refundAuthorization($foreign ? 'op-1' : 'missing'),
                fn () => $q->dueObligations($foreign ? 'account-a' : 'missing', new DateTimeImmutable('2099-01-01Z')),
            ];
            foreach ($reads as $read) {
                self::problem(ProblemCode::ReferenceUnknown, $read);
            }
        }
    }

    public function test_wire_dispatch_authenticates_scope_and_rejects_read_render(): void
    {
        $this->seedQueryState();
        $q = $this->queries();
        foreach (['sellerRef' => 'seller-b', 'environment' => 'live', 'installationRef' => 'other'] as $key => $value) {
            self::problem(ProblemCode::AuthDenied, fn () => $q->dispatch(self::request('paymentStatus', ['paymentRef' => 'op-1'], [$key => $value])));
        }
        foreach ([['obligation', ['obligationRef' => 'obligation-a']], ['invoice', ['invoiceRef' => 'invoice-a']],
            ['allocationByOperation', ['operationRef' => 'op-1']], ['refundAuthorization', ['paymentRef' => 'op-1']]] as [$query, $selector]) {
            self::assertIsArray($q->dispatch(self::request($query, $selector))['result']);
        }
        self::problem(ProblemCode::ValidationInvalid, fn () => $q->dispatch(self::request('readRender', ['documentRef' => 'document'])));
        self::problem(ProblemCode::ValidationInvalid, fn () => $q->dispatch(self::request('obligation', ['invoiceRef' => 'invoice-a'])));
    }

    public function test_refund_advice_uses_normal_capacity_and_required_release_without_reserving(): void
    {
        $this->seedQueryState();
        $q = $this->queries();
        $before = $this->table('SELECT * FROM billium_payment ORDER BY id');
        foreach ([['30.00', '0.00'], ['40.00', '0.00'], ['50.00', '10.00'], ['100.00', '60.00']] as [$amount, $release]) {
            $advice = $q->refundAuthorization('op-1', Money::parse($amount, 'RUB'));
            self::assertSame('100.00', self::arr($advice['normalCapacity'])['amount']);
            self::assertSame($release, self::arr($advice['requiredReallocation'])['amount']);
            self::assertTrue($advice['allowed']);
            self::assertTrue($advice['advisory']);
        }
        $wire = $q->dispatch(self::request('refundAuthorization', ['paymentRef' => 'op-1', 'amount' => '50.00']));
        self::assertSame('10.00', self::arr(self::arr($wire['result'])['requiredReallocation'])['amount']);
        self::problem(ProblemCode::MoneyCurrencyMismatch, fn () => $q->refundAuthorization('op-1', Money::parse('1.00', 'USD')));
        self::assertSame($before, $this->table('SELECT * FROM billium_payment ORDER BY id'));
    }

    public function test_read_transaction_is_repeatable_read_and_database_rejects_writes(): void
    {
        $this->obligation();
        $reads = $this->application()->make(ReadSession::class);
        $reads->run(function (Connection $db): void {
            self::assertSame('repeatable read', self::field($db->selectOne('SHOW transaction_isolation'), 'transaction_isolation'));
            self::assertSame('on', self::field($db->selectOne('SHOW transaction_read_only'), 'transaction_read_only'));
            $before = $db->table('billium_obligation')->value('revision');
            $this->owner()->update("UPDATE billium_obligation SET due_policy_version='host/2',source_policy_ref='policy',source_policy_snapshot_digest=?,revision=revision+1 WHERE obligation_ref='obligation-a'", [self::digest()]);
            self::assertSame($before, $db->table('billium_obligation')->value('revision'));
        });
        try {
            $reads->run(static fn (Connection $db): bool => $db->insert("INSERT INTO billium_test_effect(seller_ref,environment,ref) VALUES('seller-a','test','query-write')"));
            self::fail('A query write must fail in PostgreSQL');
        } catch (QueryException $error) {
            self::assertSame('25006', $error->errorInfo[0] ?? null);
        }
        self::assertSame(0, $this->rows('billium_test_effect'));
        self::assertSame(0, self::connection('pgsql')->transactionLevel());
        self::connection('pgsql')->transaction(function (): void {
            try {
                $this->queries()->obligation('obligation-a');
                self::fail('Nested query would inherit an unsafe transaction');
            } catch (LogicException $error) {
                self::assertStringContainsString('independent', $error->getMessage());
            }
        });
        self::assertSame(['amount' => '92233720368547758.07', 'currency' => 'RUB'], LedgerView::money(PHP_INT_MAX, 'RUB'));
        self::assertSame('{"empty":{},"list":[]}', json_encode(LedgerView::json('{"list":[],"empty":{}}'), JSON_THROW_ON_ERROR));
    }
}

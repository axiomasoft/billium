<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\Receipt;
use Billium\Core\Enums\FinancialMode;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Contracts\Outbox;
use Billium\Platform\Data\OutboxEvent;
use Billium\Platform\Tests\PlatformTestCase;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/** PF-10/COV-279: the outbox row and its owning state share one root Tx on one connection, or neither exists. */
#[Group('PF-10')]
#[Group('ACC-CON-14')]
final class OutboxRecordTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
    }

    public function test_event_and_state_commit_together_and_survive_a_crash_before_dispatch(): void
    {
        $this->runner()->run($this->spec(key: 'k-event'), function (TransactionContext $context): Receipt {
            $effect = self::effect($context, 'state');
            $this->outbox()->record($context, self::event('3V82FS6B9CH3MQY30QCXP8886K', $effect));
            // A crash between commit and dispatch: only the afterCommit wake-up is lost, never the event.
            $context->afterCommit(static fn () => throw new RuntimeException('process died before dispatch'));

            return self::receipt(['effect' => $effect]);
        });

        // A fresh connection (restart) still sees the committed event with every durable column (COV-086..089).
        $statement = TestEnvironment::pdo()->query("SELECT * FROM billium_outbox_event WHERE event_id = '3V82FS6B9CH3MQY30QCXP8886K'");
        $row = $statement === false ? [] : (array) $statement->fetch();
        self::assertSame('obligation.created.v1', $row['type']);
        self::assertSame('billium.event.v1', $row['schema_version']);
        self::assertSame('obligation:1', $row['aggregate_ref']);
        self::assertSame(3, $row['aggregate_revision']);
        self::assertSame('seller-a', $row['seller_ref']);
        self::assertSame('test', $row['environment']);
        self::assertSame(['amount' => 100, 'currency' => 'RUB'], json_decode(self::text($row['payload']), true));
        self::assertSame('sha256:'.hash('sha256', '{"amount":100,"currency":"RUB"}'), $row['digest']);
        self::assertNotNull($row['created_at']);
        self::assertNotNull($row['tx_id']);
        self::assertSame(1, $this->rows('billium_test_effect'));
    }

    public function test_failure_after_record_leaves_neither_event_nor_state(): void
    {
        try {
            $this->runner()->run($this->spec(key: 'k-fail'), function (TransactionContext $context): Receipt {
                $this->outbox()->record($context, self::event('4XJG5HZSCYX0X56N9G5DHRC85Y', self::effect($context, 'state')));

                throw new ProblemException(ProblemCode::TransportUnavailable);
            });
            self::fail('none-policy failure is thrown');
        } catch (ProblemException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(0, $this->rows('billium_outbox_event'));
        self::assertSame(0, $this->rows('billium_test_effect'));

        // A stored refusal rolls the event back with the body and keeps only the receipt.
        $refused = $this->runner()->run($this->spec(key: 'k-refused'), function (TransactionContext $context): Receipt {
            $this->outbox()->record($context, self::event('5D3AMRQTGP7WJXN0P3VX3QY6T1', self::effect($context, 'state')));

            throw new ProblemException(ProblemCode::InvoiceFrozen);
        });
        self::assertFalse($refused->accepted);
        self::assertSame(0, $this->rows('billium_outbox_event'));
    }

    public function test_record_needs_the_current_root_context_with_platform_admitted(): void
    {
        $stale = null;
        $this->runner()->run($this->spec(), static function (TransactionContext $context) use (&$stale): Receipt {
            $stale = $context;

            return self::receipt();
        });
        self::assertInstanceOf(TransactionContext::class, $stale);
        try {
            $this->outbox()->record($stale, self::event('2BDYD2CNSX9JRCTF2MJ4JV779V', 1));
            self::fail('a stale context cannot write');
        } catch (LogicException $exception) {
            self::assertStringContainsString('Stale', $exception->getMessage());
        }

        try {
            $this->runner()->run($this->spec(mode: FinancialMode::RawCustody, modules: []), function (TransactionContext $context): Receipt {
                $this->outbox()->record($context, self::event('0Z58ZJ4W6K5ZZ5A6E0BSFMASN4', 1));

                return self::receipt();
            });
            self::fail('a context without the platform module cannot write the outbox');
        } catch (LogicException $exception) {
            self::assertStringContainsString('outside the admitted closure', $exception->getMessage());
        }
        self::assertSame(0, $this->rows('billium_outbox_event'));
    }

    public function test_content_is_immutable_unique_and_bounded(): void
    {
        $this->runner()->run($this->spec(), function (TransactionContext $context): Receipt {
            $this->outbox()->record($context, self::event('1CQGEJRCJE8N31G3NSSG3XS9HA', 1));

            return self::receipt();
        });
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), "UPDATE billium_outbox_event SET payload = '{}' WHERE event_id = '1CQGEJRCJE8N31G3NSSG3XS9HA'"));
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), "DELETE FROM billium_outbox_event WHERE event_id = '1CQGEJRCJE8N31G3NSSG3XS9HA'"));
        self::assertSame('42501', self::sqlState(TestEnvironment::pdo(), "UPDATE billium_outbox_event SET type = 'x.y' WHERE event_id = '1CQGEJRCJE8N31G3NSSG3XS9HA'"));

        try {
            $this->runner()->run($this->spec(), function (TransactionContext $context): Receipt {
                $this->outbox()->record($context, self::event('1CQGEJRCJE8N31G3NSSG3XS9HA', 2));

                return self::receipt();
            });
            self::fail('event ids are unique');
        } catch (QueryException $exception) {
            self::assertSame('23505', $exception->errorInfo[0] ?? null);
        }

        try {
            $this->runner()->run($this->spec(), function (TransactionContext $context): Receipt {
                $this->outbox()->record($context, new OutboxEvent('3Y04G13WFY53WTDAQNY3TFJBP6', 'obligation.created.v1', 'billium.event.v1', 'a:1', 1, new DateTimeImmutable, ['blob' => str_repeat('x', 70_000)]));

                return self::receipt();
            });
            self::fail('payload is bounded');
        } catch (LogicException $exception) {
            self::assertStringContainsString('exceeds', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        new OutboxEvent('06X10QWVT7ZGSY74Y76B2FXJJ3', 'NotAType', 'billium.event.v1', 'a:1', 1, new DateTimeImmutable, []);
    }

    private function outbox(): Outbox
    {
        return $this->application()->make(Outbox::class);
    }

    private static function event(string $id, int $effect): OutboxEvent
    {
        return new OutboxEvent($id, 'obligation.created.v1', 'billium.event.v1', 'obligation:'.$effect, 3, new DateTimeImmutable('2026-10-28T10:00:00Z'), ['currency' => 'RUB', 'amount' => 100]);
    }
}

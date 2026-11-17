<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Contract;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\Receipt;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\PaymentDriver;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\PublicationReceipt;
use Packman\Attributes\Api;
use Packman\Attributes\Spi;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ExecutionModuleBootTest extends TestCase
{
    public function test_neutral_contract_surface_autoloads_without_runtime_or_database_boot(): void
    {
        $count = 0;
        foreach (['Contracts', 'Data', 'Enums'] as $section) {
            $files = glob(__DIR__.'/../../src/'.$section.'/*.php');
            self::assertIsArray($files);
            foreach ($files as $file) {
                $type = 'Billium\\Execution\\'.$section.'\\'.basename($file, '.php');
                if (! class_exists($type) && ! interface_exists($type)) {
                    self::fail('Contract type did not autoload: '.$type);
                }
                $reflection = new ReflectionClass($type);
                $surfaces = $reflection->getAttributes(Api::class);
                if ($section === 'Contracts') {
                    $surfaces = [...$surfaces, ...$reflection->getAttributes(Spi::class)];
                }
                self::assertCount(1, $surfaces, $type);
                if ($section === 'Contracts') {
                    self::assertTrue($reflection->isInterface(), $type);
                } elseif ($section === 'Data') {
                    self::assertTrue($reflection->isReadOnly(), $type);
                } else {
                    self::assertTrue($reflection->isEnum(), $type);
                }
                $count++;
            }
        }
        self::assertSame(103, $count);
    }

    public function test_store_mutations_require_the_current_transaction_context(): void
    {
        $store = new ReflectionClass(ExecutionStore::class);
        self::assertCount(58, $store->getMethods());
        $reads = ['plan', 'namespace', 'operation', 'merchantRevision', 'attempt', 'providerWork', 'attemptForOperation', 'providerOperationId', 'operationByProviderId', 'childByIdentity'];
        foreach ($store->getMethods() as $method) {
            if (! in_array($method->name, $reads, true)) {
                self::assertSame(TransactionContext::class, (string) $method->getParameters()[0]->getType(), $method->name);
            }
        }
        self::assertSame('bool', (string) $store->getMethod('startDispatch')->getReturnType());
        self::assertSame('bool', (string) $store->getMethod('finishAttempt')->getReturnType());
        self::assertSame('int', (string) $store->getMethod('nextObservationSequence')->getReturnType());
    }

    public function test_driver_has_explicit_financial_actions_and_read_only_recovery_ports(): void
    {
        $driver = new ReflectionClass(PaymentDriver::class);
        $methods = array_map(static fn ($method): string => $method->name, $driver->getMethods());
        self::assertSame(['contractVersion', 'execute', 'authorize', 'capture', 'voidAuthorization', 'refund', 'lookup', 'inventory', 'normalize'], $methods);
        self::assertNotContains('cancel', $methods);
    }

    public function test_execution_receipt_implements_runner_receipt_and_preserves_its_immutable_body(): void
    {
        $receipt = new ExecutionReceipt('receipt-1', 'command-1', 'operation-1', 'semantic-digest', 'accepted', false, ['evidence-1']);
        self::assertInstanceOf(Receipt::class, $receipt);
        self::assertSame('execution.operation', $receipt->receiptClass());
        self::assertSame([
            'receiptRef' => 'receipt-1',
            'commandKey' => 'command-1',
            'operationRef' => 'operation-1',
            'semanticDigest' => 'semantic-digest',
            'status' => 'accepted',
            'replayed' => false,
            'evidenceRefs' => ['evidence-1'],
        ], $receipt->body());
    }

    public function test_publication_rows_and_sequences_remain_integers_separate_from_command_references(): void
    {
        $receipt = new PublicationReceipt(['observation-command-1'], [42], ['observation-event-1'], [7]);
        self::assertSame([42], $receipt->commandRowIds);
        self::assertSame([7], $receipt->sourceSequences);
        self::assertSame(['observation-command-1'], $receipt->commandRefs);
    }
}

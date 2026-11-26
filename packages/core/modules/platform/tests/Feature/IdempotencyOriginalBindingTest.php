<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Contracts\Idempotency\IdempotencyReceiptQueries;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Platform\Tests\PlatformTestCase;
use InvalidArgumentException;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

#[Group('pg')]
#[Group('COV-130')]
final class IdempotencyOriginalBindingTest extends PlatformTestCase
{
    #[TestWith([true])]
    #[TestWith([false])]
    public function test_original_binding_is_scoped_and_immutable_across_keyed_replay(bool $accepted): void
    {
        $this->activeSession();
        $scope = self::scope();
        $spec = self::command('bound', 'root:original');
        $outcome = $this->runner()->run($spec, static function () use ($accepted): PlatformReceipt {
            if (! $accepted) {
                throw new ProblemException(ProblemCode::OperationFinancialHold, 'original binding on hold');
            }

            return self::receipt(['receiptRef' => 'bound-receipt']);
        });
        self::assertSame($accepted, $outcome->accepted);
        $queries = $this->application()->make(IdempotencyReceiptQueries::class);
        $receipt = $queries->byCommandKey($scope, 'payment.execute.v1', 'bound');
        self::assertNotNull($receipt);
        self::assertSame('root:original', $receipt->originalExecutionBindingRef);
        self::assertSame($outcome->result(), $receipt->outcome->result());
        if ($accepted) {
            $byRef = $queries->byReceiptRef($scope, 'test.receipt', 'bound-receipt');
            self::assertSame('root:original', $byRef?->originalExecutionBindingRef);
        }

        $this->tokens->set(null);
        $unexpected = static fn (): never => self::fail('Retained claim ran its body');
        $replay = $this->runner()->run($spec, $unexpected);
        self::assertTrue($replay->replayed);
        self::assertSame($outcome->receiptId, $replay->receiptId);
        try {
            $this->runner()->run(self::command('bound', 'root:substitute'), $unexpected);
            self::fail('Another binding replayed the retained claim');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::IdempotencyConflict, $exception->problem);
        }
        self::assertSame(1, $this->rows('billium_idempotency_receipt', "command_key='bound' AND original_execution_binding_ref='root:original'"));

        $this->callers->set(new Caller('seller-a', 'test', 'unrelated-installation', 'actor-test'));
        self::assertNull($queries->byCommandKey(new FinancialScope('unrelated-installation', 'seller-a', 'test', null), 'payment.execute.v1', 'bound'));
        try {
            $queries->byCommandKey($scope, 'payment.execute.v1', 'bound');
            self::fail('Foreign caller accessed the original binding');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::AuthDenied, $exception->problem);
        }
    }

    public function test_legacy_claim_preserves_absent_binding_after_ordinary_replay(): void
    {
        $this->activeSession();
        $legacy = $this->runner()->run(self::command('legacy', null), static fn (): PlatformReceipt => self::receipt());
        $replay = $this->runner()->run(self::command('legacy', 'root:unproven'), static fn (): never => self::fail('Legacy claim reran'));
        self::assertTrue($replay->replayed);
        self::assertSame($legacy->receiptId, $replay->receiptId);
        $retained = $this->application()->make(IdempotencyReceiptQueries::class)->byCommandKey(self::scope(), 'payment.execute.v1', 'legacy');
        self::assertNotNull($retained);
        self::assertNull($retained->originalExecutionBindingRef);
    }

    public function test_database_checks_scope_kind_length_and_first_claim_provenance(): void
    {
        $owner = TestEnvironment::pdo(owner: true);
        $scope = json_encode(self::scope(), JSON_THROW_ON_ERROR);
        $insert = <<<'SQL'
            INSERT INTO billium_idempotency_receipt
                (environment, seller_ref, command_kind, command_key, digest, outcome, financial_scope, original_execution_binding_ref)
            VALUES ('test','seller-a',?,?,'claim','in_progress',?::jsonb,?)
            SQL;
        $statement = $owner->prepare($insert);
        self::assertInstanceOf(PDOStatement::class, $statement);
        foreach ([['payment.execute.v1', null, 'root'], ['ledger.test_effect', $scope, 'root'],
            ['payment.execute.v1', $scope, ''], ['payment.execute.v1', $scope, str_repeat('r', 201)]] as [$kind, $selection, $reference]) {
            try {
                $statement->execute([$kind, 'invalid', $selection, $reference]);
                self::fail('Invalid original binding reference reached the database');
            } catch (PDOException $exception) {
                self::assertSame('23514', $exception->getCode());
            }
        }

        $owner->beginTransaction();
        try {
            self::assertTrue($statement->execute(['payment.execute.v1', 'first-claim', $scope, 'root:original']));
            self::assertSame('55000', self::sqlState($owner, <<<'SQL'
                UPDATE billium_idempotency_receipt SET original_execution_binding_ref = 'root:substitute',
                    outcome='accepted', result='{}', completed_at=now() WHERE command_key='first-claim'
                SQL));
        } finally {
            $owner->rollBack();
        }
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_key IN ('invalid','first-claim')"));
    }

    public function test_operation_spec_rejects_reference_that_cannot_be_retained(): void
    {
        foreach (['', str_repeat('r', 201), "root\0bad"] as $reference) {
            try {
                self::command('invalid', $reference);
                self::fail('OperationSpec accepted an invalid original binding');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('original execution binding reference', $exception->getMessage());
            }
        }
    }

    private static function command(string $key, ?string $reference): OperationSpec
    {
        return new OperationSpec('payment.execute.v1', 'seller-a', 'test', FinancialMode::Active, ModuleOperationClass::NewIntent,
            ['ledger', 'platform'], $key, 'unchanged-semantic-digest', financialScope: self::scope(), originalExecutionBindingRef: $reference);
    }

    private static function scope(): FinancialScope
    {
        return new FinancialScope(self::INSTALLATION, 'seller-a', 'test', null);
    }
}

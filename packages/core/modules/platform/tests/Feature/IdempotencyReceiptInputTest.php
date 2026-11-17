<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Contracts\Idempotency\IdempotencyReceiptQueries;
use Billium\Platform\Tests\PlatformTestCase;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

#[Group('pg')]
final class IdempotencyReceiptInputTest extends PlatformTestCase
{
    #[TestWith([true])]
    #[TestWith([false])]
    public function test_exact_scoped_input_survives_received_outcomes_and_changed_input_conflicts(bool $accepted): void
    {
        $this->activeSession();
        $scope = self::scope();
        $input = 'sha256:'.str_repeat('a', 64);
        $stored = $this->runner()->run(self::command($scope, 'checked-input', $input), static function (TransactionContext $context) use ($accepted): Receipt {
            self::effect($context, 'checked-input');
            if (! $accepted) {
                throw new ProblemException(ProblemCode::RefundCapacityExceeded, 'original reservation refused');
            }

            return self::receipt();
        });
        self::assertSame($accepted, $stored->accepted);
        self::assertFalse($stored->replayed);
        $queries = $this->application()->make(IdempotencyReceiptQueries::class);
        $read = $queries->byCommandKey($scope, 'ledger.test_effect', 'checked-input');
        self::assertNotNull($read);
        self::assertSame($input, $read->inputDigest);
        self::assertSame('original-claim-digest', $read->semanticDigest);
        self::assertSame($stored->receiptId, $read->outcome->receiptId);
        self::assertSame($stored->result(), $read->outcome->result());

        $this->tokens->set(null);
        $unexpectedBody = static fn (): never => self::fail('A retained input replay or conflict must not run the command body');
        $replayed = $this->runner()->run(self::command($scope, 'checked-input', $input), $unexpectedBody);
        self::assertTrue($replayed->replayed);
        self::assertSame($stored->receiptId, $replayed->receiptId);
        self::assertSame($stored->result(), $replayed->result());
        try {
            $this->runner()->run(self::command($scope, 'checked-input', 'sha256:'.str_repeat('b', 64)), $unexpectedBody);
            self::fail('A different canonical input replayed the original outcome');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::IdempotencyConflict, $exception->problem);
        }
        self::assertSame(1, $this->rows('billium_idempotency_receipt', 'command_key = ?', ['checked-input']));
        self::assertSame($accepted ? 1 : 0, $this->rows('billium_test_effect'));

        $this->callers->set(new Caller('seller-a', 'test', 'another-installation', 'actor-1'));
        self::assertNull($queries->byCommandKey(new FinancialScope('another-installation', 'seller-a', 'test', null),
            'ledger.test_effect', 'checked-input'));
    }

    public function test_legacy_scoped_key_keeps_null_provenance_after_an_ordinary_replay(): void
    {
        $this->activeSession();
        $scope = self::scope();
        $first = $this->runner()->run(self::command($scope, 'old-input', null), static fn (): Receipt => self::receipt());
        $this->runner()->run($this->spec(key: 'unscoped-input'), static fn (): Receipt => self::receipt());
        $again = $this->runner()->run(self::command($scope, 'old-input', 'sha256:'.str_repeat('a', 64)),
            static fn (): never => self::fail('The ordinary claim digest should retain its legacy replay'));
        self::assertTrue($again->replayed);
        self::assertSame($first->receiptId, $again->receiptId);
        $read = $this->application()->make(IdempotencyReceiptQueries::class)->byCommandKey($scope, 'ledger.test_effect', 'old-input');
        self::assertNotNull($read);
        self::assertNull($read->inputDigest);
        self::assertNull($this->value("SELECT input_digest FROM billium_idempotency_receipt WHERE command_key = 'unscoped-input'"));
    }

    public function test_receipt_input_is_scoped_and_the_first_claim_is_immutable_before_completion(): void
    {
        $owner = TestEnvironment::pdo(owner: true);
        $insert = <<<'SQL'
            INSERT INTO billium_idempotency_receipt
                (environment, seller_ref, command_kind, command_key, digest, outcome, financial_scope, input_digest)
            VALUES ('test', 'seller-a', 'ledger.test_effect', ?, 'claim', 'in_progress', ?::jsonb, ?)
            SQL;
        $scope = json_encode(self::scope(), JSON_THROW_ON_ERROR);
        $input = 'sha256:'.str_repeat('a', 64);
        self::assertSame('23514', self::sqlState($owner, sprintf(
            "INSERT INTO billium_idempotency_receipt (environment, seller_ref, command_kind, command_key, digest, outcome, input_digest)
            VALUES ('test', 'seller-a', 'ledger.test_effect', 'unscoped', 'claim', 'in_progress', '%s')", $input,
        )));
        $owner->beginTransaction();
        try {
            $statement = $owner->prepare($insert);
            self::assertInstanceOf(PDOStatement::class, $statement);
            self::assertTrue($statement->execute(['uncommitted', $scope, $input]));
            self::assertSame('55000', self::sqlState($owner, <<<'SQL'
                UPDATE billium_idempotency_receipt
                SET outcome = 'accepted', result = '{}', completed_at = now(), input_digest = NULL
                WHERE command_key = 'uncommitted'
                SQL));
        } finally {
            $owner->rollBack();
        }
        self::assertSame(0, $this->rows('billium_idempotency_receipt', 'command_key = ?', ['uncommitted']));
    }

    private static function command(FinancialScope $scope, string $key, ?string $inputDigest): OperationSpec
    {
        return new OperationSpec('ledger.test_effect', $scope->sellerRef ?? self::fail('Test seller must be selected'),
            $scope->environment ?? self::fail('Test environment must be selected'), FinancialMode::Active,
            ModuleOperationClass::NewIntent, ['ledger', 'platform'], $key, 'original-claim-digest', 'ledger.test_effect', $scope, $inputDigest);
    }

    private static function scope(): FinancialScope
    {
        return new FinancialScope(self::INSTALLATION, 'seller-a', 'test', null);
    }
}

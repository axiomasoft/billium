<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Platform\Tests\PlatformTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class RunnerSavepointAfterCommitTest extends PlatformTestCase
{
    public function test_after_commit_callback_is_not_invoked_when_body_rolls_back_to_savepoint_for_stored_refusal(): void
    {
        $this->activeSession();
        $afterCommitCalls = 0;
        $runner = $this->application()->make(OperationRunner::class);

        $spec = new OperationSpec(
            commandKind: 'test.savepoint.refusal',
            sellerRef: 'seller-a',
            environment: 'test',
            mode: FinancialMode::Administrative,
            operationClass: ModuleOperationClass::NewIntent,
            modules: ['platform'],
            commandKey: 'savepoint-refusal-1',
            digest: 'sha256:'.hash('sha256', 'savepoint-refusal-1'),
        );

        $outcome = $runner->run($spec, static function (TransactionContext $context) use (&$afterCommitCalls): Receipt {
            $context->afterCommit(static function () use (&$afterCommitCalls): void {
                $afterCommitCalls++;
            });

            throw new ProblemException(ProblemCode::RefundCapacityExceeded, 'body rolled back to savepoint');
        });

        self::assertFalse($outcome->accepted);
        self::assertSame(ProblemCode::RefundCapacityExceeded, $outcome->problem);
        self::assertSame(0, $afterCommitCalls);

        // Accepted outcome DOES invoke afterCommit after transaction commit
        $acceptedSpec = new OperationSpec(
            commandKind: 'test.savepoint.accepted',
            sellerRef: 'seller-a',
            environment: 'test',
            mode: FinancialMode::Administrative,
            operationClass: ModuleOperationClass::NewIntent,
            modules: ['platform'],
            commandKey: 'savepoint-accepted-1',
            digest: 'sha256:'.hash('sha256', 'savepoint-accepted-1'),
        );

        $acceptedOutcome = $runner->run($acceptedSpec, static function (TransactionContext $context) use (&$afterCommitCalls): Receipt {
            $context->afterCommit(static function () use (&$afterCommitCalls): void {
                $afterCommitCalls++;
            });

            return new class implements Receipt
            {
                public function receiptClass(): string
                {
                    return 'test.accepted';
                }

                /** @return array<string, mixed> */
                public function body(): array
                {
                    return ['ok' => true];
                }
            };
        });

        self::assertTrue($acceptedOutcome->accepted);
        self::assertSame(1, $afterCommitCalls);
    }
}

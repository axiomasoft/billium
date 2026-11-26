<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Migration;

use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use ValueError;

/** N−1 is the current dispatch implementation, exercised against a forward-expanded fixture model of N. */
#[Group('pg')]
#[Group('P9.7')]
final class MixedWorkerDrillTest extends ExecutionTestCase
{
    #[TestWith(['status'], 'unknown-status')]
    #[TestWith(['kind'], 'unknown-operation')]
    public function test_mgate_08_old_worker_refuses_unknown_rows_before_provider_dispatch(string $field): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            Fixture::seedMerchant($app);
            Fixture::host($app, $path);
            $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
            $commits = $this->rows('billium_money_commit_log');
            $this->expandFixture($field);
            try {
                $worker = new Process([PHP_BINARY, '-r', 'require "vendor/autoload.php"; '.self::class.'::oldWorker($argv[1]);', $path],
                    env: ['DB_CONNECTION' => 'pgsql']);
                $worker->mustRun();
                self::assertStringContainsString('fixture_future_'.$field, $worker->getOutput());
                self::assertSame(0, UnknownResultTest::events($path, 'provider.call'));
                self::assertSame(0, UnknownResultTest::events($path, 'provider.lookup'));
                self::assertSame(0, $this->rows('billium_execution_attempt', 'lease_fence > 0 OR dispatch_ticket_ref IS NOT NULL'));
                self::assertSame($commits, $this->rows('billium_money_commit_log'));
            } finally {
                $this->restoreFixture($field);
            }
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public static function oldWorker(string $path): void
    {
        $app = Fixture::workerApp(808);
        Fixture::host($app, $path);
        try {
            $app->make(AttemptWorker::class)->dispatch(Fixture::scope(), UnknownResultTest::attemptRef(), 'n-minus-one', new DateTimeImmutable('+1 minute'));
            self::fail('Previous worker processed an unsupported row');
        } catch (ValueError $refused) {
            fwrite(STDOUT, $refused->getMessage());
        }
    }

    private function expandFixture(string $field): void
    {
        $this->owner()->transaction(function () use ($field): void {
            if ($field === 'status') {
                $this->owner()->unprepared(<<<'SQL'
                    ALTER TABLE billium_execution_attempt RENAME CONSTRAINT billium_execution_attempt_state_check TO drill_previous_state;
                    ALTER TABLE billium_execution_attempt DROP CONSTRAINT drill_previous_state,
                        ADD CONSTRAINT billium_execution_attempt_state_check
                        CHECK (state IN ('admitted','dispatching','pending','unknown','succeeded','failed','cancelled','fixture_future_status'));
                    UPDATE billium_execution_attempt SET state = 'fixture_future_status' WHERE operation_ref = 'root-a';
                    SQL);

                return;
            }
            // Seed an N-only discriminator on a synthetic operation. The fixture owner temporarily suspends the
            // identity trigger inside this transaction. Application roles never receive that privilege.
            $this->owner()->unprepared(<<<'SQL'
                ALTER TABLE billium_execution_operation DROP CONSTRAINT billium_execution_operation_kind_check,
                    DROP CONSTRAINT billium_execution_operation_check1,
                    ADD CONSTRAINT billium_execution_operation_kind_check
                        CHECK (kind IN ('charge','authorize','capture','void_authorization','refund','fixture_future_kind')),
                    ADD CONSTRAINT billium_execution_operation_check1
                        CHECK ((kind IN ('charge','authorize','fixture_future_kind')) = (operation_ref = root_operation_ref)),
                    DISABLE TRIGGER billium_execution_operation_immutable;
                UPDATE billium_execution_operation SET kind = 'fixture_future_kind' WHERE operation_ref = 'root-a';
                ALTER TABLE billium_execution_operation ENABLE TRIGGER billium_execution_operation_immutable;
                SQL);
        });
    }

    /** Dispose the synthetic future values before restoring the test stand's schema. */
    private function restoreFixture(string $field): void
    {
        $this->owner()->transaction(function () use ($field): void {
            if ($field === 'status') {
                $this->owner()->unprepared(<<<'SQL'
                    UPDATE billium_execution_attempt SET state = 'admitted' WHERE state = 'fixture_future_status';
                    ALTER TABLE billium_execution_attempt DROP CONSTRAINT billium_execution_attempt_state_check,
                        ADD CONSTRAINT billium_execution_attempt_state_check
                        CHECK (state IN ('admitted','dispatching','pending','unknown','succeeded','failed','cancelled'));
                    SQL);

                return;
            }
            $this->owner()->unprepared(<<<'SQL'
                ALTER TABLE billium_execution_operation DISABLE TRIGGER billium_execution_operation_immutable;
                UPDATE billium_execution_operation SET kind = 'charge' WHERE kind = 'fixture_future_kind';
                ALTER TABLE billium_execution_operation DROP CONSTRAINT billium_execution_operation_kind_check,
                    DROP CONSTRAINT billium_execution_operation_check1,
                    ADD CONSTRAINT billium_execution_operation_kind_check
                        CHECK (kind IN ('charge','authorize','capture','void_authorization','refund')),
                    ADD CONSTRAINT billium_execution_operation_check1
                        CHECK ((kind IN ('charge','authorize')) = (operation_ref = root_operation_ref)),
                    ENABLE TRIGGER billium_execution_operation_immutable;
                SQL);
        });
    }
}

<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Concurrency;

use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\OriginDecision;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\CallbackCustodyTest;
use Billium\Platform\Contracts\Outbox\FactualCommandOutbox;
use Billium\Platform\Data\EventContracts\PreparedFactualCommand;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessBarrier;
use Billium\Testkit\Fixtures\ProcessIdentity;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
final class ObservationPartitionCounterTest extends ExecutionTestCase
{
    public function test_twenty_same_key_admissions_commit_one_zero_sequence_partition_before_dispatch(): void
    {
        $path = Fixture::tempPath();
        $workers = [];
        try {
            Fixture::seedMerchant($this->application());
            $control = new DurableJournal($path.'.control');
            $barrier = new ProcessBarrier($control);
            for ($worker = 0; $worker < 20; $worker++) {
                $workers[] = new ChildProcess([PHP_BINARY, '-r',
                    'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Concurrency\\AdmissionIdentityTest::process((int)$argv[1], $argv[2], "same");',
                    (string) $worker, $path]);
            }
            $barrier->await('ready', 20, 45_000);
            $barrier->arrive('release');
            while (($worker = array_pop($workers)) !== null) {
                self::assertSame(0, $worker->wait());
            }
            $outcomes = array_values(array_filter($control->records(), static fn (object $entry): bool => $entry instanceof JournalEvent && $entry->kind === 'result'));
            self::assertCount(20, $outcomes);
            self::assertCount(20, array_unique(array_map(static fn (JournalEvent $e): int => $e->pid, $outcomes)));
            self::assertCount(20, array_unique(array_map(static fn (JournalEvent $e): mixed => $e->data['backend'], $outcomes)));
            self::assertSame(['accepted'], array_values(array_unique(array_map(static fn (JournalEvent $e): mixed => $e->data['result'], $outcomes))));
            self::assertSame(1, $this->rows('billium_execution_observation_partition', "root_operation_ref='root-a' AND last_sequence=0"));
            self::assertSame(1, $this->rows('billium_execution_observation_partition'));
            self::assertSame(1, $this->rows('billium_execution_operation'));
            self::assertSame(1, $this->rows('billium_execution_attempt'));
            self::assertSame(0, $this->rows('billium_execution_observation_emission'));
            self::assertSame(0, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            foreach ($workers as $worker) {
                $worker->kill();
            }
            UnknownResultTest::clean($path);
        }
    }

    public function test_two_root_partitions_advance_on_distinct_backends_and_redelivery_consumes_no_sequence(): void
    {
        $path = Fixture::tempPath();
        $owner = $this->owner();
        $workers = [];
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            Fixture::seedMerchant($app);
            Fixture::host($app, $path, [Fixture::origin(), new OriginDecision($scope, 'root-b', 'intent-b', 'checkout', 'remote-billium', 1,
                null, 'sha256:'.str_repeat('b', 64), 'terms-v1', 'period-a')]);
            foreach (['a', 'b'] as $index => $suffix) {
                $ref = 'root-'.$suffix;
                $app->make(RootPaymentCommands::class)->admit($scope, Fixture::wire(['commandKey' => Fixture::key($index + 1),
                    'operationRef' => $ref, 'source' => ['context' => 'checkout', 'intentRef' => 'intent-'.$suffix]]))->orThrow();
                self::assertSame(1, $this->rows('billium_execution_observation_partition', 'root_operation_ref=? AND last_sequence=0', [$ref]));
                $app->make(AttemptWorker::class)->dispatch($scope, PaymentIdentity::reference($scope, $ref, 'attempt'), 'prepare-'.$suffix,
                    new DateTimeImmutable('+1 minute'))->orThrow();
            }
            $works = [];
            foreach (['a', 'b'] as $suffix) {
                $works[$suffix] = self::text($this->value(<<<'SQL'
                    SELECT w.work_ref FROM billium_execution_provider_work w JOIN billium_execution_provider_evidence e
                      USING (installation_ref,seller_ref,environment,evidence_ref)
                    WHERE w.operation_hint = ? AND e.signature_verdict = 'simulated-valid'
                    SQL, ['root-'.$suffix]));
                $workers[] = new ChildProcess([PHP_BINARY, '-r',
                    'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Concurrency\\ObservationPartitionCounterTest::reducerProcess($argv[1], $argv[2], $argv[3]);',
                    $path, $suffix, $works[$suffix]]);
            }
            $control = new DurableJournal($path.'.control');
            $barrier = new ProcessBarrier($control);
            $barrier->await('ready', 2, 20_000);
            $owner->beginTransaction();
            $owner->select("SELECT id FROM billium_execution_observation_partition WHERE root_operation_ref = 'root-a' FOR UPDATE");
            $barrier->arrive('launch');
            $barrier->await('finished-b', 1, 20_000);
            self::assertSame(0, $this->rows('billium_execution_observation_emission', "operation_ref='root-a'"));
            self::assertSame(1, $this->rows('billium_execution_observation_emission', "operation_ref='root-b' AND source_sequence=1"));
            $owner->commit();
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait());
            }
            $results = [];
            foreach ($control->records() as $entry) {
                if ($entry instanceof JournalEvent && $entry->kind === 'reduction') {
                    $results[] = $entry;
                }
            }
            self::assertCount(2, $results);
            self::assertNotSame($results[0]->pid, $results[1]->pid);
            self::assertNotSame($results[0]->data['backend'], $results[1]->data['backend']);
            self::assertSame(['b', 'a'], array_map(static fn (JournalEvent $result): mixed => $result->data['suffix'], $results));
            foreach ($results as $entry) {
                self::assertSame('applied', $entry->data['status']);
            }
            self::assertSame(2, $this->rows('billium_execution_observation_partition', 'last_sequence=1'));
            self::assertSame(2, $this->rows('billium_execution_observation_emission', 'source_sequence=1'));
            self::assertSame(2, $this->value('SELECT count(DISTINCT partition_ref) FROM billium_execution_observation_emission'));
            self::assertSame(1, $this->value('SELECT count(DISTINCT source_lineage) FROM billium_execution_observation_emission'));
            foreach ($works as $work) {
                self::assertSame(ReductionDisposition::Duplicate, $app->make(EvidenceReducer::class)->reduce($work)->disposition);
            }
            self::assertSame(2, $this->rows('billium_outbox_command'));
            self::assertSame(2, $this->rows('billium_execution_observation_emission'));
            self::assertSame(2, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }
            UnknownResultTest::clean($path);
        }
    }

    public function test_rollback_after_record_reuses_first_partition_sequence_on_the_same_work(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $evidence = CallbackCustodyTest::dispatched($app, $path);
            $work = CallbackCustodyTest::work($evidence);
            $outbox = $app->make(FactualCommandOutbox::class);
            $app->instance(FactualCommandOutbox::class, new readonly class($outbox) implements FactualCommandOutbox
            {
                public function __construct(private FactualCommandOutbox $outbox) {}

                public function record(TransactionContext $context, PreparedFactualCommand $command): int
                {
                    $this->outbox->record($context, $command);
                    throw new RuntimeException('failure_after_factual_outbox');
                }
            });
            try {
                $app->make(EvidenceReducer::class)->reduce($work);
                self::fail('Reduction committed after the injected failure');
            } catch (RuntimeException $failure) {
                self::assertSame('failure_after_factual_outbox', $failure->getMessage());
            }
            self::assertSame(0, $this->rows('billium_execution_observation_partition', 'last_sequence<>0'));
            self::assertSame(0, $this->rows('billium_execution_observation_emission'));
            self::assertSame(0, $this->rows('billium_outbox_command'));
            self::assertSame(0, $this->rows('billium_execution_operation_revision'));
            self::assertSame(1, $this->rows('billium_execution_provider_work', "state='due' AND due_at>clock_timestamp() AND last_error_code='financial_processing_deferred'"));
            $this->owner()->update("UPDATE billium_execution_provider_work SET due_at = clock_timestamp() - interval '1 second' WHERE state = 'due' AND work_ref=?", [$work]);
            $app->instance(FactualCommandOutbox::class, $outbox);
            $app->forgetInstance(EvidenceReducer::class);
            $reduction = $app->make(EvidenceReducer::class)->reduce($work);
            self::assertSame(ReductionDisposition::Applied, $reduction->disposition);
            self::assertCount(1, $reduction->observationCommandRefs);
            self::assertSame(1, $this->rows('billium_execution_observation_partition', 'last_sequence=1'));
            self::assertSame(1, $this->rows('billium_execution_observation_emission', 'source_sequence=1'));
            self::assertSame(1, $this->rows('billium_outbox_command'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public static function reducerProcess(string $path, string $suffix, string $work): void
    {
        $app = Fixture::workerApp($suffix === 'a' ? 503 : 504);
        Fixture::host($app, $path);
        $control = new DurableJournal($path.'.control');
        $barrier = new ProcessBarrier($control);
        $db = $app->make(DatabaseManager::class)->connection();
        $barrier->arrive('ready');
        $barrier->await('launch', 1, 20_000);
        $result = $app->make(EvidenceReducer::class)->reduce($work);
        $control->append(new JournalEvent('reduction', ['suffix' => $suffix, 'status' => $result->disposition->value,
            'backend' => self::integer($db->scalar('SELECT pg_backend_pid()'))], ProcessIdentity::current()));
        $barrier->arrive('finished-'.$suffix);
    }

    public function test_simultaneous_claims_for_the_same_work_fence_one_winner(): void
    {
        $path = Fixture::tempPath();
        $workers = [];
        try {
            $provider = CallbackCustodyTest::dispatched($this->application(), $path);
            $work = CallbackCustodyTest::work($provider);
            $journal = new DurableJournal($path.'.control');
            $barrier = new ProcessBarrier($journal);
            foreach ([508, 509] as $index) {
                $workers[] = new ChildProcess([PHP_BINARY, '-r',
                    'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Concurrency\\ObservationPartitionCounterTest::claimProcess($argv[1], $argv[2], (int) $argv[3]);',
                    $path, $work, (string) $index]);
            }
            $barrier->await('ready', 2, 20_000);
            $barrier->arrive('launch');
            while (($worker = array_pop($workers)) !== null) {
                self::assertSame(0, $worker->wait());
            }
            $claims = array_values(array_filter($journal->records(), static fn (object $event): bool => $event instanceof JournalEvent && $event->kind === 'claim'));
            self::assertCount(2, $claims);
            self::assertNotSame($claims[0]->pid, $claims[1]->pid);
            self::assertNotSame($claims[0]->data['backend'], $claims[1]->data['backend']);
            self::assertCount(1, array_filter($claims, static fn (JournalEvent $event): bool => $event->data['claimed'] === true));
            self::assertSame(1, $this->rows('billium_execution_provider_work', "work_ref=? AND state='leased' AND attempt_count=1 AND lease_fence=1", [$work]));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            foreach ($workers as $worker) {
                $worker->kill();
            }
            UnknownResultTest::clean($path);
        }
    }

    public static function claimProcess(string $path, string $work, int $index): void
    {
        $app = Fixture::workerApp($index);
        $control = new DurableJournal($path.'.control');
        $barrier = new ProcessBarrier($control);
        $scope = Fixture::scope();
        $store = $app->make(ExecutionStore::class);
        $runner = $app->make(OperationRunner::class);
        $spec = new OperationSpec('execution.test_work_cas', $scope->sellerRef, $scope->environment, FinancialMode::RawCustody,
            ModuleOperationClass::CallbackCustody, ['execution', 'platform'], financialScope: new FinancialScope(
                $scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace));
        $barrier->arrive('ready');
        $barrier->await('launch', 1, 20_000);
        $result = $runner->run($spec, static fn (TransactionContext $ctx): PlatformReceipt => self::receipt([
            'claimed' => $store->claimProviderWork($ctx, $work, 'claim-'.$index, new DateTimeImmutable('+1 minute')) !== null,
        ]))->orThrow();
        $db = $app->make(DatabaseManager::class)->connection();
        $control->append(new JournalEvent('claim', ['claimed' => $result->body['claimed'] === true,
            'backend' => self::integer($db->scalar('SELECT pg_backend_pid()'))], ProcessIdentity::current()));
    }
}

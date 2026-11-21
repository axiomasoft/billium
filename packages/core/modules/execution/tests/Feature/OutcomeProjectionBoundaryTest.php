<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\AuthorizePayment;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Data\Envelopes\ExecutionStatus;
use Billium\Contracts\Data\Envelopes\FindResult;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\TestEnvironment;
use Billium\Execution\Contracts\ExecutionHistoryQueries;
use Billium\Execution\Contracts\ExecutionQueries;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Tests\CollectionAdmissionGuardTest;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessIdentity;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

#[Group('pg')]
final class OutcomeProjectionBoundaryTest extends ExecutionTestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->make(Repository::class)->set('billium.modules.enabled', ['platform', 'ledger', 'execution', 'collection']);
    }

    public function test_cold_authorized_reads_return_original_business_key_receipt_and_conflict_audit(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            Fixture::seedMerchant($app);
            Fixture::host($app, $path);
            $scope = Fixture::scope();
            $wire = Fixture::wire(['schema' => AuthorizePayment::SCHEMA]);
            $commands = $app->make(RootPaymentCommands::class);
            $admission = $commands->admit($scope, $wire)->orThrow();
            $receiptRef = self::text($admission->body['receiptRef']);
            $this->assertProblem(ProblemCode::IdempotencyConflict, fn () => $commands->admit($scope, Fixture::wire([
                'schema' => AuthorizePayment::SCHEMA, 'money' => ['amount' => '1.01', 'currency' => 'RUB'],
            ]))->orThrow());
            self::assertTrue($commands->admit($scope, $wire)->orThrow()->replayed);
            $first = self::cold($path, $receiptRef);
            self::assertNotSame(getmypid(), $first->pid);
            self::assertNotSame($this->value('SELECT pg_backend_pid()'), $first->data['backend']);
            $data = self::arr(json_decode(self::text($first->data['snapshot']), true, 64, JSON_THROW_ON_ERROR));
            self::assertSame(['inst-test', 'seller-a', 'test', 'source-a', 'remote-billium', 'checkout', 'intent-a'], $data['scope']);
            self::assertSame(['authorize', 'admitted', 'RUB', 100, 0], $data['operation']);
            self::assertSame(['merchant-a', 'account-a', 'simulated-driver.v1', 'execution.intent.v1'], $data['binding']);
            self::assertSame('execution-business-identity-indefinite-v1', $data['policy']);
            self::assertNull($data['deadline']);
            self::assertSame($admission->body['semanticDigest'], $data['businessDigest']);
            self::assertSame($data['businessDigest'], $data['economicDigest']);
            self::assertSame($this->value('SELECT request_digest FROM billium_execution_attempt'), $data['requestDigest']);
            self::assertSame($this->value('SELECT created_at::text FROM billium_execution_business_binding'), $data['firstSeen']);
            self::assertSame($this->value('SELECT command_key FROM billium_idempotency_receipt WHERE command_kind = ?', [AuthorizePayment::SCHEMA]), $data['keyDigest']);
            self::assertSame($this->value('SELECT digest FROM billium_idempotency_receipt WHERE command_kind = ?', [AuthorizePayment::SCHEMA]), $data['receiptDigest']);
            self::assertSame($this->value('SELECT canonicalization_version FROM billium_idempotency_receipt WHERE command_kind = ?', [AuthorizePayment::SCHEMA]), $data['receiptCanonicalization']);
            self::assertSame('jcs-rfc8785+sha256', $data['receiptCanonicalization']);
            self::assertSame($this->value('SELECT expires_at::text FROM billium_idempotency_receipt WHERE command_kind = ?', [AuthorizePayment::SCHEMA]), $data['receiptExpiry']);
            self::assertNull($data['receiptExpiry']);
            self::assertSame($this->value('SELECT original_execution_binding_ref FROM billium_idempotency_receipt WHERE command_kind = ?', [AuthorizePayment::SCHEMA]), $data['originalExecutionBindingRef']);
            self::assertSame('root-a', $data['originalExecutionBindingRef']);
            self::assertSame($this->value('SELECT created_at::text FROM billium_idempotency_receipt WHERE command_kind = ?', [AuthorizePayment::SCHEMA]), $data['claimFirstSeen']);
            self::assertSame($this->value('SELECT completed_at::text FROM billium_idempotency_receipt WHERE command_kind = ?', [AuthorizePayment::SCHEMA]), $data['claimCompleted']);
            self::assertTrue($data['accepted']);
            self::assertFalse($data['replayed']);
            self::assertEquals($admission->body, $data['storedReceipt']);
            self::assertEquals($admission->body, $data['receiptByRef']);
            self::assertSame($receiptRef, $data['businessReceipt']);
            self::assertSame($receiptRef, $data['lookupReceipt']);
            self::assertSame('pending', $data['findStatus']);
            self::assertSame('pending', $data['status']);
            self::assertSame([true, false, false, false], $data['checkpoints']);
            self::assertSame([ProblemCode::IdempotencyConflict->value, 'actor-1', 'root-a', $data['keyDigest']], $data['conflict']);
            self::assertSame($this->value('SELECT semantic_digest FROM billium_execution_admission_conflict'), $data['attemptedDigest']);
            self::assertSame($this->value('SELECT recorded_at::text FROM billium_execution_admission_conflict'), $data['conflictTime']);

            try {
                $this->owner()->update("UPDATE billium_execution_admission_conflict SET actor_ref='changed'");
                self::fail('Conflict audit was mutable');
            } catch (QueryException $exception) {
                self::assertSame('55000', $exception->errorInfo[0] ?? null);
            }

            // Simulate custody pruning by the fixture owner. The original business identity remains independently readable.
            $this->owner()->statement('TRUNCATE billium_idempotency_receipt');
            $history = $app->make(ExecutionHistoryQueries::class);
            $original = $history->business($scope, 'remote-billium', 'checkout', 'intent-a');
            self::assertSame($receiptRef, $original->receiptRef);
            self::assertSame($data['businessDigest'], $original->binding->semanticDigest);
            $this->assertProblem(ProblemCode::ReferenceUnknown, fn () => $history->receiptByCommandKey($scope, 'remote-billium', AuthorizePayment::SCHEMA, 'checkout', $wire->commandKey));
            $fresh = $commands->admit($scope, Fixture::wire(['schema' => AuthorizePayment::SCHEMA, 'commandKey' => Fixture::key(57)]))->orThrow();
            self::assertSame($receiptRef, $fresh->body['receiptRef']);
            self::assertSame(1, $this->rows('billium_execution_attempt'));
            self::assertSame(0, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    private static function cold(string $path, string $receiptRef): JournalEvent
    {
        $worker = new ChildProcess([PHP_BINARY, '-r', 'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Feature\\OutcomeProjectionBoundaryTest::readInNewProcess($argv[1], $argv[2]);', $path, $receiptRef]);
        self::assertSame(0, $worker->wait());
        $event = array_find(new DurableJournal($path)->records(), static fn (object $record): bool => $record instanceof JournalEvent && $record->kind === 'cold-query');
        self::assertInstanceOf(JournalEvent::class, $event);

        return $event;
    }

    public static function readInNewProcess(string $path, string $receiptRef): void
    {
        $app = Fixture::workerApp(500);
        $scope = Fixture::scope();
        $history = $app->make(ExecutionHistoryQueries::class);
        $queries = $app->make(ExecutionQueries::class);
        $business = $history->business($scope, 'remote-billium', 'checkout', 'intent-a');
        $stored = $history->receiptByCommandKey($scope, 'remote-billium', AuthorizePayment::SCHEMA, 'checkout', Fixture::key(1));
        $audits = $history->admissionConflicts($scope, 'remote-billium', AuthorizePayment::SCHEMA, 'checkout', Fixture::key(1));
        self::assertCount(1, $audits);
        $audit = $audits[0];
        $lookup = $queries->receipt($scope, $receiptRef);
        $find = $queries->find($scope, 'root-a');
        $status = $queries->status($scope, 'root-a');
        $codec = new Codec;
        self::assertInstanceOf(ExecutionStatus::class, $codec->decode($codec->encode($status)));
        self::assertInstanceOf(FindResult::class, $codec->decode($codec->encode($find)));
        $binding = $business->binding;
        $db = $app->make(DatabaseManager::class)->connection();
        $time = static fn (DateTimeImmutable $date): string => rtrim(rtrim($date->format('Y-m-d H:i:s.u'), '0'), '.').$date->format('P');
        $pgTime = static fn (DateTimeImmutable $date): string => str_replace('+00:00', '+00', $time($date));
        $snapshot = [
            'scope' => [$binding->scope->installationRef, $binding->scope->sellerRef, $binding->scope->environment, $binding->scope->intentOwnerNamespace,
                $binding->executionProfile, $binding->sourceContext, $binding->businessIntentRef],
            'binding' => [$binding->merchantRevisionRef, $binding->merchantAccountRef, $binding->driverContractVersion, $binding->canonicalizationVersion],
            'operation' => [$business->kind->value, $business->state, $business->requested->currency->code, $business->requested->minor, $business->revision],
            'businessDigest' => $binding->semanticDigest, 'economicDigest' => $business->economicDigest, 'requestDigest' => $business->dispatchRequestDigest,
            'firstSeen' => $pgTime($business->firstSeenAt), 'policy' => $business->retentionPolicy, 'deadline' => $business->retentionDeadlineAt,
            'keyDigest' => $stored->commandKeyDigest, 'receiptDigest' => $stored->semanticDigest,
            'receiptCanonicalization' => $stored->canonicalizationVersion, 'receiptExpiry' => $stored->expiresAt === null ? null : $pgTime($stored->expiresAt),
            'originalExecutionBindingRef' => $stored->originalExecutionBindingRef,
            'claimFirstSeen' => $pgTime($stored->firstSeenAt), 'claimCompleted' => $pgTime($stored->completedAt),
            'accepted' => $stored->outcome->accepted, 'replayed' => $stored->outcome->replayed, 'storedReceipt' => $stored->outcome->body,
            'businessReceipt' => $business->receiptRef, 'lookupReceipt' => $lookup->receiptRef,
            'receiptByRef' => $lookup->body(),
            'findStatus' => $find->status, 'status' => $status->status, 'checkpoints' => [$status->delivery->commandAccepted, $status->delivery->providerOutcomeRecorded,
                $status->delivery->projectionApplied, $status->delivery->allocationApplied],
            'conflict' => [$audit->problem->value, $audit->actorRef, $audit->originalOperationRef, $audit->commandKeyDigest],
            'attemptedDigest' => $audit->semanticDigest, 'conflictTime' => $pgTime($audit->recordedAt),
        ];
        new DurableJournal($path)->append(new JournalEvent('cold-query', ['backend' => self::integer($db->scalar('SELECT pg_backend_pid()')),
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)], ProcessIdentity::current()));
    }

    #[Group('PB-17')]
    public function test_authorized_foreign_selectors_cannot_use_a_global_identity_collision_to_read_history(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            Fixture::seedMerchant($app);
            Fixture::host($app, $path);
            $commands = $app->make(RootPaymentCommands::class);
            $accepted = $commands->admit($scope, Fixture::wire())->orThrow();
            $receipt = self::text($accepted->body['receiptRef']);
            $this->assertProblem(ProblemCode::IdempotencyConflict, fn () => $commands->admit($scope, Fixture::wire(['money' => ['amount' => '1.01', 'currency' => 'RUB']]))->orThrow());
            $history = $app->make(ExecutionHistoryQueries::class);
            $queries = $app->make(ExecutionQueries::class);
            foreach ([
                [new ExecutionScope($scope->installationRef, 'seller-other', 'test', 'source-a'), 'remote-billium', 'checkout'],
                [new ExecutionScope('reinstalled', 'seller-a', 'test', 'source-a'), 'remote-billium', 'checkout'],
                [new ExecutionScope($scope->installationRef, 'seller-a', 'live', 'source-a'), 'remote-billium', 'checkout'],
                [new ExecutionScope($scope->installationRef, 'seller-a', 'test', 'source-other'), 'remote-billium', 'checkout'],
                [$scope, 'standalone-billium', 'checkout'], [$scope, 'remote-billium', 'changed-context'],
            ] as [$foreign, $source, $context]) {
                $this->callers->set(new Caller($foreign->sellerRef, $foreign->environment, $foreign->installationRef, 'other-actor', [$source], [$foreign->intentOwnerNamespace]));
                $this->assertProblem(ProblemCode::ReferenceUnknown, fn () => $history->business($foreign, $source, $context, 'intent-a'));
                $this->assertProblem(ProblemCode::ReferenceUnknown, fn () => $history->receiptByCommandKey($foreign, $source, ExecutePayment::SCHEMA, $context, Fixture::key(1)));
                self::assertSame([], $history->admissionConflicts($foreign, $source, ExecutePayment::SCHEMA, $context, Fixture::key(1)));
                if ($context === 'checkout') {
                    $this->assertProblem(ProblemCode::ReferenceUnknown, fn () => $queries->find($foreign, 'root-a'));
                    $this->assertProblem(ProblemCode::ReferenceUnknown, fn () => $queries->status($foreign, 'root-a'));
                    $this->assertProblem(ProblemCode::ReferenceUnknown, fn () => $queries->receipt($foreign, $receipt));
                }
            }
            self::assertSame(1, $this->rows('billium_execution_operation'));
            $this->assertProblem(ProblemCode::ReferenceUnknown, fn () => $history->receiptByCommandKey($scope, 'remote-billium', ExecutePayment::SCHEMA, 'checkout', Fixture::key(111)));
            $this->callers->set(new Caller($scope->sellerRef, $scope->environment, $scope->installationRef, 'ungranted', ['remote-billium'], []));
            $this->assertProblem(ProblemCode::AuthDenied, fn () => $history->business($scope, 'remote-billium', 'checkout', 'intent-a'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_stored_business_refusal_is_readable_with_exact_source_before_any_operation_exists(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            Fixture::seedMerchant($app);
            CollectionAdmissionGuardTest::installStep($app);
            Fixture::host($app, $path, [Fixture::origin('step-a')]);
            CollectionAdmissionGuardTest::guard($app);
            CollectionAdmissionGuardTest::revoke($app);
            $refusal = $app->make(RootPaymentCommands::class)->admit($scope, Fixture::wire());
            self::assertFalse($refusal->accepted);
            $history = $app->make(ExecutionHistoryQueries::class);
            $read = $history->receiptByCommandKey($scope, 'remote-billium', ExecutePayment::SCHEMA, 'checkout', Fixture::key(1));
            self::assertFalse($read->outcome->accepted);
            self::assertSame(ProblemCode::OperationFinancialHold, $read->outcome->problem);
            self::assertSame($refusal->receiptId, $read->outcome->receiptId);
            self::assertSame($refusal->detail, $read->outcome->detail);
            $this->callers->set(new Caller($scope->sellerRef, $scope->environment, $scope->installationRef, 'alternate-source', ['standalone-billium'], ['source-a']));
            $this->assertProblem(ProblemCode::ReferenceUnknown, fn () => $history->receiptByCommandKey($scope, 'standalone-billium', ExecutePayment::SCHEMA, 'checkout', Fixture::key(1)));
            self::assertSame(0, $this->rows('billium_execution_operation'));
            self::assertSame(0, $this->rows('billium_execution_observation_partition'));
        } finally {
            TestEnvironment::pdo(owner: true)->exec('DROP TABLE IF EXISTS billium_test_collection_step');
            UnknownResultTest::clean($path);
        }
    }

    public function test_public_business_key_audit_and_refundable_capacity_queries_seek_indexes_on_representative_history(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            Fixture::seedMerchant($app);
            Fixture::host($app, $path);
            $scope = Fixture::scope();
            $commands = $app->make(RootPaymentCommands::class);
            $accepted = $commands->admit($scope, Fixture::wire())->orThrow();
            $ref = self::text($accepted->body['receiptRef']);
            $this->assertProblem(ProblemCode::IdempotencyConflict,
                fn () => $commands->admit($scope, Fixture::wire(['money' => ['amount' => '1.01', 'currency' => 'RUB']]))->orThrow());
            $this->seedReadHistory();
            $reader = $app->make(DatabaseManager::class)->connection();
            $reader->enableQueryLog();
            $history = $app->make(ExecutionHistoryQueries::class);
            self::assertSame($ref, $history->business($scope, 'remote-billium', 'checkout', 'intent-a')->receiptRef);
            self::assertEquals($accepted->body, $history->receiptByCommandKey($scope, 'remote-billium', ExecutePayment::SCHEMA, 'checkout', Fixture::key(1))->outcome->body);
            self::assertCount(1, $history->admissionConflicts($scope, 'remote-billium', ExecutePayment::SCHEMA, 'checkout', Fixture::key(1), limit: 1));
            $queries = $app->make(ExecutionQueries::class);
            self::assertSame($ref, $queries->receipt($scope, $ref)->receiptRef);
            self::assertSame(0, Money::fromWire($queries->status($scope, 'root-a')->capacity->refundAvailable)->minor);
            $log = $reader->getQueryLog();
            $reader->disableQueryLog();
            $this->assertIndexed($reader, $log, 'business_identity_digest =',
                'billium_execution_business_identity_digest_unique', 'billium_execution_binding_intent_idx');
            $this->assertIndexed($reader, $log, '"command_key" =', 'billium_idempotency_receipt_scope_key');
            $this->assertIndexed($reader, $log, "result#>>'{body,receiptRef}'", 'billium_idempotency_opaque_receipt_scope');
            $this->assertIndexed($reader, $log, 'from "billium_execution_admission_conflict"', 'billium_execution_admission_conflict_history');
            $this->assertIndexed($reader, $log, 'from "billium_execution_capacity"', 'billium_execution_capacity_');
            $foreign = new ExecutionScope($scope->installationRef, 'seller-b', $scope->environment, $scope->intentOwnerNamespace);
            $this->callers->set(new Caller($foreign->sellerRef, $foreign->environment, $foreign->installationRef, 'another-seller', ['remote-billium'], ['source-a']));
            $this->assertProblem(ProblemCode::ReferenceUnknown, fn () => $queries->status($foreign, 'root-a'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    private function seedReadHistory(): void
    {
        $this->owner()->unprepared(<<<'SQL'
            INSERT INTO billium_execution_business_binding(installation_ref,seller_ref,environment,intent_owner_namespace,source_context,business_intent_ref,
                operation_kind,semantic_digest,canonicalization_version,operation_ref,binding)
            SELECT installation_ref,seller_ref,environment,intent_owner_namespace,source_context,'history-intent-'||n,
                operation_kind,semantic_digest,canonicalization_version,'history-root-'||n,
                (binding - 'origin') || jsonb_build_object('businessIntentRef','history-intent-'||n,'rootOperationRef','history-root-'||n)
            FROM billium_execution_business_binding CROSS JOIN generate_series(1,2400) n WHERE operation_ref='root-a';

            INSERT INTO billium_execution_operation(installation_ref,seller_ref,environment,operation_ref,root_operation_ref,kind,binding,binding_digest,
                business_intent_ref,intent_owner_namespace,source_context,merchant_revision_ref,merchant_account_ref,driver_id,driver_contract_version,
                capability_digest,requested_minor,currency,currency_exponent,state,economic_digest)
            SELECT b.installation_ref,b.seller_ref,b.environment,b.operation_ref,b.operation_ref,o.kind,b.binding,o.binding_digest,b.business_intent_ref,
                b.intent_owner_namespace,b.source_context,o.merchant_revision_ref,o.merchant_account_ref,o.driver_id,o.driver_contract_version,
                o.capability_digest,o.requested_minor,o.currency,o.currency_exponent,'admitted',b.semantic_digest
            FROM billium_execution_business_binding b, billium_execution_operation o
            WHERE b.operation_ref LIKE 'history-root-%' AND o.operation_ref='root-a';

            INSERT INTO billium_execution_capacity(installation_ref,seller_ref,environment,root_operation_ref,currency)
            SELECT installation_ref,seller_ref,environment,operation_ref,currency FROM billium_execution_operation WHERE operation_ref LIKE 'history-root-%';

            INSERT INTO billium_idempotency_receipt(environment,seller_ref,command_kind,command_key,digest,outcome,result,completed_at,financial_scope)
            SELECT environment,seller_ref,command_kind,'history-command-'||n,digest,'accepted',
                jsonb_set(result,'{body,receiptRef}',to_jsonb('history-receipt-'||n)),now(),financial_scope
            FROM billium_idempotency_receipt CROSS JOIN generate_series(1,2400) n WHERE command_kind='payment.execute.v1';

            INSERT INTO billium_execution_admission_conflict(audit_ref,installation_ref,seller_ref,environment,intent_owner_namespace,actor_ref,command_kind,
                command_key_digest,semantic_digest,original_operation_ref,problem_code,source_authority)
            SELECT 'conflict:'||lpad(n::text,64,'0'),installation_ref,seller_ref,environment,intent_owner_namespace,actor_ref,command_kind,
                'sha256:'||lpad(n::text,64,'0'),semantic_digest,NULL,problem_code,source_authority
            FROM billium_execution_admission_conflict CROSS JOIN generate_series(1,2400) n;

            ANALYZE billium_execution_business_binding, billium_execution_operation, billium_execution_capacity,
                    billium_idempotency_receipt, billium_execution_admission_conflict;
            SQL);
    }

    /** @param array<array<string,mixed>> $log */
    private function assertIndexed(Connection $db, array $log, string $needle, string ...$indexes): void
    {
        $execution = array_find($log, static fn (array $entry): bool => str_contains(self::text($entry['query']), $needle));
        self::assertNotNull($execution, 'Missing executed query '.$needle);
        /** @var list<mixed> $bindings */
        $bindings = $execution['bindings'];
        $explain = $db->selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.self::text($execution['query']), $bindings);
        self::assertInstanceOf(stdClass::class, $explain);
        $plan = self::text($explain->{'QUERY PLAN'});
        self::assertTrue(array_any($indexes, static fn (string $index): bool => str_contains($plan, $index)), $plan);
        self::assertStringNotContainsString('"Node Type": "Seq Scan"', $plan);
        fwrite(STDOUT, "Authorized query EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)\n".$plan.PHP_EOL);
    }

    /** @param Closure():mixed $call */
    private function assertProblem(ProblemCode $expected, Closure $call): void
    {
        try {
            $call();
            self::fail('Expected a typed query refusal');
        } catch (ProblemException $error) {
            self::assertSame($expected, $error->problem);
        }
    }
}

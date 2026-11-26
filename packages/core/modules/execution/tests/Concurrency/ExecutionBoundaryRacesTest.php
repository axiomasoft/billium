<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Concurrency;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\MerchantRevisionPolicy;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\CallbackCustodyTest as Inputs;
use Billium\Execution\Tests\Feature\HistoricalMerchantRefundTest;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessBarrier;
use Billium\Testkit\Fixtures\ProcessIdentity;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class ExecutionBoundaryRacesTest extends ExecutionTestCase
{
    #[Group('ACC-CON-06')]
    public function test_current_finish_stale_sender_and_authenticated_application_contend_after_lease_rollover(): void
    {
        $path = Fixture::tempPath();
        $journal = new DurableJournal($path.'.control');
        $barrier = new ProcessBarrier($journal);
        $children = [];
        try {
            $app = $this->application();
            Fixture::seedMerchant($app);
            Fixture::host($app, $path);
            $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
            $roles = ['old', 'current', 'apply'];
            foreach ($roles as $role) {
                $children[$role] = self::start($role, $path);
            }
            $backends = $this->participants($journal, $children);
            $barrier->arrive('start');
            $barrier->await('accepted', 1, 15_000);
            UnknownResultTest::awaitLease($app->make(ExecutionStore::class));
            $barrier->arrive('takeover');
            $barrier->await('finish-locked', 1, 15_000);
            self::assertSame(1, $this->rows('billium_execution_attempt', "state='dispatching' AND lease_fence=2 AND finish_evidence_ref IS NULL"));
            $barrier->arrive('contend');
            $this->assertWaiting($backends['current'], [$backends['old'], $backends['apply']]);
            $barrier->arrive('commit');
            $this->join($children);
            self::assertSame('retained_stale', self::event($journal, 'old-finish')->data['status']);
            self::assertSame('pending', self::event($journal, 'current-finish')->data['status']);
            self::assertSame('applied', self::event($journal, 'applied')->data['status']);
            self::assertSame(1, $this->rows('billium_execution_attempt'));
            self::assertSame(1, $this->rows('billium_execution_attempt', "lease_fence=3 AND state='succeeded' AND lease_owner IS NULL AND finish_evidence_ref IN (SELECT evidence_ref FROM billium_execution_provider_fact f CROSS JOIN LATERAL jsonb_array_elements_text(f.evidence_refs) evidence_ref)"));
            self::assertSame(1, $this->rows('billium_execution_operation_revision', "operation_ref='root-a' AND revision=1"));
            self::assertSame(1, $this->rows('billium_execution_provider_fact'));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND NOT financial_hold'));
            self::assertSame(1, $this->rows('billium_execution_observation_emission'));
            self::assertSame(1, $this->rows('billium_execution_provider_work', "state='processed'"));
            self::assertSame(1, $this->rows('billium_execution_provider_evidence', "signature_verdict='simulated-valid'"));
            foreach (['old' => ['dispatch', '1'], 'current' => ['lookup', '2']] as $role => [$source, $fence]) {
                $ref = self::event($journal, $role.'-finish')->data['receipt'];
                self::assertSame(1, $this->rows('billium_execution_provider_evidence', "evidence_ref=? AND signature_verdict='local_transport' AND sanitized_payload->>'source'=? AND sanitized_payload->>'leaseFence'=? AND sanitized_payload->>'retainedProviderEvidenceRef' IN (SELECT evidence_ref FROM billium_execution_provider_evidence WHERE signature_verdict='simulated-valid')", [$ref, $source, $fence]));
            }
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.lookup'));
        } finally {
            foreach (['start', 'takeover', 'contend', 'commit'] as $point) {
                $barrier->arrive($point);
            }
            $this->join($children);
            UnknownResultTest::clean($path);
        }
    }

    public static function finishWorker(string $role, string $path, Application $app, ProcessBarrier $barrier, DurableJournal $journal): void
    {
        $scope = Fixture::scope();
        $db = $app->make(DatabaseManager::class)->connection();
        Fixture::host($app, $path, checkpoint: static function (string $stage) use ($role, $barrier): void {
            if ($role === 'old' && $stage === 'after_accept') {
                $barrier->arrive('accepted');
                $barrier->await('contend', 1, 25_000);
            }
        });
        $worker = $app->make(AttemptWorker::class);
        if ($role === 'apply') {
            $barrier->await('contend', 1, 25_000);
            $work = self::text($db->scalar("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint='root-a'"));
            $result = $app->make(EvidenceReducer::class)->reduce($work);
            $journal->append(new JournalEvent('applied', ['status' => $result->disposition->value], ProcessIdentity::current()));

            return;
        }
        if ($role === 'current') {
            $barrier->await('takeover', 1, 20_000);
            $db->listen(static function (QueryExecuted $query) use ($barrier): void {
                if (str_starts_with($query->sql, 'update "billium_execution_attempt" set') && str_contains($query->sql, '"finish_evidence_ref"')) {
                    $barrier->arrive('finish-locked');
                    $barrier->await('commit', 1, 20_000);
                }
            });
            $outcome = $worker->recover($scope, UnknownResultTest::attemptRef(), 'current-owner', new DateTimeImmutable('+1 minute'))->orThrow();
        } else {
            $outcome = $worker->dispatch($scope, UnknownResultTest::attemptRef(), 'old-owner', new DateTimeImmutable('+3 seconds'))->orThrow();
        }
        $journal->append(new JournalEvent($role.'-finish', ['status' => self::text($outcome->body['status']), 'receipt' => self::text($outcome->body['receiptRef'])], ProcessIdentity::current()));
    }

    #[Group('ACC-CON-23')]
    public function test_credential_rotation_two_colliding_callbacks_and_original_account_refund(): void
    {
        $path = Fixture::tempPath();
        $journal = new DurableJournal($path.'.control');
        $barrier = new ProcessBarrier($journal);
        $children = [];
        try {
            $this->preparePayments($path);
            $root = $this->row("SELECT binding, binding_digest, merchant_revision_ref, merchant_account_ref FROM billium_execution_operation WHERE operation_ref='root-a'");
            foreach (['rotate', 'callback-a', 'callback-b', 'refund'] as $role) {
                $children[$role] = self::start($role, $path);
            }
            $backends = $this->participants($journal, $children);
            $barrier->arrive('start');
            $barrier->await('rotation-locked', 1, 15_000);
            $this->assertWaiting($backends['rotate'], [$backends['refund']]);
            $barrier->await('callback-applied', 2, 20_000);
            self::assertSame(2, $this->rows('billium_execution_operation_revision', "revision=1 AND ((operation_ref='root-a' AND observed->>'merchantAccountRef'='account-a' AND observed->'capturedTotal'->>'minor'='100') OR (operation_ref='root-b' AND observed->>'merchantAccountRef'='account-b' AND observed->'capturedTotal'->>'minor'='200'))"));
            self::assertSame(0, $this->rows('billium_execution_operation', "kind='refund'"));
            $barrier->arrive('commit');
            $this->join($children);
            self::assertSame($root, $this->row("SELECT binding, binding_digest, merchant_revision_ref, merchant_account_ref FROM billium_execution_operation WHERE operation_ref='root-a'"));
            self::assertSame($root, $this->row("SELECT binding, binding_digest, merchant_revision_ref, merchant_account_ref FROM billium_execution_operation WHERE operation_ref='original-refund'"));
            self::assertSame(2, $this->rows('billium_execution_provider_identity', "provider_operation_id='both-accounts-42'"));
            self::assertSame(2, $this->rows('billium_execution_provider_evidence', "signature_verdict='simulated-valid' AND provider_event_id='simulated-event:both-accounts-paid:9'"));
            self::assertSame(1, $this->rows('billium_execution_provider_work', "state='quarantined' AND last_error_code='unmatched_signed_operation'"));
            self::assertSame(1, $this->rows('billium_execution_provider_work', "state='quarantined' AND last_error_code='callback_authentication_failed'"));
            self::assertSame(2, $this->rows('billium_execution_operation_revision'));
            $record = array_find(new DurableJournal($path)->records(), static fn (object $e): bool => $e instanceof ProviderRecord && $e->kind === EffectKind::Refund);
            self::assertInstanceOf(ProviderRecord::class, $record);
            self::assertSame(['root-a', 'merchant-a', 'account-a', 'refund-v2'], [$record->request->binding->rootOperationRef, $record->request->binding->merchantRevisionRef,
                $record->request->credentials->merchantAccountRef, $record->request->credentials->credentialVersionRef]);
            self::assertSame(2, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(1, UnknownResultTest::events($path.'.b', 'provider.call'));
            $row = (array) $this->owner()->table('billium_execution_provider_identity')->where('merchant_account_ref', 'account-a')->sole();
            unset($row['id']);
            $row['attempt_ref'] = RefundCapacityTest::attemptRef('original-refund');
            try {
                $this->owner()->table('billium_execution_provider_identity')->insert($row);
                self::fail('Database accepted duplicate assignment in the same account');
            } catch (QueryException $error) {
                self::assertSame('23505', $error->getCode());
                self::assertStringContainsString('billium_execution_assigned_operation', $error->getMessage());
            }
        } finally {
            foreach (['start', 'rotation-locked', 'commit'] as $point) {
                $barrier->arrive($point);
            }
            $this->join($children);
            UnknownResultTest::clean($path);
            UnknownResultTest::clean($path.'.b');
        }
    }

    private function preparePayments(string $path): void
    {
        $app = $this->application();
        $scope = Fixture::scope();
        Inputs::dispatched($app, $path, ResponseMode::AcceptedThenTimeout);
        $old = $app->make(MerchantRegistry::class)->historical($scope, 'root-a');
        $cap = SimulatedCapabilities::snapshot('account-b');
        $merchant = new MerchantRevision('merchant-b', $scope, 'account-b', $old->providerId, $cap->driverId, $cap->driverContractVersion,
            $cap->snapshotRef, $cap->digest, new SecretRef('secret://fixture/account_b'), 'credential-b', $cap->currencies,
            hash('sha256', 'merchant-b'), $old->createdAt, new MerchantRevisionPolicy($old->policy->validFrom, null, null, 'api-v1', 'webhook-b', 'continuity-b', 'settlement-b', 'mandate-b'));
        $this->runner()->run($this->spec(kind: 'execution.configure_b', mode: FinancialMode::Administrative, class: ModuleOperationClass::HistoricalRead, modules: ['execution', 'platform']),
            static function (TransactionContext $ctx) use ($app, $merchant, $cap, $scope): PlatformReceipt {
                $app->make(ExecutionStore::class)->appendMerchantRevision($ctx, $merchant, $cap);
                $registry = $app->make(DatabaseMerchantRegistry::class);
                foreach (['new', 'lookup'] as $purpose) {
                    $registry->appendLifecycle($ctx, $scope, 'merchant-b', 'b-enable-'.$purpose, $purpose === 'new' ? 'enable_new' : 'enable_recovery', $purpose,
                        new DateTimeImmutable('-1 day'), $purpose === 'new' ? null : new DateTimeImmutable('+2 days'), 'approve merchant B', 'approval-b');
                    $registry->appendCredential($ctx, $merchant, 'b-credential-'.$purpose, $merchant->credentialVersionRef, $merchant->credentialRef, $purpose,
                        new DateTimeImmutable('-1 day'), new DateTimeImmutable('+2 days'), 'proof-'.$purpose, null);
                }

                return self::receipt();
            })->orThrow();
        Fixture::host($app, $path.'.b', [Fixture::origin(context: 'support', operation: 'root-b')], $cap, ResponseMode::AcceptedThenTimeout);
        $app->make(RootPaymentCommands::class)->admit($scope, Fixture::wire(['commandKey' => Fixture::key(2), 'operationRef' => 'root-b', 'source' => ['context' => 'support', 'intentRef' => 'intent-a'],
            'merchantAccountRef' => 'account-b', 'bindingRevision' => 'merchant-b', 'money' => ['amount' => '2.00', 'currency' => 'RUB']]))->orThrow();
        $app->make(AttemptWorker::class)->dispatch($scope, RefundCapacityTest::attemptRef('root-b'), 'send-b', new DateTimeImmutable('+1 minute'))->orThrow();
        self::assertSame(0, $this->rows('billium_execution_operation_revision'));
    }

    private static function rotate(Application $app, ProcessBarrier $barrier): void
    {
        $scope = Fixture::scope();
        $merchant = $app->make(MerchantRegistry::class)->historical($scope, 'root-a');
        $selection = new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, 'remote-billium');
        $admin = new OperationSpec('execution.rotate_refund_credential', $scope->sellerRef, $scope->environment, FinancialMode::Administrative,
            ModuleOperationClass::HistoricalRead, ['execution', 'platform'], financialScope: $selection);
        $app->make(OperationRunner::class)->run($admin, static function (TransactionContext $ctx) use ($app, $merchant, $barrier): PlatformReceipt {
            $app->make(DatabaseMerchantRegistry::class)->appendCredential($ctx, $merchant, 'rotated-refund', 'refund-v2', new SecretRef('secret://fixture/rotated_a'),
                'refund', new DateTimeImmutable('-1 minute'), new DateTimeImmutable('+2 days'), 'approved-account-a', 'cred-refund');
            $barrier->arrive('rotation-locked');
            $barrier->await('commit', 1, 30_000);

            return self::receipt();
        })->orThrow();
    }

    private static function refund(Application $app, string $path, ProcessBarrier $barrier): void
    {
        $scope = Fixture::scope();
        Fixture::host($app, $path);
        $api = $app->make(RefundCommands::class);
        $barrier->await('rotation-locked', 1, 20_000);
        HistoricalMerchantRefundTest::problem(ProblemCode::RecoveryRequired, static fn () => $api->admit($scope, RefundCapacityTest::refund('original-refund', 25, 4))->orThrow());
        $app->instance(MerchantCredentials::class, new readonly class implements MerchantCredentials
        {
            public function resolve(MerchantRevision $revision, string $purpose): ResolvedCredentials
            {
                return new ResolvedCredentials($revision->merchantAccountRef, 'refund-v2', new SecretRef('secret://fixture/rotated_a'), $purpose,
                    new DateTimeImmutable('+5 minutes'), 'approved-account-a');
            }
        });
        $api->admit($scope, RefundCapacityTest::refund('original-refund', 25, 5))->orThrow();
        $app->make(AttemptWorker::class)->dispatch($scope, RefundCapacityTest::attemptRef('original-refund'), 'historical-sender', new DateTimeImmutable('+1 minute'))->orThrow();
    }

    private static function acceptCallback(Application $app, string $role, string $path, ProcessBarrier $barrier): void
    {
        $account = $role === 'callback-a' ? 'a' : 'b';
        $op = 'root-'.$account;
        $scope = Fixture::scope();
        Fixture::host($app, $account === 'a' ? $path : $path.'.b', cap: SimulatedCapabilities::snapshot('account-'.$account));
        $base = Inputs::providerInput($app, $op);
        $signed = Inputs::signed($base, ['revision' => '9', 'stableEffectId' => 'both-accounts-paid', 'providerOperationId' => 'both-accounts-42']);
        $barrier->await('rotation-locked', 1, 20_000);
        $api = $app->make(CallbackCustody::class);
        if ($account === 'b') {
            $foreign = Inputs::signed($signed, ['revision' => '10', 'operationRef' => 'root-a']);
            HistoricalMerchantRefundTest::problem(ProblemCode::AuthDenied, static fn () => $api->receive($scope, 'merchant-b', null, $foreign));
            $unmatched = Inputs::signed($signed, ['revision' => '11', 'operationRef' => 'unidentified']);
            self::assertSame('custody_quarantined', $api->receive($scope, 'merchant-b', null, $unmatched)->orThrow()->body['status']);
        }
        self::assertSame('custody_accepted', $api->receive($scope, 'merchant-'.$account, null, $signed)->orThrow()->body['status']);
        self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce(Inputs::work($signed))->disposition);
        $barrier->arrive('callback-applied');
    }

    private static function start(string $role, string $path): ChildProcess
    {
        return new ChildProcess([PHP_BINARY, '-r', 'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Concurrency\\ExecutionBoundaryRacesTest::worker($argv[1],$argv[2]);', $role, $path]);
    }

    public static function worker(string $role, string $path): void
    {
        $index = array_search($role, ['old', 'current', 'apply', 'rotate', 'callback-a', 'callback-b', 'refund'], true);
        self::assertIsInt($index);
        $app = Fixture::workerApp(600 + $index);
        $journal = new DurableJournal($path.'.control');
        $barrier = new ProcessBarrier($journal);
        $backend = self::integer($app->make(DatabaseManager::class)->connection()->scalar('SELECT pg_backend_pid()'));
        $journal->append(new JournalEvent($role, ['backend' => $backend], ProcessIdentity::current()));
        $barrier->arrive('ready');
        $barrier->await('start', 1, 20_000);
        match ($role) {
            'rotate' => self::rotate($app, $barrier),
            'callback-a', 'callback-b' => self::acceptCallback($app, $role, $path, $barrier),
            'refund' => self::refund($app, $path, $barrier),
            default => self::finishWorker($role, $path, $app, $barrier, $journal),
        };
    }

    /** @param array<string, ChildProcess> $children
     * @return array<string,int>
     */
    private function participants(DurableJournal $journal, array $children): array
    {
        new ProcessBarrier($journal)->await('ready', count($children), 20_000);
        $backends = [];
        $pids = [];
        foreach ($children as $role => $child) {
            $record = self::event($journal, $role);
            self::assertSame($child->pid, $record->pid);
            $pids[$role] = $record->pid;
            $backends[$role] = self::integer($record->data['backend']);
        }
        self::assertCount(count($children), array_unique($pids));
        self::assertCount(count($children), array_unique($backends));
        fwrite(STDOUT, PHP_EOL.json_encode(['processes' => $pids, 'postgres_backends' => $backends], JSON_THROW_ON_ERROR).PHP_EOL);

        return $backends;
    }

    private static function event(DurableJournal $journal, string $name): JournalEvent
    {
        $record = array_find($journal->records(), static fn (object $r): bool => $r instanceof JournalEvent && $r->kind === $name);
        self::assertInstanceOf(JournalEvent::class, $record);

        return $record;
    }

    /** @param list<int> $waiters */
    private function assertWaiting(int $holder, array $waiters): void
    {
        $deadline = hrtime(true) + 8_000_000_000;
        do {
            $count = $this->value(<<<'SQL'
                WITH RECURSIVE locks(pid) AS (
                    SELECT unnest(pg_blocking_pids(?::int))
                    UNION
                    SELECT b.pid FROM locks CROSS JOIN LATERAL unnest(pg_blocking_pids(locks.pid)) b(pid)
                ) SELECT count(*) FROM locks WHERE pid = ?::int
                SQL, [$waiters[0], $holder]);
            if (self::integer($count) > 0) {
                fwrite(STDOUT, PHP_EOL.json_encode(['waiting_backend' => $waiters[0], 'blocking_backend' => $holder], JSON_THROW_ON_ERROR).PHP_EOL);
                array_shift($waiters);
                if ($waiters === []) {
                    return;
                }
            }
            usleep(2_000);
        } while (hrtime(true) < $deadline);
        self::fail('PostgreSQL never witnessed the workers blocked by '.$holder);
    }

    /** @param array<string, ChildProcess> $children */
    private function join(array &$children): void
    {
        while ($children !== []) {
            self::assertSame(0, array_shift($children)->wait());
        }
    }
}

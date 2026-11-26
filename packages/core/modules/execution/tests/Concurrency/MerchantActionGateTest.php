<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Concurrency;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantActionGate;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Internal\Admission\AdmitRoot;
use Billium\Execution\Internal\Dispatch\CommitDispatch;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Internal\Storage\DatabaseExecutionStore;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\ExecutionSchemaTest;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessBarrier;
use Billium\Testkit\Fixtures\ProcessIdentity;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

#[Group('pg')]
final class MerchantActionGateTest extends ExecutionTestCase
{
    private static function admin(Application $app, string $change): void
    {
        $scope = AdmissionIdentityTest::scope();
        $spec = new OperationSpec('execution.merchant_change', $scope->sellerRef, $scope->environment, FinancialMode::Administrative,
            ModuleOperationClass::HistoricalRead, ['execution', 'platform'], financialScope: new FinancialScope($scope->installationRef,
                $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, 'remote-billium'));
        $app->make(OperationRunner::class)->run($spec, static function (TransactionContext $ctx) use ($app, $scope, $change): PlatformReceipt {
            $registry = $app->make(DatabaseMerchantRegistry::class);
            $store = $app->make(ExecutionStore::class);
            if ($change === 'retire') {
                $registry->appendCredential($ctx, $store->merchantRevision($scope, 'merchant-a'), 'replacement', 'v2', new SecretRef('secret://vault/replacement'),
                    'new', new DateTimeImmutable('-1 hour'), new DateTimeImmutable('+1 day'), 'replacement-proof', 'cred-new');
            } elseif ($change === 'seed-second') {
                $store->appendMerchantRevision($ctx, ExecutionSchemaTest::merchantFixture('merchant-b', 'account-b', 'cap-b'),
                    ExecutionSchemaTest::capabilityFixture('cap-b', 'account-b'));
            } else {
                $registry->appendLifecycle($ctx, $scope, $change === 'second' ? 'merchant-b' : 'merchant-a', 'stop-'.$change, 'stop_new',
                    'new', new DateTimeImmutable, null, 'fixture stop', 'stop-evidence');
            }

            return self::receipt();
        })->orThrow();
    }

    public static function process(string $role, string $path, string $order, string $change): void
    {
        $app = AdmissionIdentityTest::workerApp($role === 'admin' ? 2 : 1);
        $journal = new DurableJournal($path.'.control');
        $barrier = new ProcessBarrier($journal);
        $backend = self::integer($app->make(DatabaseManager::class)->connection()->scalar('SELECT pg_backend_pid()'));
        $journal->append(new JournalEvent($role, ['backend' => $backend], ProcessIdentity::current()));
        if ($role === 'admin') {
            self::admin($app, $change);
            $barrier->arrive('admin-committed');

            return;
        }
        AdmissionIdentityTest::host($app, $path);
        $gate = $app->make(MerchantActionGate::class);
        $app->when($role === 'admit' ? AdmitRoot::class : CommitDispatch::class)->needs(MerchantActionGate::class)->give(
            static fn (): MerchantActionGate => new readonly class($gate, $barrier, $order, $role) implements MerchantActionGate
            {
                public function __construct(private MerchantActionGate $gate, private ProcessBarrier $barrier, private string $order, private string $role) {}

                public function lock(TransactionContext $context, ExecutionScope $scope, string $revisionRef, string $purpose, ?ResolvedCredentials $credentials = null): void
                {
                    if ($this->role === 'dispatch') {
                        MerchantActionGateTest::assertInstanceOf(ResolvedCredentials::class, $credentials);
                    }
                    if ($this->order === 'after') {
                        $this->gate->lock($context, $scope, $revisionRef, $purpose, $credentials);
                    }
                    $this->barrier->arrive('dispatch-boundary');
                    $this->barrier->await('resume', 1, 15_000);
                    if ($this->order === 'before') {
                        $this->gate->lock($context, $scope, $revisionRef, $purpose, $credentials);
                    }
                }
            });
        try {
            if ($role === 'admit') {
                $app->make(RootPaymentCommands::class)->admit(AdmissionIdentityTest::scope(), AdmissionIdentityTest::wire())->orThrow();
            } else {
                $app->make(AttemptWorker::class)->dispatch(AdmissionIdentityTest::scope(), UnknownResultTest::attemptRef(), 'worker', new DateTimeImmutable('+1 minute'))->orThrow();
            }
            $barrier->arrive('completed');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            $barrier->arrive('refused');
        }
    }

    private static function start(string $role, string $path, string $order, string $change): ChildProcess
    {
        return new ChildProcess([PHP_BINARY, '-r',
            'require "vendor/autoload.php"; Billium\Execution\Tests\Concurrency\MerchantActionGateTest::process(...array_slice($argv, 1));',
            $role, $path, $order, $change]);
    }

    private static function backend(DurableJournal $journal, string $role): int
    {
        foreach ($journal->records() as $event) {
            if ($event instanceof JournalEvent && $event->kind === $role) {
                return self::integer($event->data['backend']);
            }
        }

        return 0;
    }

    #[TestWith(['before', 'stop', 0, 'admit'])]
    #[TestWith(['before', 'stop', 0])]
    #[TestWith(['before', 'retire', 0])]
    #[TestWith(['after', 'stop', 1])]
    #[TestWith(['after', 'retire', 1])]
    #[TestWith(['after', 'second', 1])]
    public function test_dispatch_and_administration_serialize_only_on_the_original_merchant(string $order, string $change, int $calls, string $role = 'dispatch'): void
    {
        $app = $this->application();
        $path = AdmissionIdentityTest::tempPath();
        $journal = new DurableJournal($path.'.control');
        $barrier = new ProcessBarrier($journal);
        $children = [];
        try {
            AdmissionIdentityTest::seedMerchant($app);
            if ($change === 'second') {
                self::admin($app, 'seed-second');
            }
            AdmissionIdentityTest::host($app, $path);
            if ($role === 'dispatch') {
                $app->make(RootPaymentCommands::class)->admit(AdmissionIdentityTest::scope(), AdmissionIdentityTest::wire())->orThrow();
            }
            $dispatch = $children[] = self::start($role, $path, $order, $change);
            $barrier->await('dispatch-boundary', 1, 15_000);
            $admin = $children[] = self::start('admin', $path, $order, $change);
            if ($order === 'after' && $change !== 'second') {
                $deadline = hrtime(true) + 8_000_000_000;
                $holder = self::backend($journal, 'dispatch');
                do {
                    $blocked = $this->value('SELECT ?::integer = ANY(pg_blocking_pids(?::integer))', [$holder, self::backend($journal, 'admin')]);
                    if ($blocked === true) {
                        break;
                    }
                    usleep(2_000);
                } while (hrtime(true) < $deadline);
                self::assertTrue($blocked, 'PostgreSQL must identify the dispatch backend blocking this administrator');
            } else {
                $barrier->await('admin-committed', 1, 10_000);
            }
            self::assertSame(0, UnknownResultTest::events($path, 'provider.call'));
            $barrier->arrive('resume');
            while ($children !== []) {
                self::assertSame(0, array_shift($children)->wait());
            }
            self::assertNotSame($dispatch->pid, $admin->pid);
            self::assertNotSame(self::backend($journal, $role), self::backend($journal, 'admin'));
            $barrier->await($calls === 0 ? 'refused' : 'completed', 1);
            self::assertSame($calls, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame($calls, $this->rows('billium_execution_attempt', 'started_at IS NOT NULL'));
            self::assertSame($role === 'admit' ? 0 : 1, $this->rows('billium_execution_operation'));
        } finally {
            $barrier->arrive('resume');
            try {
                foreach ($children as $child) {
                    $child->wait();
                }
            } finally {
                UnknownResultTest::clean($path);
            }
        }
    }

    public function test_gate_checks_exact_credential_scope_and_expiry_and_runtime_has_only_the_lock_capability(): void
    {
        $app = $this->application();
        AdmissionIdentityTest::seedMerchant($app);
        $scope = AdmissionIdentityTest::scope();
        $credential = static fn (string $account = 'account-a', string $version = 'credential-v1', string $locator = 'secret://vault/credential_a',
            string $purpose = 'new', string $expiry = '+1 hour', string $proof = 'proof-new'): ResolvedCredentials => new ResolvedCredentials(
                $account, $version, new SecretRef($locator), $purpose, new DateTimeImmutable($expiry), $proof);
        $valid = $credential();
        $spec = $this->spec(kind: 'execution.check_credentials', modules: ['execution', 'platform']);
        $gate = $app->make(MerchantActionGate::class);
        foreach ([
            'account' => $credential(account: 'substituted-account'), 'version' => $credential(version: 'other-version'),
            'locator' => $credential(locator: 'secret://vault/other'), 'purpose' => $credential(purpose: 'lookup'),
            'proof' => $credential(proof: 'other-proof'), 'expiry' => $credential(expiry: '-1 second'),
            'durable expiry' => $credential(expiry: '+3 days'),
        ] as $field => $changed) {
            try {
                $this->runner()->run($spec, static function (TransactionContext $ctx) use ($gate, $scope, $changed): PlatformReceipt {
                    $gate->lock($ctx, $scope, 'merchant-a', 'new', $changed);

                    return self::receipt();
                })->orThrow();
                self::fail('Substituted credential field accepted: '.$field);
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            }
        }
        $this->runner()->run($spec, static function (TransactionContext $ctx) use ($gate, $scope, $valid, $app): PlatformReceipt {
            $gate->lock($ctx, $scope, 'merchant-a', 'new', $valid);
            $db = $app->make(DatabaseManager::class)->connection($ctx->connectionName());
            self::assertFalse($db->scalar("SELECT has_any_column_privilege(current_user, 'public.billium_execution_merchant_revision', 'UPDATE')"));
            $id = $db->table('billium_execution_merchant_revision')->value('id');
            $lock = 'SELECT count(*) FROM public.billium_lock_execution_merchant_revision(ARRAY[?::bigint], ?, ?, true)';
            self::assertSame(0, $db->scalar($lock, [$id, 'seller-b', $scope->environment]));
            self::assertSame(0, $db->scalar($lock, [$id, $scope->sellerRef, 'different']));
            $db->selectOne("SELECT set_config('billium.merchant_gate_installation', 'other-installation', true)");
            self::assertSame(0, $db->scalar($lock, [$id, $scope->sellerRef, $scope->environment]));

            return self::receipt();
        })->orThrow();
    }

    public function test_locked_reads_use_the_authenticated_transaction_context_without_calling_the_host(): void
    {
        $app = $this->application();
        AdmissionIdentityTest::seedMerchant($app);
        $callers = new class implements CallerContext
        {
            public function current(): Caller
            {
                throw new LogicException('Locked merchant read called the host');
            }
        };
        $db = $app->make(DatabaseManager::class);
        $options = $app->make(BilliumOptions::class);
        $store = new DatabaseExecutionStore($db, $this->runner(), $callers, $options, $app->make(FinancialSessionAdmission::class));
        $gate = new DatabaseMerchantRegistry($store, $db, $this->runner(), $callers, $options);
        $result = $this->runner()->run($this->spec(kind: 'execution.check_cached', modules: ['execution', 'platform']),
            static function (TransactionContext $ctx) use ($gate): PlatformReceipt {
                $gate->lock($ctx, AdmissionIdentityTest::scope(), 'merchant-a', 'new',
                    new ResolvedCredentials('account-a', 'credential-v1', new SecretRef('secret://vault/credential_a'),
                        'new', new DateTimeImmutable('+1 hour'), 'proof-new'));

                return self::receipt();
            });
        self::assertTrue($result->accepted);
    }

    public function test_stop_new_preserves_explicit_historical_lookup_and_refund_on_the_original_account(): void
    {
        $app = $this->application();
        $scope = AdmissionIdentityTest::scope();
        AdmissionIdentityTest::seedMerchant($app);
        $path = AdmissionIdentityTest::tempPath();
        try {
            AdmissionIdentityTest::host($app, $path, mode: ResponseMode::Pending);
            $app->make(RootPaymentCommands::class)->admit($scope, AdmissionIdentityTest::wire())->orThrow();
            $app->make(AttemptWorker::class)->dispatch($scope, UnknownResultTest::attemptRef(), 'worker', new DateTimeImmutable('+2 seconds'))->orThrow();
            self::admin($app, 'stop');
            $gate = $app->make(MerchantActionGate::class);
            $spec = $this->spec(kind: 'execution.old_action', modules: ['execution', 'platform']);
            foreach (['new' => false, 'refund' => true, 'lookup' => true, 'reconciliation' => false] as $purpose => $enabled) {
                try {
                    $this->runner()->run($spec, static function (TransactionContext $ctx) use ($gate, $scope, $purpose): PlatformReceipt {
                        $gate->lock($ctx, $scope, 'merchant-a', $purpose);

                        return self::receipt();
                    })->orThrow();
                    self::assertTrue($enabled, $purpose);
                } catch (ProblemException $e) {
                    self::assertFalse($enabled, $purpose);
                    self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
                }
            }
            $deadline = hrtime(true) + 5_000_000_000;
            do {
                $due = $this->value("SELECT state='due' AND due_at <= clock_timestamp() FROM billium_execution_dispatch_work");
                if ($due === true) {
                    break;
                }
                usleep(10_000);
            } while (hrtime(true) < $deadline);
            self::assertTrue($due, 'Historical lookup is due');
            $app->make(AttemptWorker::class)->recover($scope, UnknownResultTest::attemptRef(), 'recovery', new DateTimeImmutable('+1 minute'))->orThrow();
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.lookup'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }
}

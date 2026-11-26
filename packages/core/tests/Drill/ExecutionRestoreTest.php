<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Drill;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Contracts\Runtime\IncomingCommandAuthenticator;
use Billium\Core\Contracts\Runtime\InternalCommandReceiver;
use Billium\Core\Contracts\Runtime\ScopedCallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Data\Runtime\RecoveryCoverage;
use Billium\Core\Data\Runtime\RetirementProof;
use Billium\Core\Data\Runtime\VerifiedSessionAuthorization;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\Concurrency\WorkerApplication;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedExecutionReadiness;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Core\Tests\TestEnvironment;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\AuthorizationCommands;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionOriginResolver;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Contracts\OriginalIdentityArchive;
use Billium\Execution\Contracts\OriginalIdentityRecovery;
use Billium\Execution\Contracts\PaymentDriver;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\ReconciliationCommands;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Data\ArchivedExecutionIdentity;
use Billium\Execution\Data\DispatchRequest;
use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\DriverRequest;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\LookupRequest;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\OriginDecision;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Merchants\ContinuityMerchantCredentials;
use Billium\Execution\Laravel\ExecutionServiceProvider;
use Billium\Execution\Tests\CollectionAdmissionGuardTest;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\DispatchCancelTest;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest;
use Billium\Execution\Tests\Drill\AssignedProviderIdRecoveryTest;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\AuthorizationLifecycleTest;
use Billium\Execution\Tests\Feature\CallbackCustodyTest;
use Billium\Execution\Tests\Feature\InventoryReconciliationTest as Inventory;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Credits\RecordCustomerCredit;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Platform\Exceptions\TransitionRefused;
use Billium\Platform\Internal\CommandOutbox\CommandOutboxRelay;
use Billium\Platform\Internal\Installation\DatabaseInstallation;
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DeterministicWriterHost;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessBarrier;
use Billium\Testkit\Fixtures\ProcessIdentity;
use Billium\Testkit\Restore\PostgresSnapshot;
use Billium\Testkit\Restore\RestoreCoverageInspector;
use Billium\Testkit\Restore\RestoreSessionAuthority;
use Billium\Testkit\Restore\SignedCommandChannel;
use Billium\Testkit\Restore\SignedOriginalArchive;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

#[Group('pg')]
#[Group('ACC-RST-03')]
#[Group('ACC-RST-04')]
#[Group('PB-11')]
#[Group('PB-20')]
#[Group('PF-08')]
#[Group('PF-09')]
final class ExecutionRestoreTest extends ExecutionTestCase
{
    private static function key(): string
    {
        return getenv('BILLIUM_SIMULATED_AUTHORITY_KEY') ?: throw new LogicException('Missing independent fixture key');
    }

    private static function select(): FinancialScope
    {
        $s = Fixture::scope();

        return new FinancialScope($s->installationRef, $s->sellerRef, $s->environment, $s->intentOwnerNamespace, 'remote-billium');
    }

    private static function origin(string $operation, string $intent): OriginDecision
    {
        return new OriginDecision(...array_replace(get_object_vars(Fixture::origin(operation: $operation)), ['businessIntentRef' => $intent]));
    }

    private static function charge(string $ref, string $intent, int $key): ExecutePayment
    {
        $wire = Fixture::wire(['commandKey' => Fixture::key($key), 'operationRef' => $ref, 'source' => ['context' => 'checkout', 'intentRef' => $intent, 'orderRef' => 'order-a']]);
        self::assertInstanceOf(ExecutePayment::class, $wire);

        return $wire;
    }

    private function settlement(string $intent): void
    {
        $ledger = $this->application()->make(LedgerParticipants::class);
        $account = new BillingAccountScope('seller-a', 'test', 'billing-a');
        $this->runner()->run($this->spec(financialScope: self::select()), fn (TransactionContext $ctx) => $ledger->recordObligation($ctx,
            new RecordObligation('debt-'.$intent, $account, 'debt-'.$intent, 'basis-'.$intent, 'customer-a', 'component-'.$intent, new Money(100, Currency::of('RUB')), 'sha256:'.str_repeat('d', 64))))->orThrow();
        $this->runner()->run($this->spec(financialScope: self::select()), fn (TransactionContext $ctx) => $ledger->declareSettlementIntent($ctx,
            new DeclareSettlementIntent('declare-'.$intent, $account, $intent, 'RUB', ['debt-'.$intent])))->orThrow();
    }

    /** Boot a restored attachment without issuing any old process token or opening a writer session. */
    public static function cloneApplication(Application $original): Application
    {
        $app = WorkerApplication::create();
        $app->register(ExecutionServiceProvider::class);
        $app['config']->set('billium.modules.enabled', ['platform', 'ledger', 'execution', 'collection']);
        SimulatedExecutionReadiness::install($app, true);
        $app->instance(CallerContext::class, new FixedCallerContext(new Caller('seller-a', 'test', self::INSTALLATION,
            'restore-operator', ['remote-billium'], ['source-a'], ['execution.reconcile', 'execution.recover_held', 'execution.replay'])));
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission);
        SimulatedFinancialAuthorityEvidence::install($app);
        foreach ([PaymentDrivers::class, AdapterQualification::class, MerchantCredentials::class,
            ExecutionOriginResolver::class, WriterAdmission::class, DispatchEgress::class] as $port) {
            $app->instance($port, $original->make($port));
        }

        return $app;
    }

    public static function receiverApplication(): Application
    {
        $app = Fixture::workerApp(802);
        $callers = new class($app->make(CallerContext::class)->current()) implements ScopedCallerContext
        {
            public function __construct(private Caller $caller) {}

            public function current(): Caller
            {
                return $this->caller;
            }

            public function run(Caller $caller, Closure $body): mixed
            {
                $prior = $this->caller;
                $this->caller = $caller;
                try {
                    return $body();
                } finally {
                    $this->caller = $prior;
                }
            }
        };
        $app->instance(CallerContext::class, $callers);
        $app->instance(ScopedCallerContext::class, $callers);

        return $app;
    }

    public static function receiver(string $journal, string $binding): void
    {
        $app = self::receiverApplication();
        $channel = new SignedCommandChannel(new DurableJournal($journal.'.commands'), self::key());
        $app->instance(IncomingCommandAuthenticator::class, $channel);
        [$route, $message] = $channel->packet($binding);
        $app->make(InternalCommandReceiver::class)->receive($route, $message);
        $db = $app->make(DatabaseManager::class)->connection();
        new DurableJournal($journal.'.receiver')->append(new JournalEvent('applied', ['binding' => $binding, 'receipt' => $message['commandRef'],
            'database' => $db->scalar('SELECT current_database()'), 'backend' => $db->scalar('SELECT pg_backend_pid()'), 'projection' => $db->table('billium_payment_revision')->count(),
            'allocation' => $db->table('billium_allocation')->count(), 'inbox' => $db->table('billium_inbox_message')->where('status', 'applied')->count()], ProcessIdentity::current()));
    }

    public static function relay(Connection $db, string $receiver, string $path, int $limit = 100): void
    {
        $failure = null;
        $channel = new SignedCommandChannel(new DurableJournal($path.'.commands'), self::key(), static function (string $digest) use ($receiver, $path, &$failure): void {
            $child = new ChildProcess([PHP_BINARY, '-r', 'require "vendor/autoload.php"; Billium\\Core\\Tests\\Drill\\ExecutionRestoreTest::receiver($argv[1], $argv[2]);', $path, $digest], array_replace(getenv(), ['DB_DATABASE' => $receiver]));
            try {
                self::assertSame(0, $child->wait());
            } catch (RuntimeException $exception) {
                $failure = $exception;
                throw $exception;
            }
        });
        $result = new CommandOutboxRelay($db, $channel, budget: 1)->relay($limit);
        if ($failure !== null) {
            throw $failure;
        }
        if ($result->failed > 0) {
            self::fail('Downstream relay failed '.json_encode($db->table('billium_outbox_command_delivery')->get()->all()));
        }
        self::assertGreaterThan(0, $result->delivered);
    }

    private function denied(Closure $call): void
    {
        try {
            $call();
            self::fail('Unauthorized restore action succeeded');
        } catch (ProblemException|TransitionRefused $failure) {
            self::assertNotSame('', $failure->getMessage());
        }
    }

    private function counts(Connection $db, string $path): array
    {
        $counts = [];
        foreach (['operation', 'attempt', 'dispatch_work', 'provider_fact', 'observation_emission', 'original_identity_import'] as $table) {
            $counts[$table] = $db->table('billium_execution_'.$table)->count();
        }
        $counts['provider_effects'] = count(array_filter(new DurableJournal($path)->records(), static fn (object $r): bool => $r instanceof ProviderRecord));
        $counts['provider_calls'] = UnknownResultTest::events($path, 'provider.call');
        $counts['receiver_calls'] = count(new DurableJournal($path.'.receiver')->records());
        $counts['idempotency_receipts'] = $db->table('billium_idempotency_receipt')->count();
        $counts['database'] = $db->scalar('SELECT current_database()');
        $counts['backend'] = $db->scalar('SELECT pg_backend_pid()');

        return $counts;
    }

    #[Group('ACC-RST-08')]
    public function test_postbackup_provider_effect_and_downstream_replay_survive_a_real_postgres_restore(): void
    {
        $app = $this->application();
        $db = $app->make(DatabaseManager::class)->connection();
        $scope = Fixture::scope();
        $path = Fixture::tempPath();
        $source = TestEnvironment::databaseName();
        $backup = null;
        $child = null;
        $phase = [];
        try {
            Fixture::seedMerchant($app);
            $writer = Fixture::host($app, $path, [Fixture::origin(), self::origin('root-before', 'intent-before'), self::origin('root-fresh', 'intent-fresh'), self::origin('root-alias', 'intent-a')]);
            Inventory::enableInventory($app, $path);
            $this->settlement('intent-before');
            $this->settlement('intent-a');
            $wire = self::charge('root-before', 'intent-before', 71);
            $app->make(RootPaymentCommands::class)->admit($scope, $wire)->orThrow();
            AuthorizationLifecycleTest::dispatchAndReduce($app, 'root-before');
            $phase['before_backup'] = $this->counts($db, $path);
            self::assertSame(1, $phase['before_backup']['observation_emission']);
            $from = new DateTimeImmutable('-5 minutes');
            $backup = new PostgresSnapshot(TestEnvironment::pdo(owner: true), $source, $path.'.dump');
            $receiver = $backup->restore();
            self::relay($db, $receiver, $path);
            $archive = new SignedOriginalArchive($path, self::key(), $this->authority);
            $oldWire = Fixture::wire();
            self::assertInstanceOf(ExecutePayment::class, $oldWire);
            $archive->recordSource($oldWire);
            $child = UnknownResultTest::start($path, 'after-accept');
            $barrier = new ProcessBarrier(new DurableJournal($path.'.control'));
            $barrier->await('at-boundary', 1, 15_000);
            $store = $app->make(ExecutionStore::class);
            $original = $store->operation($scope, 'root-a');
            $attempt = $store->attempt($scope, UnknownResultTest::attemptRef());
            self::assertNotNull($original);
            self::assertNotNull($attempt);
            $record = array_find(new DurableJournal($path)->records(), static fn ($row): bool => $row instanceof ProviderRecord && $row->request->permit->operationRef === 'root-a');
            self::assertInstanceOf(ProviderRecord::class, $record);
            $token = $this->tokens->currentToken(self::INSTALLATION);
            self::assertNotNull($token);
            $archive->recordDispatch($record, $original, $attempt, $store->merchantRevision($scope, 'merchant-a'), AuthorityClaims::sourceLineage($token, 1));
            $this->authority->issueTransition('seal', 'restore-seal', [1]);
            $seal = $this->installation()->seal('restore-seal', 1);
            $barrier->arrive('resume');
            self::assertSame(0, $child->wait());
            $drainedProcess = $child->pid;
            $child = null;
            self::assertSame('dispatching', $store->attempt($scope, $attempt->attemptRef)?->state->value);
            self::assertGreaterThan(0, $db->table('billium_execution_provider_work')->where('operation_hint', 'root-a')->count());
            $phase['after_backup'] = $this->counts($db, $path);
            $ticket = array_find(new DurableJournal($path.'.host')->records(), static fn ($r): bool => $r instanceof DispatchTicket && $r->claims->attemptRef === $attempt->attemptRef);
            self::assertInstanceOf(DispatchTicket::class, $ticket);
            $key = PaymentIdentity::commandKey($scope, 'charge', 'checkout', $oldWire->commandKey);
            $receiptQuery = static fn (Connection $connection) => $connection->table('billium_idempotency_receipt')
                ->where('command_kind', ExecutePayment::SCHEMA)->where('command_key', $key);
            self::assertSame(1, $receiptQuery($db)->count());
            self::assertSame(1, $db->table('billium_execution_business_binding')->where('operation_ref', 'root-a')->count());
            $cloneName = $backup->restore();
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $cloneName;
            putenv('DB_DATABASE='.$cloneName);
            $clone = self::cloneApplication($app);
            $host = $writer;
            $replica = $clone->make(DatabaseManager::class)->connection();
            $tokens = $clone->make(FinancialSessionAdmission::class);
            $authority = $clone->make(SimulatedFinancialAuthorityEvidence::class);
            $session = $clone->make(Installation::class);
            $phase['restored_clone'] = $this->counts($replica, $path);
            self::assertSame(1, $phase['restored_clone']['operation']);
            self::assertSame(2, $phase['restored_clone']['provider_effects']);
            self::assertSame(1, $phase['restored_clone']['receiver_calls']);
            self::assertNotSame($db->scalar('SELECT pg_backend_pid()'), $replica->scalar('SELECT pg_backend_pid()'));
            self::assertNull($clone->make(ExecutionStore::class)->operation($scope, 'root-a'));
            $fresh = self::charge('root-fresh', 'intent-fresh', 99);
            $freshCommand = fn () => $clone->make(RootPaymentCommands::class)->admit($scope, $fresh)->orThrow();
            $tokens->set(null);
            $this->denied($freshCommand);
            $new = FixedSessionAdmission::token(self::INSTALLATION, 'owner-2', 2, 2, 2, [FinancialMode::Active, FinancialMode::Recovery], 'recovered');
            $tokens->set($new);
            $authority->issueProcess($new, [self::select()]);
            $this->denied($freshCommand);
            $inspector = new RestoreCoverageInspector($path, $backup->sha256, $from, $receiver, 'restore-inventory', $ticket, $drainedProcess, $seal, self::key());
            $coverage = fn (): RecoveryCoverage => $inspector->inspect($replica, $scope, 'account-a');
            $this->coverageRefused($coverage, 'Old worker and egress retirement incomplete');
            $writer->retire($ticket->claims, 'original worker drained, attachment and egress retired');
            try {
                $writer->verify($ticket, $ticket->claims);
                self::fail('Retired egress ticket accepted');
            } catch (RuntimeException $failure) {
                self::assertStringContainsString('Retired', $failure->getMessage());
            }
            $external = new RestoreSessionAuthority($authority, new DurableJournal($path.'.authority'), self::key());
            $clone->instance(FinancialAuthorityEvidence::class, $external);
            $retirement = $inspector->retirement();
            $open = $authority->issueTransition('open', 'restore-open', ['owner-2', 2, 2, 2, 'retired-egress-and-worker']);
            $external->adopt($open, $retirement);
            $session->openSession('restore-open', 'owner-2', 2, 2, 2, 'retired-egress-and-worker');
            $this->denied($freshCommand);
            $this->denied(fn () => $clone->make(AuthorizationCommands::class)->admit($scope, AuthorizationLifecycleTest::child('capture-denied', 10))->orThrow());
            $this->denied(fn () => $clone->make(AuthorizationCommands::class)->admit($scope, AuthorizationLifecycleTest::child('void-denied', 10, true))->orThrow());
            $this->denied(fn () => $clone->make(RefundCommands::class)->admit($scope, RefundCapacityTest::refund('refund-denied', 10, 83))->orThrow());
            $activate = fn () => $session->activateSession('restore-activate', 2, 'scoped-restore', 'admit-2', 'reconciled');
            $this->denied($activate);
            $this->coverageRefused($coverage, 'unresolved original identity');
            $archive = new SignedOriginalArchive($path, self::key(), $authority);
            $clone->instance(OriginalIdentityArchive::class, $archive);
            $recovery = $clone->make(OriginalIdentityRecovery::class);
            $this->denied(fn () => $recovery->recover($scope, 'remote-billium', 'account-b', $attempt->providerReference));
            $this->denied(fn () => $recovery->recover($scope, 'remote-billium', 'account-a', 'missing-original-reference'));
            self::assertSame('recovery_required', $clone->make(ExecutionStore::class)->namespace($scope)->state->value);
            self::assertSame(0, $receiptQuery($replica)->count());
            self::assertSame(0, $replica->table('billium_execution_business_binding')->where('operation_ref', 'root-a')->count());
            $repeatAccepted = fn () => $clone->make(RootPaymentCommands::class)->admit($scope, $oldWire)->orThrow();
            AuthorizationLifecycleTest::refuses(ProblemCode::RecoveryRequired, $repeatAccepted);
            $clone->make(OperationRunner::class)->run(new OperationSpec('execution.close_original_namespace', 'seller-a', 'test', FinancialMode::Recovery,
                ModuleOperationClass::UnknownResolution, ['execution', 'platform'], financialScope: self::select()), static function (TransactionContext $ctx) use ($clone, $scope): PlatformReceipt {
                    $store = $clone->make(ExecutionStore::class);
                    $store->transitionNamespace($ctx, $scope, NamespaceState::Closed, $store->namespace($scope, $ctx)->generation, 'await-original-source');

                    return new PlatformReceipt('execution.namespace', []);
                })->orThrow();
            AuthorizationLifecycleTest::refuses(ProblemCode::KeyExpired, $repeatAccepted);
            self::assertSame(0, $receiptQuery($replica)->count());
            self::assertSame(0, $replica->table('billium_execution_attempt')->where('operation_ref', 'root-a')->count());
            self::assertSame(0, $replica->table('billium_execution_dispatch_work')->where('operation_ref', 'root-a')->count());
            self::assertSame(2, UnknownResultTest::events($path, 'provider.call'));
            rename($path.'.source', $path.'.offline');
            $this->denied(fn () => $recovery->recover($scope, 'remote-billium', 'account-a', $attempt->providerReference));
            rename($path.'.offline', $path.'.source');
            $clone->instance(OriginalIdentityArchive::class, new SignedOriginalArchive($path, 'foreign-signer', $authority));
            $this->denied(fn () => $recovery->recover($scope, 'remote-billium', 'account-a', $attempt->providerReference));
            $clone->instance(OriginalIdentityArchive::class, $archive);
            self::assertNull($clone->make(ExecutionStore::class)->operation($scope, 'root-a'));
            $applied = $recovery->recover($scope, 'remote-billium', 'account-a', $attempt->providerReference);
            self::assertSame(ReductionDisposition::Applied, $applied->disposition);
            self::assertSame(ReductionDisposition::Duplicate, $recovery->recover($scope, 'remote-billium', 'account-a', $attempt->providerReference)->disposition);
            self::assertSame(NamespaceState::Closed, $clone->make(ExecutionStore::class)->namespace($scope)->state);
            self::assertTrue($repeatAccepted()->replayed);
            self::assertSame(1, $receiptQuery($replica)->count());
            $phase['after_import'] = $this->counts($replica, $path);
            self::assertSame(1, $phase['after_import']['original_identity_import']);
            self::assertSame(1, $phase['after_import']['dispatch_work']);
            self::assertSame(2, $phase['after_import']['provider_calls']);
            self::assertSame(2, $phase['after_import']['observation_emission']);
            $imported = $clone->make(ExecutionStore::class)->operation($scope, 'root-a');
            self::assertEquals($original->binding, $imported?->binding);
            self::assertEquals($original->requested, $imported?->requested);
            self::assertSame($attempt->immutableRequestDigest, $clone->make(ExecutionStore::class)->attempt($scope, $attempt->attemptRef)?->immutableRequestDigest);
            self::relay($replica, $receiver, $path);
            $received = new DurableJournal($path.'.receiver')->records();
            self::assertCount(3, $received);
            self::assertSame($received[0]->data['binding'], $received[1]->data['binding']);
            self::assertSame([1, 1, 2], array_map(static fn (object $r) => $r->data['projection'], $received));
            self::assertSame([1, 1, 2], array_map(static fn (object $r) => $r->data['allocation'], $received));
            self::assertCount(3, array_unique(array_map(static fn (object $r) => $r->pid, $received)));
            $merchant = $clone->make(ExecutionStore::class)->merchantRevision($scope, 'merchant-a');
            $credentials = $clone->make(ContinuityMerchantCredentials::class)->resolve($merchant, 'lookup');
            $driver = $clone->make(PaymentDrivers::class)->resolve($merchant->driverId, $merchant->driverContractVersion);
            $listed = $driver->inventory($merchant, $credentials, $from, null);
            self::assertTrue($listed->complete);
            self::assertCount(2, $listed->evidence);
            Inventory::bindInventory($clone, $path);
            $through = new DateTimeImmutable;
            $inventory = $clone->make(ReconciliationCommands::class);
            Inventory::page($path, array_slice($listed->evidence, 0, 1), next: 'page-2');
            $inventory->inventory($scope, 'remote-billium', 'restore-inventory', 'merchant-a', 'RUB', $from, $through)->orThrow();
            $this->denied($activate);
            $this->coverageRefused($coverage, 'paginated inventory is incomplete');
            Inventory::page($path, array_slice($listed->evidence, 1), 'page-2');
            $inventory->inventory($scope, 'remote-billium', 'restore-inventory', 'merchant-a', 'RUB', $from, $through)->orThrow();
            $report = $replica->table('billium_execution_reconciliation_run')->where('run_ref', 'restore-inventory')->first();
            self::assertSame('complete', $report->state);
            self::assertSame(0, $replica->table('billium_execution_provider_work')->whereNotIn('state', ['processed'])->count());
            $this->coverageRefused(fn (): RecoveryCoverage => $inspector->inspect($replica, $scope, 'wrong-account'), 'no authenticated inventory');
            $late = CallbackCustodyTest::signed($listed->evidence[0], ['revision' => '0']);
            $clone->make(CallbackCustody::class)->receive($scope, 'merchant-a', 'root-before', $late)->orThrow();
            $this->coverageRefused($coverage, 'Raw provider custody awaits financial review');
            $this->denied($activate);
            $reviewed = $clone->make(EvidenceReducer::class)->reduce(CallbackCustodyTest::work($late));
            self::assertContains($reviewed->disposition, [ReductionDisposition::Stale, ReductionDisposition::Duplicate]);
            $this->activateCovered($clone, $authority, $external, $retirement, $activate, $coverage(), $path);
            self::assertSame('active', $session->currentSession(self::INSTALLATION)?->state);
            $host->openSession(new DispatchRequest(...array_replace(get_object_vars($ticket->claims), ['ownerSessionRef' => 'owner-2', 'localSessionEpoch' => 2, 'authorityEpoch' => 2, 'deploymentGeneration' => 2, 'attachmentGeneration' => 2])));
            $clone->make(OperationRunner::class)->run(new OperationSpec('execution.apply_reconciled_namespace', 'seller-a', 'test', FinancialMode::Active, ModuleOperationClass::UnknownResolution,
                ['execution', 'platform'], financialScope: self::select()), static function (TransactionContext $ctx) use ($clone, $scope): PlatformReceipt {
                    $store = $clone->make(ExecutionStore::class);
                    $store->transitionNamespace($ctx, $scope, NamespaceState::Open, $store->namespace($scope, $ctx)->generation, 'scoped-restore');

                    return new PlatformReceipt('execution.namespace', []);
                })->orThrow();
            self::assertTrue($clone->make(RootPaymentCommands::class)->admit($scope, $oldWire)->orThrow()->replayed);
            AuthorizationLifecycleTest::refuses(ProblemCode::IdempotencyConflict, fn () => $clone->make(RootPaymentCommands::class)->admit($scope, self::charge('root-alias', 'intent-a', 72))->orThrow());
            AuthorizationLifecycleTest::refuses(ProblemCode::IdempotencyConflict, fn () => $clone->make(RootPaymentCommands::class)->admit($scope, Fixture::wire(['money' => ['amount' => '2.00', 'currency' => 'RUB']]))->orThrow());
            $this->denied(fn () => $clone->make(AttemptWorker::class)->replayOriginalWithinWindow($scope, $attempt->attemptRef, 'replay-closed', new DateTimeImmutable('+30 seconds'), 'invalid-old-approval')->orThrow());
            self::assertTrue($freshCommand()->accepted);
            self::assertTrue($freshCommand()->replayed);
            AuthorizationLifecycleTest::dispatch($clone, 'root-fresh');
            AuthorizationLifecycleTest::dispatch($clone, 'root-a');
            self::assertSame(3, UnknownResultTest::events($path, 'provider.call'));
            $phase['activated'] = $this->counts($replica, $path);
            fwrite(STDOUT, 'Execution restore evidence '.json_encode(['pgDumpSha256' => $backup->sha256, 'counts' => $phase, 'receiver' => array_map(static fn (object $r): array => [$r->pid, $r->data], $received)], JSON_THROW_ON_ERROR).PHP_EOL);
        } finally {
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $source;
            putenv('DB_DATABASE='.$source);
            if ($child !== null) {
                $child->kill();
            }
            $backup?->close();
            foreach (glob($path.'*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    public function test_import_of_a_lost_postbackup_refund_restores_original_child_key_and_reservation_once(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        $source = TestEnvironment::databaseName();
        $backup = null;
        try {
            RefundCapacityTest::captured($app, $path, AssignedProviderIdRecoveryTest::capability());
            $scope = Fixture::scope();
            $wire = RefundCapacityTest::refund('lost-refund', 35, 53);
            $archive = new SignedOriginalArchive($path, self::key(), $this->authority);
            $archive->recordSource($wire);
            $backup = new PostgresSnapshot(TestEnvironment::pdo(owner: true), $source, $path.'.dump');
            $app->make(RefundCommands::class)->admit($scope, $wire)->orThrow();
            AuthorizationLifecycleTest::dispatch($app, 'lost-refund');
            $store = $app->make(ExecutionStore::class);
            $operation = $store->operation($scope, 'lost-refund');
            $attempt = $store->attempt($scope, RefundCapacityTest::attemptRef('lost-refund'));
            $accepted = array_find(new DurableJournal($path)->records(), static fn ($r): bool => $r instanceof ProviderRecord && $r->kind === EffectKind::Refund);
            self::assertInstanceOf(ProviderRecord::class, $accepted);
            self::assertNotNull($operation);
            self::assertNotNull($attempt);
            $token = $this->tokens->currentToken(self::INSTALLATION);
            self::assertNotNull($token);
            $archive->recordDispatch($accepted, $operation, $attempt, $store->merchantRevision($scope, 'merchant-a'), AuthorityClaims::sourceLineage($token, 1));
            $cloneName = $backup->restore();
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $cloneName;
            putenv('DB_DATABASE='.$cloneName);
            $clone = self::cloneApplication($app);
            $authority = $clone->make(SimulatedFinancialAuthorityEvidence::class);
            $authority->issueTransition('open', 'refund-open', ['owner-2', 2, 2, 2, 'retired-refund']);
            $clone->make(Installation::class)->openSession('refund-open', 'owner-2', 2, 2, 2, 'retired-refund');
            $token = FixedSessionAdmission::token(self::INSTALLATION, 'owner-2', 2, 2, 2, [FinancialMode::Recovery]);
            $clone->make(FinancialSessionAdmission::class)->set($token);
            $authority->issueProcess($token, [self::select()]);
            $archive = new SignedOriginalArchive($path, self::key(), $authority);
            $clone->instance(OriginalIdentityArchive::class, $archive);
            $port = $clone->make(OriginalIdentityRecovery::class);
            $recover = fn () => $port->recover($scope, 'remote-billium', 'account-a', $attempt->providerReference);
            $drivers = $clone->make(PaymentDrivers::class);
            $merchant = $store->merchantRevision($scope, 'merchant-a');
            $driver = $drivers->resolve($merchant->driverId, $merchant->driverContractVersion);
            $wrong = self::createStub(PaymentDriver::class);
            $wrong->method('contractVersion')->willReturn($driver->contractVersion());
            $wrong->method('normalize')->willReturnCallback($driver->normalize(...));
            $field = 'providerOperationId';
            $wrong->method('lookup')->willReturnCallback(static function (LookupRequest $request) use ($driver, &$field): ProviderLookupResult {
                $real = $driver->lookup($request);
                self::assertNotNull($real->snapshot);
                $foreign = new MaterialSnapshot(...array_replace(get_object_vars($real->snapshot), [$field => 'unrelated-assignment']));

                return new ProviderLookupResult($real->state, $real->evidence, $foreign, null, null, null);
            });
            $foreign = self::createStub(PaymentDrivers::class);
            $foreign->method('resolve')->willReturn($wrong);
            $foreign->method('capabilities')->willReturn($drivers->capabilities($merchant));
            $clone->instance(PaymentDrivers::class, $foreign);
            $otherScope = new ExecutionScope($scope->installationRef, $scope->sellerRef, $scope->environment, 'another-owner');
            AuthorizationLifecycleTest::refuses(ProblemCode::AuthDenied, fn () => $port->recover($otherScope, 'remote-billium', 'account-a', $attempt->providerReference));
            foreach (['providerOperationId', 'providerReference', 'operationRef'] as $field) {
                AuthorizationLifecycleTest::refuses(ProblemCode::RecoveryRequired, $recover);
            }
            $db = $clone->make(DatabaseManager::class)->connection();
            self::assertSame(3, $db->table('billium_execution_provider_evidence')->where('signature_verdict', 'unverified')->where('evidence_ref', 'like', 'review:%')->count());
            self::assertSame(0, $db->table('billium_execution_capacity')->value('confirmed_refund_minor'));
            self::assertSame(35, $db->table('billium_execution_capacity')->value('held_refund_minor'));
            self::assertSame('held', $db->table('billium_execution_reservation')->where('operation_ref', 'lost-refund')->value('state'));
            self::assertSame(0, $db->table('billium_execution_provider_fact')->where('operation_ref', 'lost-refund')->count());
            $clone->instance(PaymentDrivers::class, $drivers);
            self::assertSame(ReductionDisposition::Applied, $recover()->disposition);
            self::assertSame(ReductionDisposition::Duplicate, $recover()->disposition);
            self::assertSame('root-a', $db->table('billium_idempotency_receipt')->where('command_kind', 'payment.refund.v1')->value('original_execution_binding_ref'));
            $lookup = array_find(new DurableJournal($path)->records(), static fn (object $entry): bool => $entry instanceof JournalEvent && $entry->kind === 'provider.lookup');
            self::assertInstanceOf(JournalEvent::class, $lookup);
            self::assertNotNull($accepted->providerOperationId);
            self::assertSame($accepted->providerOperationId, $lookup->data['providerOperationId'] ?? null);
            self::assertSame($attempt->providerReference, $lookup->data['reference'] ?? null);
            $reservation = $db->table('billium_execution_reservation')->where('operation_ref', 'lost-refund')->sole();
            self::assertSame('consumed', $reservation->state);
            self::assertSame(35, $reservation->consumed_minor);
            self::assertSame(1, $db->table('billium_execution_child_identity')->where('business_intent_ref', 'business-lost-refund')->count());
            self::assertSame(35, $db->table('billium_execution_capacity')->value('confirmed_refund_minor'));
            self::assertSame(0, $db->table('billium_execution_dispatch_work')->where('operation_ref', 'lost-refund')->count());
            self::assertSame(2, UnknownResultTest::events($path, 'provider.call'));
            $identity = $archive->cached($scope, 'account-a', $attempt->providerReference, $token, 2);
            self::assertNotNull($identity);
            $forged = new ArchivedExecutionIdentity(...array_replace(get_object_vars($identity), ['businessIntentRef' => 'another-intent']));
            $this->denied(fn () => $clone->make(OperationRunner::class)->run(new OperationSpec('execution.probe_import', 'seller-a', 'test',
                FinancialMode::Recovery, ModuleOperationClass::UnknownResolution, ['execution', 'platform'], financialScope: self::select()),
                fn (TransactionContext $ctx) => $clone->make(ExecutionStore::class)->importOriginalIdentity($ctx, $forged, true))->orThrow());
            self::assertSame(0, $db->table('billium_execution_child_identity')->where('business_intent_ref', 'another-intent')->count());
        } finally {
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $source;
            putenv('DB_DATABASE='.$source);
            $backup?->close();
            foreach (glob($path.'*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    #[Group('ACC-RST-13')]
    public function test_a_copy_missing_postbackup_local_cancel_and_customer_credit_allocation_cannot_dispatch_or_allocate(): void
    {
        $app = $this->application();
        $scope = Fixture::scope();
        $source = TestEnvironment::databaseName();
        $path = Fixture::tempPath();
        $backup = null;
        try {
            Fixture::seedMerchant($app);
            Fixture::host($app, $path);
            $app->make(RootPaymentCommands::class)->admit($scope, Fixture::wire())->orThrow();
            $ledger = $app->make(Ledger::class);
            $account = new BillingAccountScope('seller-a', 'test', 'billing-credit');
            $money = new Money(70, Currency::of('RUB'));
            $ledger->recordObligation(new RecordObligation('debt', $account, 'debt', 'basis', 'customer', 'component', $money, 'sha256:'.str_repeat('a', 64)))->orThrow();
            $ledger->recordCustomerCredit(new RecordCustomerCredit('credit', $account, 'credit', 'basis', $money))->orThrow();
            $backup = new PostgresSnapshot(TestEnvironment::pdo(owner: true), $source, $path.'.dump');
            $app->make(RootPaymentCommands::class)->cancel($scope, DispatchCancelTest::command())->orThrow();
            $allocate = new Allocate('apply-credit', $account, 'allocation', 'debt', $money, customerCreditRef: 'credit');
            $ledger->allocate($allocate)->orThrow();
            $this->authority->issueTransition('seal', 'local-only-seal', [1]);
            $this->installation()->seal('local-only-seal', 1);
            self::assertSame(1, $this->rows('billium_allocation'));
            $latest = $this->value('SELECT max(commit_no) FROM billium_money_commit_log');
            $cloneName = $backup->restore();
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $cloneName;
            putenv('DB_DATABASE='.$cloneName);
            $clone = self::cloneApplication($app);
            $db = $clone->make(DatabaseManager::class)->connection();
            self::assertSame('admitted', $db->table('billium_execution_attempt')->value('state'));
            self::assertSame(0, $db->table('billium_allocation')->count());
            self::assertLessThan($latest, $db->table('billium_money_commit_log')->max('commit_no'));
            $token = FixedSessionAdmission::token(self::INSTALLATION, attachmentGeneration: 2);
            $clone->make(FinancialSessionAdmission::class)->set($token);
            $clone->make(SimulatedFinancialAuthorityEvidence::class)->issueProcess($token);
            $this->denied(fn () => $clone->make(AttemptWorker::class)->dispatch($scope, UnknownResultTest::attemptRef(), 'rolled-back-copy', new DateTimeImmutable('+30 seconds'))->orThrow());
            $clone['config']->set('billium.modules.composition', 'ledger-only');
            $clone['config']->set('billium.modules.enabled', ['ledger', 'platform']);
            $clone->forgetInstance(BilliumOptions::class);
            $clone->forgetInstance(DatabaseInstallation::class);
            $this->denied(fn () => $clone->make(Ledger::class)->allocate($allocate)->orThrow());
            self::assertSame(0, $db->table('billium_allocation')->count());
            self::assertSame(0, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $source;
            putenv('DB_DATABASE='.$source);
            $backup?->close();
            foreach (glob($path.'*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('billium.modules.enabled', ['execution', 'ledger', 'platform', 'collection']);
    }

    public static function transferWorker(string $path, string $role): void
    {
        $app = Fixture::workerApp(901);
        $app['config']->set('billium.modules.enabled', ['execution', 'ledger', 'platform', 'collection']);
        SimulatedExecutionReadiness::install($app, true);
        $db = $app->make(DatabaseManager::class)->connection();
        $journal = new DurableJournal($path.'.control');
        $barrier = new ProcessBarrier($journal);
        if ($role === 'seal') {
            $app->make(SimulatedFinancialAuthorityEvidence::class)->issueTransition('seal', 'transfer-race-seal', [1]);
            $app->make(Installation::class)->seal('transfer-race-seal', 1);
            $journal->append(new JournalEvent('sealed', ['pg' => $db->scalar('SELECT pg_backend_pid()')], ProcessIdentity::current()));

            return;
        }
        $host = Fixture::host($app, $path, [Fixture::origin('step-a')]);
        CollectionAdmissionGuardTest::guard($app);
        $app->instance(DispatchEgress::class, new readonly class($host, $barrier) implements DispatchEgress
        {
            public function __construct(private DeterministicWriterHost $host, private ProcessBarrier $barrier) {}

            public function invoke(DispatchTicket $ticket, DriverRequest $request, Closure $send): ProviderLookupResult
            {
                $this->barrier->arrive('after-txb');
                $this->barrier->await('egress', 1, 20_000);

                return $this->host->invoke($ticket, $request, $send);
            }
        });
        $barrier->arrive('ready');
        $barrier->await('claim', 1, 20_000);
        $outcome = $app->make(AttemptWorker::class)->dispatch(Fixture::scope(), UnknownResultTest::attemptRef(), 'transfer-old', new DateTimeImmutable('+30 seconds'))->orThrow();
        $journal->append(new JournalEvent('returned', ['pg' => $db->scalar('SELECT pg_backend_pid()'), 'receipt' => $outcome->body['receiptRef']], ProcessIdentity::current()));
    }

    #[Group('ACC-CON-22')]
    #[Group('ACC-CON-15')]
    #[TestWith([false])]
    #[TestWith([true])]
    public function test_shared_writer_arbitrates_live_and_copied_dbs_across_committed_dispatch_and_quarantine(bool $sealBeforeEgress): void
    {
        $app = $this->application();
        $scope = Fixture::scope();
        $path = Fixture::tempPath();
        $source = TestEnvironment::databaseName();
        $backup = $worker = null;
        try {
            Fixture::seedMerchant($app);
            CollectionAdmissionGuardTest::installStep($app);
            $host = Fixture::host($app, $path, [Fixture::origin('step-a')]);
            CollectionAdmissionGuardTest::guard($app);
            $app->make(RootPaymentCommands::class)->admit($scope, Fixture::wire())->orThrow();
            $backup = new PostgresSnapshot(TestEnvironment::pdo(owner: true), $source, $path.'.dump');
            $cloneName = $backup->restore();
            $child = static fn (string $role): ChildProcess => new ChildProcess([PHP_BINARY, '-r',
                'require "vendor/autoload.php"; Billium\\Core\\Tests\\Drill\\ExecutionRestoreTest::transferWorker($argv[1],$argv[2]);', $path, $role],
                array_replace(getenv(), ['DB_DATABASE' => $source]));
            $worker = $child('dispatch');
            $journal = new DurableJournal($path.'.control');
            $barrier = new ProcessBarrier($journal);
            $barrier->await('ready', 1, 15_000);
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $cloneName;
            putenv('DB_DATABASE='.$cloneName);
            $clone = self::cloneApplication($app);
            CollectionAdmissionGuardTest::guard($clone);
            $db = $clone->make(DatabaseManager::class)->connection();
            $tokens = $clone->make(FinancialSessionAdmission::class);
            $candidate = FixedSessionAdmission::token(self::INSTALLATION, 'owner-2', 2, 2, 2);
            $tokens->set($candidate);
            $clone->make(SimulatedFinancialAuthorityEvidence::class)->issueProcess($candidate, [self::select()]);
            $barrier->arrive('claim');
            $send = fn () => $clone->make(AttemptWorker::class)->dispatch($scope, UnknownResultTest::attemptRef(), 'clone', new DateTimeImmutable('+30 seconds'))->orThrow();
            $this->denied($send);
            $barrier->await('after-txb', 1, 15_000);
            $ticket = array_find(new DurableJournal($path.'.host')->records(), static fn ($r): bool => $r instanceof DispatchTicket);
            self::assertInstanceOf(DispatchTicket::class, $ticket);
            $foreign = new DispatchRequest(...array_replace(get_object_vars($ticket->claims), ['ownerSessionRef' => 'owner-2', 'authorityEpoch' => 2, 'deploymentGeneration' => 2, 'attachmentGeneration' => 2, 'localSessionEpoch' => 2]));
            try {
                $host->ticket($foreign);
                self::fail('Second attachment obtained overlapping writer ticket');
            } catch (RuntimeException $failure) {
                self::assertStringContainsString('No independently granted', $failure->getMessage());
            }
            if (! $sealBeforeEgress) {
                $barrier->arrive('egress');
                self::assertSame(0, $worker->wait());
                $worker = null;
            }
            $sealer = $child('seal');
            self::assertSame(0, $sealer->wait());
            $host->retire($ticket->claims, 'transfer process revoked old egress');
            if ($worker !== null) {
                $barrier->arrive('egress');
                self::assertSame(0, $worker->wait());
                $worker = null;
            }
            $this->denied($send);
            self::assertSame($sealBeforeEgress ? 0 : 1, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(1, count(array_filter(new DurableJournal($path.'.host')->records(), static fn (object $r): bool => $r instanceof DispatchTicket)));
            self::assertNull($db->table('billium_test_collection_step')->value('dispatch_permit'));
            self::assertSame('admitted', $db->table('billium_execution_attempt')->value('state'));
            self::assertSame(0, $db->table('billium_execution_attempt')->whereNotNull('started_at')->count());
            $parties = array_values(array_filter($journal->records(), static fn (object $r): bool => $r instanceof JournalEvent && in_array($r->kind, ['sealed', 'returned'], true)));
            self::assertCount(3, array_unique([getmypid(), ...array_map(static fn (JournalEvent $r): int => $r->pid, $parties)]));
            self::assertCount(3, array_unique([$db->scalar('SELECT pg_backend_pid()'), ...array_map(static fn (JournalEvent $r): string|int|bool => $r->data['pg'], $parties)]));
            fwrite(STDOUT, 'Restore writer race '.json_encode(['old' => $source, 'copy' => $cloneName, 'sealedBeforeEgress' => $sealBeforeEgress,
                'copyBackend' => $db->scalar('SELECT pg_backend_pid()'), 'copyProcess' => getmypid(), 'peers' => $parties, 'effectCount' => UnknownResultTest::events($path, 'provider.call'),
                'cloneStarted' => 0, 'cloneCollectionPermits' => 0], JSON_THROW_ON_ERROR).PHP_EOL);
        } finally {
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $source;
            putenv('DB_DATABASE='.$source);
            if ($worker !== null) {
                $worker->kill();
            }
            $backup?->close();
            TestEnvironment::pdo(owner: true)->exec('DROP TABLE IF EXISTS billium_test_collection_step');
            foreach (glob($path.'*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    public static function delayedCommit(string $path): void
    {
        $app = Fixture::workerApp(904);
        $db = $app->make(DatabaseManager::class)->connection();
        $barrier = new ProcessBarrier(new DurableJournal($path));
        $db->listen(static function (QueryExecuted $query) use ($barrier): void {
            if (str_starts_with($query->sql, 'INSERT INTO public.billium_money_commit_log ')) {
                $barrier->arrive('number-assigned');
                $barrier->await('commit-allowed', 1, 25_000);
            }
        });
        $app->make(OperationRunner::class)->run(new OperationSpec('ledger.delayed_commit', 'seller-a', 'test', FinancialMode::Active, ModuleOperationClass::NewIntent,
            ['ledger', 'platform'], commitKind: 'ledger.delayed_commit'), static fn (TransactionContext $ctx): PlatformReceipt => self::receipt(['effect' => self::effect($ctx, 'delayed')]))->orThrow();
    }

    public function test_matching_commit_numbers_after_clean_release_cannot_activate_a_copy_missing_an_earlier_transaction(): void
    {
        $path = Fixture::tempPath();
        $source = TestEnvironment::databaseName();
        $backup = $child = null;
        try {
            $app = $this->application();
            Fixture::host($app, $path);
            $barrier = new ProcessBarrier(new DurableJournal($path.'.control'));
            $child = new ChildProcess([PHP_BINARY, '-r', 'require "vendor/autoload.php"; Billium\\Core\\Tests\\Drill\\ExecutionRestoreTest::delayedCommit($argv[1]);', $path.'.control']);
            $barrier->await('number-assigned', 1, 15_000);
            $this->runner()->run($this->spec(kind: 'ledger.committed_first', commit: 'ledger.committed_first'), static fn (TransactionContext $ctx): PlatformReceipt => self::receipt(['effect' => self::effect($ctx, 'visible')]))->orThrow();
            $backup = new PostgresSnapshot(TestEnvironment::pdo(owner: true), $source, $path.'.dump');
            $barrier->arrive('commit-allowed');
            self::assertSame(0, $child->wait());
            $child = null;
            $this->authority->issueTransition('seal', 'equal-counter-release', [1]);
            $this->installation()->seal('equal-counter-release', 1);
            $sourceMax = $this->value('SELECT max(commit_no) FROM billium_money_commit_log');
            self::assertSame(2, $this->rows('billium_test_effect'));
            $copy = $backup->restore();
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $copy;
            putenv('DB_DATABASE='.$copy);
            $app = self::cloneApplication($app);
            $db = $app->make(DatabaseManager::class)->connection();
            self::assertSame(['visible'], $db->table('billium_test_effect')->pluck('ref')->all());
            self::assertSame($sourceMax, $db->table('billium_money_commit_log')->max('commit_no'));
            self::assertSame(0, $db->table('billium_money_commit_log')->where('kind', 'ledger.delayed_commit')->count());
            $token = FixedSessionAdmission::token(self::INSTALLATION, attachmentGeneration: 2);
            $app->make(FinancialSessionAdmission::class)->set($token);
            $app->make(SimulatedFinancialAuthorityEvidence::class)->issueProcess($token);
            $this->denied(fn () => $app->make(OperationRunner::class)->run($this->spec(), static fn (TransactionContext $ctx): PlatformReceipt => self::receipt(['effect' => self::effect($ctx, 'forbidden')]))->orThrow());
            self::assertSame(1, $db->table('billium_test_effect')->count());
            fwrite(STDOUT, 'Equal release diagnostic '.json_encode(['dumpSha256' => $backup->sha256, 'oldMax' => $sourceMax, 'restoredMax' => $db->table('billium_money_commit_log')->max('commit_no'), 'oldEffects' => 2, 'restoredEffects' => 1], JSON_THROW_ON_ERROR).PHP_EOL);
        } finally {
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $source;
            putenv('DB_DATABASE='.$source);
            if ($child !== null) {
                $child->kill();
            }
            $backup?->close();
            foreach (glob($path.'*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    private function coverageRefused(Closure $inspect, string $reason): void
    {
        try {
            $inspect();
            self::fail('Deployment issued coverage for an unreconciled namespace');
        } catch (ProblemException $failure) {
            self::assertStringContainsString($reason, $failure->getMessage());
        }
    }

    private function activateCovered(Application $app, SimulatedFinancialAuthorityEvidence $authority, RestoreSessionAuthority $external, RetirementProof $retirement, Closure $activate, RecoveryCoverage $coverage, string $path): void
    {
        $adopted = $authority->issueTransition('activate', 'restore-activate', [2, 'scoped-restore', 'admit-2', 'reconciled']);
        $valid = $external->adopt($adopted, $retirement, $coverage);
        $accepted = null;
        $verifier = $this->createStub(FinancialAuthorityEvidence::class);
        $verifier->method('process')->willReturnCallback($authority->process(...));
        $verifier->method('transition')->willReturnCallback(static function ($inst, $ref) use ($external, &$accepted): ?VerifiedSessionAuthorization {
            return $ref === 'restore-activate' ? $accepted : $external->transition($inst, $ref);
        });
        $app->instance(FinancialAuthorityEvidence::class, $verifier);
        $accepted = new VerifiedSessionAuthorization(...array_replace(get_object_vars($valid), ['nonce' => 'forged-incarnation']));
        $this->denied($activate);
        $accepted = new VerifiedSessionAuthorization(...array_replace(get_object_vars($valid), ['expiresAt' => new DateTimeImmutable('-1 second')]));
        $this->denied($activate);
        // Crash after external adoption but before local activation. Re-acquire the same nonce from the independently signed journal.
        self::assertSame('recovery', $app->make(Installation::class)->currentSession(self::INSTALLATION)?->state);
        $resumed = new RestoreSessionAuthority($authority, new DurableJournal($path.'.authority'), self::key());
        self::assertSame($valid->nonce, $resumed->transition(self::INSTALLATION, 'restore-activate')?->nonce);
        $app->instance(FinancialAuthorityEvidence::class, $resumed);
        $first = $activate();
        self::assertEquals($first, $activate());
    }
}

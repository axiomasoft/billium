<?php

declare(strict_types=1);

namespace Billium\Conformance\Http;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleLifecycleState;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedExecutionReadiness;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\MerchantRevisionPolicy;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as ExecutionFixture;
use Billium\Execution\Tests\Concurrency\OutcomeDeliveryTest;
use Billium\Execution\Tests\Feature\AuthorizationLifecycleTest;
use Billium\Execution\Tests\Feature\CallbackCustodyTest;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\Obligations\ObligationDuePolicy;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Platform\Data\Feed\RelayReport;
use Billium\Platform\Data\Installation\ModuleTransitionRequest;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Platform\Internal\EventContracts\DatabaseEventContractRegistrar;
use Billium\Server\BilliumServerServiceProvider;
use Billium\Server\Contracts\Authenticator;
use Billium\Server\Contracts\AuthorizationVerdict;
use Billium\Server\Contracts\InstallationContextResolver;
use Billium\Server\Contracts\SellerContextResolver;
use Billium\Server\Contracts\SellerEligibility;
use Billium\Server\Data\AuthenticatedPrincipal;
use Billium\Server\Data\ExecutionContext;
use Billium\Server\Data\InstallationContext;
use Billium\Server\Data\RequestMeta;
use Billium\Server\Data\ResourceScope;
use Billium\Server\Data\SellerAdmission;
use Billium\Server\Data\SellerScope;
use Billium\Server\Data\SourceAuthorityGrant;
use Billium\Server\Enums\Verdict;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Drivers\SimulatedAbsenceVerifier;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use Billium\Testkit\Fixtures\JournalEvent;
use BilliumMinimalHost\Host;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\PackageManifest;
use LogicException;

/** Test-only host policy and evidence. The HTTP kernel still owns all request handling. */
final readonly class LoopbackHost implements Authenticator, AuthorizationVerdict, InstallationContextResolver, SellerEligibility
{
    public const string INSTALLATION = 'inst-test';

    public const string SELLER = 'seller-a';

    public const string NAMESPACE = 'source-a';

    /** @var list<string> */
    public const array OPERATIONS = ['root-a', 'root-b', 'after-drain'];

    public function __construct(public string $directory, private string $profile) {}

    public function application(string $database, bool $writer = true): Application
    {
        $app = new Host('unused')->application($this->directory, $database, $this->composition(), $this->modules(),
            [BilliumCoreServiceProvider::class, BilliumServerServiceProvider::class]);
        $app->make(PackageManifest::class)->manifest = [];
        if (PHP_SAPI === 'cli') {
            $app->afterBootstrapping(HandleExceptions::class, static function (): void {
                restore_error_handler();
                restore_exception_handler();
            });
        }
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $config = $app->make(Repository::class);
            $config->set('app.env', 'testing');
            $config->set('database.default', 'server_runtime');
            $config->set('database.connections.server_runtime.timezone', 'UTC');
            $config->set('database.connections.server_owner.timezone', 'UTC');
            $config->set('database.connections.billium_owner', $config->get('database.connections.server_owner'));
            $config->set('billium.installation_ref', self::INSTALLATION);
            if ($this->profile === 'execution') {
                SimulatedExecutionReadiness::install($app);
            }
        });

        foreach ([Authenticator::class, AuthorizationVerdict::class, InstallationContextResolver::class, SellerEligibility::class] as $port) {
            $app->instance($port, $this);
        }
        $app->instance(SellerContextResolver::class, new readonly class($this) implements SellerContextResolver
        {
            public function __construct(private LoopbackHost $host) {}

            public function resolve(AuthenticatedPrincipal $principal, string $routeSeller): ExecutionContext
            {
                return $this->host->context($principal, $routeSeller);
            }
        });
        $app->instance(CallerContext::class, new FixedCallerContext(new Caller(self::SELLER, 'test', self::INSTALLATION,
            'fixture-host', ['remote-billium', 'embedded-commerz'], [self::NAMESPACE])));
        $app->make(Kernel::class)->bootstrap();

        if ($this->profile === 'execution') {
            $this->drivers($app);
            $app->instance(ProviderAbsenceVerifier::class, new SimulatedAbsenceVerifier(
                new DurableJournal($this->directory.'/provider.journal'), new DurableJournal($this->directory.'/provider.journal.host'),
                new DurableJournal($this->directory.'/absence.journal'), new FixtureClock(new DateTimeImmutable),
                SimulatedCapabilities::snapshot(), static fn (): int => self::database($app)->transactionLevel(),
            ));
            if (! $writer) {
                $app->forgetInstance(WriterAdmission::class);
            }
        }

        return $app;
    }

    /** @return list<string> */
    private function modules(): array
    {
        return $this->profile === 'execution' ? ['platform', 'ledger', 'execution'] : ['platform', 'ledger'];
    }

    private function composition(): string
    {
        return $this->profile === 'execution' ? 'remote-billium' : 'embedded-commerz';
    }

    public function authenticate(array $headers, RequestMeta $requestMeta): AuthenticatedPrincipal
    {
        $header = $headers['authorization'][0] ?? '';
        foreach (['owner', 'other-seller', 'revoked', 'source-x', 'source-y'] as $actor) {
            if ($header === 'Bearer '.$actor) {
                return new AuthenticatedPrincipal($actor);
            }
        }

        throw new ProblemException(ProblemCode::AuthUnauthenticated);
    }

    public function resolve(AuthenticatedPrincipal $principal): InstallationContext
    {
        return new InstallationContext(self::INSTALLATION, 'test');
    }

    public function context(AuthenticatedPrincipal $principal, string $seller): ExecutionContext
    {
        if ($seller !== ($principal->principalRef === 'other-seller' ? 'seller-b' : self::SELLER)) {
            throw new ProblemException(ProblemCode::AuthDenied);
        }
        $sources = match ($principal->principalRef) {
            'source-x' => ['embedded-commerz'],
            'source-y' => ['remote-billium'],
            default => ['embedded-commerz'],
        };
        $grants = array_map(static fn (string $source): SourceAuthorityGrant => new SourceAuthorityGrant(
            'payment.observation', $source, self::INSTALLATION, 'test', $seller, 'L1',
            ['main', 'shared', 'gap', 'overcapture', 'overrefund'],
        ), $sources);

        return new ExecutionContext(self::INSTALLATION, 'test', $seller, $principal->principalRef,
            sourceAuthorities: $grants, intentOwnerNamespace: $seller === self::SELLER ? self::NAMESPACE : 'source-b',
            executionAuthorities: ['remote-billium']);
    }

    public function admission(SellerScope $scope): ?SellerAdmission
    {
        return new SellerAdmission($scope, in_array($scope->sellerRef, [self::SELLER, 'seller-b'], true));
    }

    public function authorize(AuthenticatedPrincipal $principal, string $action, ResourceScope $resourceScope, string $payloadDigest): Verdict
    {
        if ($principal->principalRef === 'revoked') {
            return Verdict::Deny;
        }
        $source = match ($principal->principalRef) {
            'source-x' => 'embedded-commerz',
            'source-y' => 'remote-billium',
            default => null,
        };
        if ($source !== null && $action === 'receipt.find') {
            $receipt = $resourceScope->selectors['receiptRef'] ?? null;

            return is_string($receipt) && str_starts_with($receipt, 'observation:'.$source.':') ? Verdict::Allow : Verdict::Deny;
        }

        return Verdict::Allow;
    }

    public function install(Application $app): void
    {
        Host::migrate($app, $this->modules());
        $installation = $app->make(Installation::class);
        $installation->init(self::INSTALLATION);
        if ($this->profile === 'custody') {
            return;
        }
        $this->startSession($app);

        if ($this->profile === 'execution') {
            $modules = $app->make(ModuleRegistry::class);
            $registrar = new DatabaseEventContractRegistrar(self::database($app, true), $modules);
            $registrar->register($registrar->plan($modules->descriptor('execution') ?? throw new LogicException('Execution descriptor missing'), true));
            ExecutionFixture::seedMerchant($app);
        }
        $this->seedLedger($app);
    }

    private function startSession(Application $app): void
    {
        SimulatedFinancialAuthorityEvidence::reset();
        $authority = SimulatedFinancialAuthorityEvidence::install($app);
        $installation = $app->make(Installation::class);
        $authority->issueTransition('open', 'open-http', ['owner-1', 1, 1, 1, 'evidence:open-http']);
        $installation->openSession('open-http', 'owner-1', 1, 1, 1, 'evidence:open-http');
        $authority->issueTransition('activate', 'activate-http', [1, 'certificate:http', 'admission:http', 'evidence:activate-http']);
        $installation->activateSession('activate-http', 1, 'certificate:http', 'admission:http', 'evidence:activate-http');
        file_put_contents($this->directory.'/authority.json', json_encode([
            'BILLIUM_SIMULATED_AUTHORITY_KEY' => getenv('BILLIUM_SIMULATED_AUTHORITY_KEY'),
            'BILLIUM_SIMULATED_AUTHORITY_JOURNAL' => getenv('BILLIUM_SIMULATED_AUTHORITY_JOURNAL'),
        ], JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($this->directory.'/authority.json', 0600);
        $this->admitProcess($app);
    }

    /** @return array{session: string} */
    public function activateCustodyStand(Application $app): array
    {
        if ($this->profile !== 'custody' || is_file($this->directory.'/authority.json')) {
            throw new LogicException('Expected a pre-activation custody fixture');
        }
        $this->startSession($app);
        $this->seedLedger($app);

        return ['session' => 'active'];
    }

    private function seedLedger(Application $app): void
    {
        $ledger = $app->make(Ledger::class);
        $scope = new BillingAccountScope(self::SELLER, 'test', 'billing-a');
        $digest = 'sha256:'.str_repeat('a', 64);
        $due = new ObligationDuePolicy(new DateTimeImmutable('2026-10-01T00:00:00Z'), 'fixture/1', 'fixture-policy', $digest);
        foreach (['obligation-a', 'obligation-b'] as $ref) {
            $ledger->recordObligation(new RecordObligation('key-'.$ref, $scope, $ref, 'basis-'.$ref,
                'customer-a', 'component', Money::parse('10.00', 'RUB'), $digest, duePolicy: $due))->orThrow();
        }
        $ledger->declareSettlementIntent(new DeclareSettlementIntent('intent-command', $scope, 'intent-a', 'RUB', ['obligation-a']))->orThrow();
    }

    /** @return array{seller: string} */
    public function seedOtherSeller(Application $app): array
    {
        $app->instance(CallerContext::class, FixedCallerContext::default('seller-b', installationRef: self::INSTALLATION));
        $scope = new BillingAccountScope('seller-b', 'test', 'billing-a');
        $digest = 'sha256:'.str_repeat('b', 64);
        $due = new ObligationDuePolicy(new DateTimeImmutable('2026-10-01T00:00:00Z'), 'fixture/1', 'fixture-policy', $digest);
        $app->make(Ledger::class)->recordObligation(new RecordObligation('key-obligation-a', $scope, 'obligation-other', 'basis-other',
            'customer-a', 'component', Money::parse('5.00', 'RUB'), $digest, duePolicy: $due))->orThrow();
        if ($this->profile === 'execution') {
            $other = new ExecutionScope(self::INSTALLATION, 'seller-b', 'test', 'source-b');
            $app->instance(CallerContext::class, new FixedCallerContext(new Caller($other->sellerRef, 'test', self::INSTALLATION,
                'other-seller', ['remote-billium'], [$other->intentOwnerNamespace])));
            ExecutionFixture::seedMerchant($app, scope: $other);
        }

        return ['seller' => 'seller-b'];
    }

    public function admitProcess(Application $app): void
    {
        if (is_file($this->directory.'/attachment-quarantined') || ! is_file($this->directory.'/authority.json')) {
            return;
        }
        $evidence = json_decode((string) file_get_contents($this->directory.'/authority.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($evidence as $name => $value) {
            putenv($name.'='.$value);
        }
        $tokens = new FixedSessionAdmission(FixedSessionAdmission::token(self::INSTALLATION, processRef: 'http-'.getmypid()));
        $app->instance(FinancialSessionAdmission::class, $tokens);
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);
    }

    public function drivers(Application $app, ResponseMode $mode = ResponseMode::Success): void
    {
        $scope = self::scope();
        $origins = array_map(static fn (string $operation) => ExecutionFixture::origin(operation: $operation, scope: $scope),
            self::OPERATIONS);
        $origins[] = ExecutionFixture::origin(scope: new ExecutionScope(self::INSTALLATION, 'seller-b', 'test', 'source-b'));
        ExecutionFixture::host($app, $this->directory.'/provider.journal', $origins, mode: $mode);
    }

    public static function scope(): ExecutionScope
    {
        return new ExecutionScope(self::INSTALLATION, self::SELLER, 'test', self::NAMESPACE);
    }

    public static function database(Application $app, bool $owner = false): Connection
    {
        return $app->make(DatabaseManager::class)->connection($owner ? 'server_owner' : 'server_runtime');
    }

    /** @return array<string,mixed> */
    public function worker(Application $app, string $operation, string $action, string $mode): array
    {
        $this->drivers($app, ResponseMode::from($mode));
        $db = self::database($app);
        $attempt = $db->table('billium_execution_attempt')->where('operation_ref', $operation)->value('attempt_ref');
        if (! is_string($attempt)) {
            throw new LogicException('Expected a durably admitted attempt');
        }
        $worker = $app->make(AttemptWorker::class);
        // Recovery waits for the retry due time. Keep the worker lease valid while it records the timeout.
        $lease = new DateTimeImmutable('+1 minute');
        $outcome = match ($action) {
            'dispatch' => $worker->dispatch(self::scope(), $attempt, 'http-worker', $lease),
            'lookup' => $worker->recover(self::scope(), $attempt, 'http-recovery', $lease),
            default => throw new LogicException('Unknown worker action'),
        };
        $outcome->orThrow();
        $reductions = [];
        foreach ($db->table('billium_execution_provider_work')->where('operation_hint', $operation)->where('state', 'due')->pluck('work_ref') as $ref) {
            $reductions[] = $app->make(EvidenceReducer::class)->reduce($ref);
        }

        return ['status' => $outcome->body['status'], 'reductions' => $reductions];
    }

    /** @param array<string,mixed> $changes */
    public function lateEvidence(Application $app, string $operation, array $changes): object
    {
        $original = AuthorizationLifecycleTest::evidence($app, $operation);

        return AuthorizationLifecycleTest::receive($app, $operation, CallbackCustodyTest::signed($original, $changes));
    }

    public function drain(Application $app): void
    {
        $app->make(Installation::class)->transitionModule(new ModuleTransitionRequest(
            'drain-http', 'execution', ModuleLifecycleState::Draining, 'Loopback proof',
        ))->orThrow();
    }

    public function stopNew(Application $app): void
    {
        $scope = self::scope();
        $old = $app->make(MerchantRegistry::class)->historical($scope, 'root-a');
        $next = SimulatedCapabilities::snapshot('replacement-account');
        $registry = $app->make(DatabaseMerchantRegistry::class);
        $storage = $app->make(ExecutionStore::class);
        $selection = new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, 'remote-billium');
        $app->make(OperationRunner::class)->run(new OperationSpec('execution.fixture_switch', $scope->sellerRef, $scope->environment,
            FinancialMode::Administrative, ModuleOperationClass::HistoricalRead, ['execution', 'platform'], financialScope: $selection),
            static function (TransactionContext $context) use ($scope, $old, $next, $registry, $storage): PlatformReceipt {
                $time = new DateTimeImmutable('-1 minute');
                $registry->appendLifecycle($context, $scope, $old->revisionRef, 'stop-old-route', 'stop_new', 'new',
                    $time, null, 'HTTP-11 owner change', 'fixture-approval');
                $replacement = new MerchantRevision('replacement', $scope, $next->merchantAccountRef, $old->providerId,
                    $next->driverId, $next->driverContractVersion, $next->snapshotRef, $next->digest,
                    new SecretRef('secret://fixture/replacement'), 'replacement-v1', $next->currencies, $old->digest, $time,
                    new MerchantRevisionPolicy($time, null, $old->revisionRef, 'fixture-api-v2', 'fixture-hook',
                        'fixture-continuity', 'fixture-settlement', 'fixture-mandate'));
                $storage->appendMerchantRevision($context, $replacement, $next);
                $registry->appendLifecycle($context, $scope, $replacement->revisionRef, 'enable-new-route', 'enable_new', 'new',
                    $time, null, 'HTTP-11 owner change', 'fixture-approval');

                return new PlatformReceipt('execution.fixture_switch', ['applied' => true]);
            })->orThrow();
    }

    public function deliver(Application $app): RelayReport
    {
        // The P5 relay fixture authenticates the dispatch journal independently of the ledger database.
        $app->instance(CallerContext::class, new FixedCallerContext(new Caller(self::SELLER, 'test', self::INSTALLATION,
            'fixture-host', ['remote-billium'], [self::NAMESPACE])));
        $path = $this->directory.'/provider.journal';
        $refs = self::database($app)->table('billium_outbox_command')->orderBy('id')->pluck('command_ref');
        foreach ($refs as $ref) {
            OutcomeDeliveryTest::attest($app, $path, $ref);
        }
        $claimed = $delivered = $failed = $stale = 0;
        do {
            $report = OutcomeDeliveryTest::relay($app, $path);
            $claimed += $report->claimed;
            $delivered += $report->delivered;
            $failed += $report->failed;
            $stale += $report->stale;
        } while ($report->claimed > 0 && $claimed < count($refs) && $failed === 0);

        return new RelayReport($claimed, $delivered, $failed, $stale);
    }

    /** @return array<string,mixed> */
    public function snapshot(Application $app): array
    {
        $db = self::database($app, true);

        return $db->transaction(function () use ($db): array {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $tables = ['inbox_message', 'inbox_conflict', 'feed_partition', 'observed_payment', 'payment', 'allocation_receipt',
                'outbox_event', 'idempotency_receipt', 'money_commit_log', 'execution_operation', 'execution_attempt',
                'execution_provider_fact', 'execution_discrepancy', 'outbox_command'];
            $counts = [];
            foreach ($tables as $table) {
                if ($db->getSchemaBuilder()->hasTable('billium_'.$table)) {
                    $counts[$table] = $db->table('billium_'.$table)->count();
                }
            }
            $operations = $this->profile === 'execution' ? $db->table('billium_execution_operation')->orderBy('operation_ref')->orderBy('seller_ref')
                ->get(['operation_ref', 'root_operation_ref', 'seller_ref', 'kind', 'merchant_account_ref', 'merchant_revision_ref',
                    'intent_owner_namespace', 'requested_minor', 'state'])->all() : [];
            $attempts = $this->profile === 'execution' ? $db->table('billium_execution_attempt')->orderBy('operation_ref')
                ->get(['operation_ref', 'attempt_ref', 'provider_reference', 'request_digest', 'state'])->all() : [];
            $events = $db->table('billium_outbox_event')->orderBy('id')->get(['event_id', 'type as event_type'])->all();
            $inbox = $db->table('billium_inbox_message')->orderBy('source_authority')->orderBy('external_event_id')
                ->get(['source_authority', 'external_event_id', 'status'])->all();
            $receipts = $db->table('billium_idempotency_receipt')->orderBy('id')
                ->get(['id', 'seller_ref', 'command_kind', 'command_key', 'digest', 'outcome'])->all();
            $admissions = $db->table('billium_idempotency_receipt')->where('command_kind', 'payment.execute.v1')->orderBy('seller_ref')
                ->selectRaw("id, seller_ref, result->'body'->>'receiptRef' AS receipt_ref")->get()->all();
            $allocations = $db->table('billium_allocation_receipt')->orderBy('id')->get(['receipt_ref', 'kind'])->all();
            $modules = $db->table('billium_module_state')->orderBy('module_id')->pluck('state', 'module_id')->all();

            return ['counts' => $counts, 'operations' => $operations, 'attempts' => $attempts, 'events' => $events,
                'inbox' => $inbox, 'receipts' => $receipts, 'admissions' => $admissions, 'allocations' => $allocations, 'modules' => $modules];
        });
    }

    /** @return array<string,list<array<string,string|int|bool>>> */
    public function providerEvidence(): array
    {
        $events = ['calls' => [], 'lookups' => []];
        foreach (new DurableJournal($this->directory.'/provider.journal')->records() as $record) {
            if ($record instanceof JournalEvent && $record->kind === 'provider.call') {
                $events['calls'][] = $record->data;
            } elseif ($record instanceof JournalEvent && $record->kind === 'provider.lookup') {
                $events['lookups'][] = $record->data;
            }
        }

        return $events;
    }
}

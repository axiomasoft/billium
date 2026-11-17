<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Concurrency;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\AuthorizePayment;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
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
use Billium\Core\Tests\Support\Concurrency\WorkerApplication;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\ExecutionOriginResolver;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Contracts\PaymentDriver;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\DispatchRequest;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\OriginDecision;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Internal\ExecutionServiceProvider;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Execution\Tests\Feature\ExecutionSchemaTest;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Testkit\Drivers\DeterministicPaymentDriver;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DeterministicWriterHost;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessBarrier;
use Billium\Testkit\Fixtures\ProcessIdentity;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
#[Group('ACC-CON-02')]
final class AdmissionIdentityTest extends ExecutionTestCase
{
    public static function scope(): ExecutionScope
    {
        return ExecutionSchemaTest::scopeFixture();
    }

    /** @param array<string,mixed> $changes */
    public static function wire(array $changes = []): ExecutePayment|AuthorizePayment
    {
        $document = array_replace([
            'schema' => 'payment.execute.v1', 'commandKey' => self::key(1), 'installationRef' => self::INSTALLATION,
            'sellerRef' => 'seller-a', 'environment' => 'test', 'operationRef' => 'root-a', 'operationKind' => 'charge',
            'source' => ['context' => 'checkout', 'intentRef' => 'intent-a', 'orderRef' => 'order-a'],
            'executionProfile' => 'remote-billium', 'routingEpoch' => 1, 'merchantAccountRef' => 'account-a',
            'money' => ['amount' => '1.00', 'currency' => 'RUB'], 'bindingRevision' => 'merchant-a', 'requestedAt' => '2026-11-17T00:00:00Z',
        ], $changes);
        $envelope = (new Codec)->decode(json_encode($document, JSON_THROW_ON_ERROR));
        self::assertTrue($envelope instanceof ExecutePayment || $envelope instanceof AuthorizePayment);

        return $envelope;
    }

    public static function key(int $n): string
    {
        return sprintf('00000000-0000-4100-8800-%012d', $n);
    }

    public static function origin(?string $auth = null, string $context = 'checkout', string $operation = 'root-a', ?ExecutionScope $scope = null): OriginDecision
    {
        return new OriginDecision($scope ?? self::scope(), $operation, 'intent-a', $context, 'remote-billium', 1, $auth,
            'sha256:'.hash('sha256', 'trusted-order-a'), 'terms-v1', 'period-a');
    }

    public static function tempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'billium-dispatch-');
        if ($path === false) {
            throw new RuntimeException('Temporary external journal unavailable');
        }

        return $path;
    }

    public static function seedMerchant(Application $app, ?CapabilitySnapshot $cap = null, ?ExecutionScope $scope = null): void
    {
        $scope ??= self::scope();
        $cap ??= SimulatedCapabilities::snapshot();
        $template = ExecutionSchemaTest::merchantFixture();
        $merchant = new MerchantRevision('merchant-a', $scope, $cap->merchantAccountRef, 'simulated-provider', $cap->driverId, $cap->driverContractVersion,
            $cap->snapshotRef, $cap->digest, $template->credentialRef, $template->credentialVersionRef, $cap->currencies, $template->digest,
            $template->createdAt, $template->policy);
        $runner = $app->make(OperationRunner::class);
        $store = $app->make(ExecutionStore::class);
        $selection = new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, 'remote-billium');
        $runner->run(new OperationSpec('execution.fixture_admin', $scope->sellerRef, $scope->environment, FinancialMode::Administrative,
            ModuleOperationClass::HistoricalRead, ['execution', 'platform'], financialScope: $selection),
            static function (TransactionContext $ctx) use ($app, $store, $merchant, $cap, $scope): PlatformReceipt {
                $store->appendMerchantRevision($ctx, $merchant, $cap);
                $registry = $app->make(DatabaseMerchantRegistry::class);
                foreach (['new', 'lookup', 'refund', 'capture', 'void_authorization'] as $purpose) {
                    $registry->appendLifecycle($ctx, $scope, $merchant->revisionRef, 'enable-'.$purpose, $purpose === 'new' ? 'enable_new' : 'enable_recovery',
                        $purpose, new DateTimeImmutable('-1 day'), $purpose === 'new' ? null : new DateTimeImmutable('+2 days'), 'explicit fixture', 'approval-'.$purpose);
                    $registry->appendCredential($ctx, $merchant, 'cred-'.$purpose, $merchant->credentialVersionRef, $merchant->credentialRef, $purpose,
                        new DateTimeImmutable('-1 day'), new DateTimeImmutable('+2 days'), 'proof-'.$purpose, null);
                }

                return new PlatformReceipt('execution.seed', ['seeded' => true]);
            })->orThrow();
        $runner->run(new OperationSpec('execution.fixture_namespace', $scope->sellerRef, $scope->environment, FinancialMode::Active, ModuleOperationClass::NewIntent,
            ['execution', 'platform'], financialScope: $selection), static function (TransactionContext $ctx) use ($store, $scope): PlatformReceipt {
                $store->transitionNamespace($ctx, $scope, NamespaceState::Open, 0, 'externally-certified-history');

                return new PlatformReceipt('execution.seed', ['open' => true]);
            })->orThrow();
    }

    /** Installs the independently configured simulated host/PSP, never a default runtime authority.
     * @param  list<OriginDecision>|null  $origins
     * @param  null|Closure(string):void  $checkpoint
     */
    public static function host(Application $app, string $journal, ?array $origins = null, ?CapabilitySnapshot $cap = null, ResponseMode $mode = ResponseMode::Success,
        ?Closure $checkpoint = null, bool $qualified = true, ?FixtureClock $clock = null): DeterministicWriterHost
    {
        $origins ??= [self::origin()];
        $cap ??= SimulatedCapabilities::snapshot();
        $clock ??= new FixtureClock(new DateTimeImmutable);
        $outside = static fn (): int => $app->make(DatabaseManager::class)->connection()->transactionLevel();
        $app->instance(ExecutionOriginResolver::class, new readonly class($origins) implements ExecutionOriginResolver
        {
            /** @param list<OriginDecision> $origins */
            public function __construct(private array $origins) {}

            public function resolve(ExecutionScope $scope, string $operationRef, string $businessIntentRef): OriginDecision
            {
                foreach ($this->origins as $origin) {
                    if (get_object_vars($origin->scope) === get_object_vars($scope) && $origin->operationRef === $operationRef && $origin->businessIntentRef === $businessIntentRef) {
                        return $origin;
                    }
                }

                throw new ProblemException(ProblemCode::AuthDenied, 'Unknown fixture commercial origin');
            }
        });
        $app->instance(AdapterQualification::class, new readonly class($cap, $qualified) implements AdapterQualification
        {
            public function __construct(private CapabilitySnapshot $approved, private bool $qualified) {}

            public function verify(MerchantRevision $revision, CapabilitySnapshot $snapshot, EffectKind $kind): void
            {
                if (! $this->qualified || ! in_array($revision->scope->environment, ['test', 'fixture-second-environment'], true) || ExecutionCodec::encode($snapshot) !== ExecutionCodec::encode($this->approved) || ! in_array($kind, $snapshot->supportedEffects, true)) {
                    throw new ProblemException(ProblemCode::CapabilityUnsupported, 'No simulated host qualification for this account/effect');
                }
            }
        });
        $driver = new DeterministicPaymentDriver(new DurableJournal($journal), $clock, $cap, $outside, $mode, $checkpoint);
        $app->instance(PaymentDrivers::class, new readonly class($driver, $cap) implements PaymentDrivers
        {
            public function __construct(private PaymentDriver $driver, private CapabilitySnapshot $snapshot) {}

            public function resolve(string $driverId, string $contractVersion): PaymentDriver
            {
                if ($driverId !== $this->snapshot->driverId || $contractVersion !== $this->driver->contractVersion()) {
                    throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Unknown fixture provider contract');
                }

                return $this->driver;
            }

            public function capabilities(MerchantRevision $merchant): CapabilitySnapshot
            {
                return $this->snapshot;
            }
        });
        $app->instance(MerchantCredentials::class, new readonly class implements MerchantCredentials
        {
            public function resolve(MerchantRevision $revision, string $purpose): ResolvedCredentials
            {
                return new ResolvedCredentials($revision->merchantAccountRef, $revision->credentialVersionRef, $revision->credentialRef, $purpose,
                    new DateTimeImmutable('+30 minutes'), 'proof-'.$purpose);
            }
        });
        $writer = new DeterministicWriterHost(new DurableJournal($journal.'.host'), $clock, $outside);
        $app->instance(WriterAdmission::class, $writer);
        $app->instance(DispatchEgress::class, $writer);
        $writer->openSession(new DispatchRequest(self::scope(), 'fixture', 'fixture', 'fixture', EffectKind::Charge, self::wire()->money, 'unused', 'unused',
            'owner-1', 1, 1, 1, 1, 1, 'billium.psp_dispatch.v1'));

        return $writer;
    }

    public static function workerApp(int $index, ?ExecutionScope $scope = null): Application
    {
        $scope ??= self::scope();
        $app = WorkerApplication::create();
        $app->register(ExecutionServiceProvider::class);
        $app['config']->set('billium.modules.enabled', ['platform', 'ledger', 'execution']);
        $app->instance(CallerContext::class, new FixedCallerContext(new Caller($scope->sellerRef, $scope->environment, $scope->installationRef, 'process-'.$index,
            ['remote-billium'], [$scope->intentOwnerNamespace])));
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token($scope->installationRef, processRef: 'worker-'.$index)));
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);

        return $app;
    }

    public static function process(int $i, string $journal, string $race): void
    {
        $scope = $i % 2 === 0 ? self::scope() : self::raceScope($race);
        $app = self::workerApp($i, $scope);
        $account = $scope->sellerRef === 'seller-b' ? 'account-b' : 'account-a';
        self::host($app, $journal, [self::origin(scope: $scope), self::origin(context: $race === 'context' ? 'support' : 'checkout', operation: 'root-b', scope: $scope)],
            SimulatedCapabilities::snapshot($account));
        $db = $app->make(DatabaseManager::class)->connection();
        $backend = $db->scalar('SELECT pg_backend_pid()');
        $control = new DurableJournal($journal.'.control');
        $barrier = new ProcessBarrier($control);
        $barrier->arrive('ready');
        $barrier->await('release', 1, 45_000);
        $changes = match ($race) {
            'keys' => ['commandKey' => self::key($i + 1)],
            'changed' => ['money' => ['amount' => $i % 2 === 0 ? '1.00' : '2.00', 'currency' => 'RUB']],
            'context' => $i % 2 === 0 ? [] : ['source' => ['context' => 'support', 'intentRef' => 'intent-a'], 'operationRef' => 'root-b'],
            default => [],
        };
        if ($race === 'namespace' && $i % 2 === 1) {
            $changes['operationRef'] = 'root-b';
        }
        $changes += ['sellerRef' => $scope->sellerRef, 'installationRef' => $scope->installationRef, 'environment' => $scope->environment, 'merchantAccountRef' => $account];
        try {
            $outcome = $app->make(RootPaymentCommands::class)->admit($scope, self::wire($changes))->orThrow();
            $record = ['result' => 'accepted', 'receipt' => json_encode($outcome->body, JSON_THROW_ON_ERROR), 'id' => $outcome->receiptId ?? 0];
        } catch (ProblemException $exception) {
            $record = ['result' => $exception->problem->value, 'receipt' => '', 'id' => 0];
        }
        $control->append(new JournalEvent('result', $record + ['index' => $i, 'backend' => self::integer($backend)], ProcessIdentity::current()));
    }

    /** @return list<array{string}> */
    public static function races(): array
    {
        return [['same'], ['keys'], ['changed'], ['context'], ['namespace'], ['environment'], ['seller'], ['reinstall']];
    }

    private static function raceScope(string $race): ExecutionScope
    {
        return match ($race) {
            'namespace' => new ExecutionScope(self::INSTALLATION, 'seller-a', 'test', 'source-b'),
            'environment' => new ExecutionScope(self::INSTALLATION, 'seller-a', 'fixture-second-environment', 'source-a'),
            'seller' => new ExecutionScope(self::INSTALLATION, 'seller-b', 'test', 'source-a'),
            'reinstall' => new ExecutionScope('fixture-second-installation', 'seller-a', 'test', 'source-a'),
            default => self::scope(),
        };
    }

    private function prepareOtherScope(string $race): void
    {
        $scope = self::raceScope($race);
        if (get_object_vars($scope) === get_object_vars(self::scope())) {
            return;
        }
        $app = $this->application();
        $original = $this->callers->current();
        $this->callers->set(new Caller($scope->sellerRef, $scope->environment, $scope->installationRef, 'scope-fixture', ['remote-billium'], [$scope->intentOwnerNamespace]));
        try {
            if ($race === 'reinstall') {
                $this->installation()->init($scope->installationRef);
                $this->authority->issueTransition('open', 'reinstall-open', ['owner-1', 1, 1, 1, 'reinstall-history']);
                $this->installation()->openSession('reinstall-open', 'owner-1', 1, 1, 1, 'reinstall-history');
                $this->authority->issueTransition('activate', 'reinstall-active', [1, 'reinstall-certificate', 'reinstall-admission', 'reinstall-evidence']);
                $this->installation()->activateSession('reinstall-active', 1, 'reinstall-certificate', 'reinstall-admission', 'reinstall-evidence');
                $this->tokens->set(FixedSessionAdmission::token($scope->installationRef));
                SimulatedFinancialAuthorityEvidence::admitCurrent($app);
            }
            if ($race === 'namespace') {
                $this->runner()->run($this->spec(kind: 'execution.second_namespace', modules: ['platform', 'execution'],
                    financialScope: new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, 'source-b', 'remote-billium')),
                    static function (TransactionContext $ctx) use ($scope, $app): PlatformReceipt {
                        $app->make(ExecutionStore::class)->transitionNamespace($ctx, $scope, NamespaceState::Open, 0, 'second-owner-history');

                        return self::receipt();
                    });
            } else {
                self::seedMerchant($app, SimulatedCapabilities::snapshot($race === 'seller' ? 'account-b' : 'account-a'), $scope);
            }
        } finally {
            $this->callers->set($original);
        }
    }

    #[DataProvider('races')]
    public function test_twenty_independent_database_processes_preserve_one_effect_per_commercial_identity(string $race): void
    {
        self::seedMerchant($this->application());
        $this->prepareOtherScope($race);
        $path = self::tempPath();
        $control = new DurableJournal($path.'.control');
        $barrier = new ProcessBarrier($control);
        $workers = [];
        try {
            for ($i = 0; $i < 20; $i++) {
                $workers[] = new ChildProcess([PHP_BINARY, '-r',
                    'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Concurrency\\AdmissionIdentityTest::process((int)$argv[1], $argv[2], $argv[3]);',
                    (string) $i, $path, $race]);
            }
            $barrier->await('ready', 20, 45_000);
            $barrier->arrive('release');
            foreach ($workers as $child) {
                self::assertSame(0, $child->wait());
            }
            $results = array_values(array_filter($control->records(), static fn (object $entry): bool => $entry instanceof JournalEvent && $entry->kind === 'result'));
            self::assertCount(20, $results);
            self::assertCount(20, array_unique(array_map(static fn (JournalEvent $entry): int => $entry->pid, $results)));
            self::assertCount(20, array_unique(array_map(static fn (JournalEvent $entry): mixed => $entry->data['backend'], $results)));
            $accepted = array_filter($results, static fn (JournalEvent $entry): bool => $entry->data['result'] === 'accepted');
            self::assertCount(in_array($race, ['changed', 'seller', 'reinstall'], true) ? 10 : 20, $accepted, $race);
            if (in_array($race, ['changed', 'seller', 'reinstall'], true)) {
                self::assertCount(10, array_filter($results, static fn (JournalEvent $entry): bool => $entry->data['result'] === ProblemCode::IdempotencyConflict->value));
            }
            if ($race === 'same') {
                self::assertCount(1, array_unique(array_map(static fn (JournalEvent $entry): string => json_encode($entry->data['receipt'], JSON_THROW_ON_ERROR), $accepted)));
                self::assertCount(1, array_unique(array_map(static fn (JournalEvent $entry): mixed => $entry->data['id'], $accepted)));
            }
            foreach (['business_binding', 'operation', 'attempt', 'dispatch_work'] as $table) {
                self::assertSame(in_array($race, ['context', 'namespace', 'environment'], true) ? 2 : 1, $this->rows('billium_execution_'.$table));
            }
            self::assertSame(0, $this->rows('billium_execution_reservation'));
            self::assertSame([], new DurableJournal($path)->records());
        } finally {
            foreach ([$path, $path.'.host', $path.'.control'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}

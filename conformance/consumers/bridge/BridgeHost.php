<?php

declare(strict_types=1);

namespace Billium\Conformance\ConsumerBridge;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Contracts\Runtime\ScopedCallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\ActivationCertificate;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialAdmissionToken;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Data\Runtime\NamespaceDisposition;
use Billium\Core\Data\Runtime\OwnerSessionIdentity;
use Billium\Core\Data\Runtime\RecoveryCoverage;
use Billium\Core\Data\Runtime\RetirementProof;
use Billium\Core\Data\Runtime\VerifiedProcessAdmission;
use Billium\Core\Data\Runtime\VerifiedSessionAuthorization;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\ExecutionOriginResolver;
use Billium\Execution\Contracts\ExecutionReadiness;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\DispatchRequest;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\MerchantRevisionPolicy;
use Billium\Execution\Data\OriginDecision;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\NamespaceState;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\Obligations\ObligationDuePolicy;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Platform\Contracts\Inbox\ObservationBootstrapAuthenticator;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Platform\Data\Inbox\BootstrapPartition;
use Billium\Platform\Data\Inbox\VerifiedObservationBootstrap;
use Billium\Platform\Data\Installation\PlatformReceipt;
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
use Billium\Testkit\Drivers\FaultInjectingPaymentDrivers;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Drivers\SimulatedAbsenceVerifier;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\DeterministicWriterHost;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use BilliumMinimalHost\Host;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\PackageManifest;
use LogicException;

/**
 * Product-agnostic consumer/bridge loopback stand using only public Billium APIs/SPIs and real PostgreSQL.
 */
final class BridgeHost implements Authenticator, AuthorizationVerdict, FinancialAuthorityEvidence, FinancialSessionAdmission, InstallationContextResolver, ScopedCallerContext, SellerEligibility
{
    public const string INSTALLATION = 'inst-test';

    public const string SELLER = 'seller-a';

    public const string OTHER_SELLER = 'seller-b';

    public const string NAMESPACE = 'source-a';

    public const string OTHER_NAMESPACE = 'source-b';

    public const string RELEASE_DOSSIER = 'simulated:ct-release-v1';

    public const string RETENTION_DOSSIER = 'simulated:ct-retention-indefinite-v1';

    private Caller $caller;

    /** @var array<string, VerifiedProcessAdmission> */
    private array $processes = [];

    /** @var array<string, VerifiedSessionAuthorization> */
    private array $transitions = [];

    /** @var array<string, array{owner: OwnerSessionIdentity, epoch: int, retirement: RetirementProof}> */
    private array $owners = [];

    private ?FinancialAdmissionToken $currentToken = null;

    private string $authorityKey = '';

    private ?FaultInjectingPaymentDrivers $paymentDrivers = null;

    private ?DeterministicWriterHost $writerHost = null;

    public function __construct(
        public readonly string $directory,
        public readonly string $standProfile,
    ) {
        $this->caller = new Caller(
            self::SELLER,
            'test',
            self::INSTALLATION,
            'bridge-host',
            ['remote-billium', 'standalone-billium'],
            [self::NAMESPACE],
        );
    }

    public function application(string $database, bool $writer = true): Application
    {
        $app = new Host('unused')->application(
            $this->directory,
            $database,
            $this->composition(),
            $this->modules(),
            [BilliumCoreServiceProvider::class, BilliumServerServiceProvider::class],
        );
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
            $config->set('database.connections.billium-routing', $config->get('database.connections.server_owner'));
            $config->set('billium.installation_ref', self::INSTALLATION);
            $config->set('billium.outbox.maintenance_connection', 'server_owner');
            $config->set('billium.outbox.retention_floor_seconds', 0);
            if ($this->standProfile === 'execution') {
                $config->set('billium.modules.composition', 'remote-billium');
                $config->set('billium.execution', [
                    'release_evidence_ref' => self::RELEASE_DOSSIER,
                    'retention_policy_ref' => self::RETENTION_DOSSIER,
                    'outgoing_enabled' => true,
                ]);
            }
        });

        foreach ([
            Authenticator::class,
            AuthorizationVerdict::class,
            InstallationContextResolver::class,
            SellerEligibility::class,
            CallerContext::class,
            ScopedCallerContext::class,
            FinancialAuthorityEvidence::class,
            FinancialSessionAdmission::class,
        ] as $port) {
            $app->instance($port, $this);
        }
        $app->instance(ObservationBootstrapAuthenticator::class, new readonly class($this) implements ObservationBootstrapAuthenticator
        {
            public function __construct(private BridgeHost $host) {}

            public function authenticate(BootstrapPartition $snapshot): VerifiedObservationBootstrap
            {
                return $this->host->authenticateBootstrap($snapshot);
            }
        });
        $app->instance(SellerContextResolver::class, new readonly class($this) implements SellerContextResolver
        {
            public function __construct(private BridgeHost $host) {}

            public function resolve(AuthenticatedPrincipal $principal, string $routeSeller): ExecutionContext
            {
                return $this->host->sellerContext($principal, $routeSeller);
            }
        });

        if ($this->standProfile === 'execution') {
            $app->bind(ExecutionReadiness::class, static fn (Application $app): ExecutionReadiness => new readonly class($app) implements ExecutionReadiness
            {
                public function __construct(private Application $app) {}

                public function assertConfigured(string $releaseEvidenceRef, string $retentionPolicyRef): void
                {
                    $db = BridgeHost::database($this->app);
                    if ($db->transactionLevel() !== 0 || $releaseEvidenceRef !== BridgeHost::RELEASE_DOSSIER || $retentionPolicyRef !== BridgeHost::RETENTION_DOSSIER) {
                        throw new ProblemException(ProblemCode::RecoveryRequired, 'Simulated readiness dossier mismatch');
                    }
                }

                public function assertReady(FrozenBinding $binding, EffectKind $kind, Money $amount, string $releaseEvidenceRef, string $retentionPolicyRef): void
                {
                    $db = BridgeHost::database($this->app);
                    if ($db->transactionLevel() !== 0) {
                        throw new LogicException('ExecutionReadiness called inside DB transaction');
                    }
                    if (
                        $releaseEvidenceRef !== BridgeHost::RELEASE_DOSSIER
                        || $retentionPolicyRef !== BridgeHost::RETENTION_DOSSIER
                        || ! in_array($binding->scope->environment, ['test', 'testing'], true)
                        || ! in_array($binding->executionProfile, ['remote-billium', 'standalone-billium'], true)
                        || $binding->origin === null
                        || $amount->minor <= 0
                    ) {
                        throw new ProblemException(ProblemCode::RecoveryRequired, 'Simulated readiness refused request');
                    }
                }
            });
        }

        $app->make(HttpKernel::class)->bootstrap();

        if ($this->standProfile === 'execution') {
            $this->bindExecutionPorts($app, ResponseMode::Success);
            if (! $writer) {
                $app->forgetInstance(WriterAdmission::class);
            }
        }

        return $app;
    }

    /** @return list<string> */
    public function modules(): array
    {
        return $this->standProfile === 'execution'
            ? ['platform', 'ledger', 'execution']
            : ['platform', 'ledger'];
    }

    public function composition(): string
    {
        return $this->standProfile === 'execution' ? 'remote-billium' : 'ledger-only';
    }

    public static function database(Application $app, bool $owner = false): Connection
    {
        return $app->make(DatabaseManager::class)->connection($owner ? 'server_owner' : 'server_runtime');
    }

    public static function scope(string $seller = self::SELLER, string $namespace = self::NAMESPACE): ExecutionScope
    {
        return new ExecutionScope(self::INSTALLATION, $seller, 'test', $namespace);
    }

    public function current(): Caller
    {
        return $this->caller;
    }

    public function setCaller(Caller $caller): void
    {
        $this->caller = $caller;
    }

    public function run(Caller $caller, Closure $callback): mixed
    {
        $previous = $this->caller;
        $this->caller = $caller;
        try {
            return $callback();
        } finally {
            $this->caller = $previous;
        }
    }

    public function currentToken(string $installationRef): ?FinancialAdmissionToken
    {
        return $this->currentToken?->installationRef === $installationRef ? $this->currentToken : null;
    }

    public function process(string $installationRef, string $processRef, string $nonce): ?VerifiedProcessAdmission
    {
        return $this->processes[$installationRef.'|'.$processRef.'|'.$nonce] ?? null;
    }

    public function transition(string $installationRef, string $transitionRef): ?VerifiedSessionAuthorization
    {
        return $this->transitions[$installationRef.'|'.$transitionRef] ?? null;
    }

    public function authenticateBootstrap(BootstrapPartition $snapshot): VerifiedObservationBootstrap
    {
        $proof = 'sha256:'.hash_hmac('sha256', $snapshot->snapshotDigest, $this->authorityKey !== '' ? $this->authorityKey : 'bootstrap-fixture-key');

        return new VerifiedObservationBootstrap(
            new FinancialScope(self::INSTALLATION, $snapshot->sellerRef, $snapshot->environment, null, $snapshot->sourceAuthority),
            $snapshot->bootstrapRef,
            $snapshot->snapshotDigest,
            $snapshot->sourceRetentionLeaseRef,
            $proof,
            new DateTimeImmutable('+30 minutes'),
        );
    }

    public function install(Application $app): void
    {
        Host::migrate($app, $this->modules());
        $installation = $app->make(Installation::class);
        $installation->init(self::INSTALLATION);
        $this->startSession($app);
        if ($app->make(ConsoleKernel::class)->call('billium:event-contracts:register', ['--database' => 'server_owner']) !== 0) {
            throw new LogicException('Failed to register packaged module event contracts');
        }
        if ($this->standProfile === 'execution') {
            $this->seedMerchant($app, self::scope(), 'merchant-a', 'account-a');
        }
        $this->seedObligations($app, self::SELLER, [
            ['obligation-a', '10.00', true, 'intent-a'],
            ['obligation-100', '100.00', true, 'intent-100'],
        ]);
    }

    public function admitProcess(Application $app, ?array $modes = null): void
    {
        if (is_file($this->directory.'/attachment-quarantined') || ! is_file($this->directory.'/authority.json')) {
            $this->currentToken = null;

            return;
        }
        $stored = json_decode((string) file_get_contents($this->directory.'/authority.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->authorityKey = (string) $stored['key'];
        [$this->transitions, $this->owners] = unserialize((string) base64_decode((string) $stored['payload'], true));
        $token = new FinancialAdmissionToken(
            self::INSTALLATION, 'owner-1', 1, 1, 1,
            $modes ?? [FinancialMode::Active, FinancialMode::Recovery],
            'bridge-'.getmypid(), new DateTimeImmutable('+1 hour'), bin2hex(random_bytes(16)),
        );
        $this->currentToken = $token;
        $scopes = [new FinancialScope(self::INSTALLATION, null, null, null)];
        $sig = hash_hmac('sha256', serialize([$token, $scopes]), $this->authorityKey);
        $this->processes[$token->installationRef.'|'.$token->processRef.'|'.$token->nonce] = new VerifiedProcessAdmission(
            $token, 'billium.financial_session.v1', $scopes, 'simulated-bridge-host', 'sha256:'.$sig, new DateTimeImmutable,
        );
    }

    public function bindExecutionPorts(Application $app, ResponseMode $mode = ResponseMode::Success): FaultInjectingPaymentDrivers
    {
        $clock = new FixtureClock(new DateTimeImmutable);
        $outside = static fn (): int => self::database($app)->transactionLevel();
        $drivers = new FaultInjectingPaymentDrivers(new DurableJournal($this->directory.'/provider.journal'), $clock, $outside, $mode);
        $drivers->registerAccount('account-a');
        $drivers->registerAccount('account-b');
        $drivers->registerAccount('replacement-account');
        $this->paymentDrivers = $drivers;

        $app->instance(PaymentDrivers::class, $drivers);
        $app->instance(AdapterQualification::class, $drivers);
        $app->instance(ExecutionOriginResolver::class, new readonly class($app, $this->directory) implements ExecutionOriginResolver
        {
            public function __construct(private Application $app, private string $directory) {}

            public function resolve(ExecutionScope $scope, string $operationRef, string $businessIntentRef): OriginDecision
            {
                if (is_file($this->directory.'/deny-origin-'.$operationRef)) {
                    throw new ProblemException(ProblemCode::AuthDenied, 'Unrecognized commercial origin');
                }
                $epochRow = BridgeHost::database($this->app, true)->table('billium_intent_binding')
                    ->where('operation_ref', $operationRef)->value('routing_epoch');
                $epoch = is_int($epochRow) ? $epochRow : (is_numeric($epochRow) ? (int) $epochRow : 1);

                return new OriginDecision(
                    $scope, $operationRef, $businessIntentRef, 'checkout', 'remote-billium', $epoch, null,
                    'sha256:'.hash('sha256', 'origin:'.$scope->sellerRef.':'.$businessIntentRef), 'terms-v1', 'period-1',
                );
            }
        });
        $app->instance(MerchantCredentials::class, new readonly class($this->directory) implements MerchantCredentials
        {
            public function __construct(private string $directory) {}

            public function resolve(MerchantRevision $revision, string $purpose): ResolvedCredentials
            {
                $rotFile = $this->directory.'/cred-rotated-'.$revision->merchantAccountRef.'-'.$purpose.'.json';
                if (is_file($rotFile)) {
                    /** @var array{version: string, locator: string, proof: string} $rot */
                    $rot = json_decode((string) file_get_contents($rotFile), true, flags: JSON_THROW_ON_ERROR);

                    return new ResolvedCredentials(
                        $revision->merchantAccountRef, $rot['version'], new SecretRef($rot['locator']),
                        $purpose, new DateTimeImmutable('+30 minutes'), $rot['proof'],
                    );
                }

                return new ResolvedCredentials(
                    $revision->merchantAccountRef, $revision->credentialVersionRef, $revision->credentialRef,
                    $purpose, new DateTimeImmutable('+30 minutes'), 'proof-'.$purpose,
                );
            }
        });

        $writer = new DeterministicWriterHost(new DurableJournal($this->directory.'/provider.journal.host'), $clock, $outside);
        $this->writerHost = $writer;
        $app->instance(WriterAdmission::class, $writer);
        $app->instance(DispatchEgress::class, $writer);
        if (! is_file($this->directory.'/host-session-opened')) {
            foreach ([self::scope(), self::scope(self::OTHER_SELLER, self::OTHER_NAMESPACE)] as $s) {
                $writer->openSession(self::dispatchSessionRequest($s));
            }
            touch($this->directory.'/host-session-opened');
        }

        $app->instance(ProviderAbsenceVerifier::class, new SimulatedAbsenceVerifier(
            new DurableJournal($this->directory.'/provider.journal'),
            new DurableJournal($this->directory.'/provider.journal.host'),
            new DurableJournal($this->directory.'/absence.journal'),
            $clock, SimulatedCapabilities::snapshot(), $outside,
        ));

        return $drivers;
    }

    public static function dispatchSessionRequest(ExecutionScope $scope): DispatchRequest
    {
        return new DispatchRequest(
            $scope, 'session-init', 'session-init', 'session-init', EffectKind::Charge, Money::parse('1.00', 'RUB'),
            'unused', 'unused', 'owner-1', 1, 1, 1, 1, 1, 'billium.psp_dispatch.v1',
        );
    }

    public function drivers(): FaultInjectingPaymentDrivers
    {
        return $this->paymentDrivers ?? throw new LogicException('Execution payment drivers are not bound');
    }

    public function writerHost(): DeterministicWriterHost
    {
        return $this->writerHost ?? throw new LogicException('Writer host is not bound');
    }

    public function seedMerchant(
        Application $app,
        ExecutionScope $scope,
        string $revisionRef,
        string $accountRef,
        bool $openNamespace = true,
    ): CapabilitySnapshot {
        $cap = $this->drivers()->registerAccount($accountRef);
        $now = new DateTimeImmutable('-5 minutes');
        $merchant = new MerchantRevision(
            $revisionRef,
            $scope,
            $accountRef,
            'simulated-provider',
            $cap->driverId,
            $cap->driverContractVersion,
            $cap->snapshotRef,
            $cap->digest,
            new SecretRef('secret://fixture/'.$accountRef),
            'cred-v1-'.$accountRef,
            $cap->currencies,
            'sha256:'.hash('sha256', 'merchant:'.$revisionRef.':'.$accountRef),
            $now,
            new MerchantRevisionPolicy($now, null, null, 'api-v1', 'hook-v1', 'continuity-v1', 'settlement-v1', 'mandate-v1'),
        );
        $prior = $this->caller;
        $this->caller = new Caller($scope->sellerRef, $scope->environment, $scope->installationRef, 'bridge-seed', ['remote-billium'], [$scope->intentOwnerNamespace]);
        try {
            $runner = $app->make(OperationRunner::class);
            $store = $app->make(ExecutionStore::class);
            $finScope = new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, 'remote-billium');
            $runner->run(
                new OperationSpec('execution.bridge_seed_merchant', $scope->sellerRef, $scope->environment, FinancialMode::Administrative, ModuleOperationClass::HistoricalRead, ['execution', 'platform'], financialScope: $finScope),
                static function (TransactionContext $ctx) use ($store, $merchant, $cap): PlatformReceipt {
                    $store->appendMerchantRevision($ctx, $merchant, $cap);

                    return new PlatformReceipt('execution.seed', ['revision' => $merchant->revisionRef]);
                },
            )->orThrow();

            $ownerDb = self::database($app, true);
            $validFrom = (new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s.uP');
            $validUntil = (new DateTimeImmutable('+2 days'))->format('Y-m-d H:i:s.uP');
            foreach (['new', 'lookup', 'refund', 'capture', 'void_authorization'] as $purpose) {
                $ownerDb->table('billium_execution_merchant_lifecycle_event')->insert([
                    'installation_ref' => $scope->installationRef,
                    'seller_ref' => $scope->sellerRef,
                    'environment' => $scope->environment,
                    'event_ref' => $revisionRef.'-enable-'.$purpose,
                    'revision_ref' => $revisionRef,
                    'action' => $purpose === 'new' ? 'enable_new' : 'enable_recovery',
                    'purpose' => $purpose,
                    'effective_at' => $validFrom,
                    'retention_until' => $purpose === 'new' ? null : $validUntil,
                    'actor_ref' => 'bridge-seed',
                    'reason' => 'fixture-bootstrap',
                    'evidence_ref' => 'approval-'.$revisionRef.'-'.$purpose,
                ]);
                $ownerDb->table('billium_execution_merchant_credential_revision')->insert([
                    'installation_ref' => $scope->installationRef,
                    'seller_ref' => $scope->sellerRef,
                    'environment' => $scope->environment,
                    'credential_revision_ref' => $revisionRef.'-cred-'.$purpose,
                    'revision_ref' => $revisionRef,
                    'merchant_account_ref' => $accountRef,
                    'credential_ref' => $merchant->credentialRef->locator,
                    'credential_version_ref' => $merchant->credentialVersionRef,
                    'purpose' => $purpose,
                    'valid_from' => $validFrom,
                    'valid_until' => $validUntil,
                    'identity_continuity_evidence_ref' => 'proof-'.$purpose,
                    'predecessor_ref' => null,
                ]);
            }

            if ($openNamespace) {
                $runner->run(
                    new OperationSpec('execution.bridge_open_ns', $scope->sellerRef, $scope->environment, FinancialMode::Active, ModuleOperationClass::NewIntent, ['execution', 'platform'], financialScope: $finScope),
                    static function (TransactionContext $ctx) use ($store, $scope): PlatformReceipt {
                        $store->transitionNamespace($ctx, $scope, NamespaceState::Open, 0, 'certified-empty-namespace');

                        return new PlatformReceipt('execution.namespace', ['open' => true]);
                    },
                )->orThrow();
            }
        } finally {
            $this->caller = $prior;
        }

        return $cap;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: bool, 3: string}>  $items
     */
    public function seedObligations(Application $app, string $seller, array $items): void
    {
        $prior = $this->caller;
        $this->caller = new Caller($seller, 'test', self::INSTALLATION, 'bridge-ledger-seed', ['remote-billium', 'standalone-billium'], [$seller === self::SELLER ? self::NAMESPACE : self::OTHER_NAMESPACE]);
        try {
            $ledger = $app->make(Ledger::class);
            $account = new BillingAccountScope($seller, 'test', 'billing-main');
            $digest = 'sha256:'.hash('sha256', 'seed-policy:'.$seller);
            $due = new ObligationDuePolicy(new DateTimeImmutable('2026-10-01T00:00:00Z'), 'fixture/1', 'fixture-policy', $digest);
            foreach ($items as [$ref, $amount, $withIntent, $intentRef]) {
                $ledger->recordObligation(new RecordObligation(
                    'seed-ob-'.$seller.'-'.$ref,
                    $account,
                    $ref,
                    'basis-'.$seller.'-'.$ref,
                    'customer-1',
                    'component-main',
                    Money::parse($amount, 'RUB'),
                    $digest,
                    duePolicy: $due,
                ))->orThrow();
                if ($withIntent) {
                    $ledger->declareSettlementIntent(new DeclareSettlementIntent(
                        'seed-intent-'.$seller.'-'.$intentRef,
                        $account,
                        $intentRef,
                        'RUB',
                        [$ref],
                    ))->orThrow();
                }
            }
        } finally {
            $this->caller = $prior;
        }
    }

    public function authenticate(array $headers, RequestMeta $requestMeta): AuthenticatedPrincipal
    {
        $header = $headers['authorization'][0] ?? '';
        foreach (['owner', 'other-seller', 'revoked', 'external-source', 'remote-source'] as $actor) {
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

    public function sellerContext(AuthenticatedPrincipal $principal, string $seller): ExecutionContext
    {
        $expectedSeller = $principal->principalRef === 'other-seller' ? self::OTHER_SELLER : self::SELLER;
        if ($seller !== $expectedSeller) {
            throw new ProblemException(ProblemCode::AuthDenied);
        }
        $sources = match ($principal->principalRef) {
            'remote-source' => ['remote-billium'],
            default => ['standalone-billium', 'remote-billium'],
        };
        $grants = array_map(static fn (string $src): SourceAuthorityGrant => new SourceAuthorityGrant(
            'payment.observation',
            $src,
            self::INSTALLATION,
            'test',
            $seller,
            'L1',
            ['main', 'shared', 'gap', 'ct04-part', 'ct05-part', 'ct07-part', 'ct08-part', 'ct16-part'],
        ), $sources);
        if ($principal->principalRef === 'external-source') {
            $grants[] = new SourceAuthorityGrant(
                'payment.observation',
                'standalone-billium',
                self::INSTALLATION,
                'test',
                $seller,
                'L2',
                ['ct08-part', 'ct08-part-l2'],
            );
        }

        return new ExecutionContext(
            self::INSTALLATION,
            'test',
            $seller,
            $principal->principalRef,
            sourceAuthorities: $grants,
            intentOwnerNamespace: $seller === self::SELLER ? self::NAMESPACE : self::OTHER_NAMESPACE,
            executionAuthorities: ['remote-billium'],
        );
    }

    public function admission(SellerScope $scope): ?SellerAdmission
    {
        return new SellerAdmission($scope, in_array($scope->sellerRef, [self::SELLER, self::OTHER_SELLER], true));
    }

    public function authorize(AuthenticatedPrincipal $principal, string $action, ResourceScope $resourceScope, string $payloadDigest): Verdict
    {
        if ($principal->principalRef === 'revoked') {
            return Verdict::Deny;
        }

        return Verdict::Allow;
    }

    private function startSession(Application $app): void
    {
        $this->authorityKey = bin2hex(random_bytes(32));
        $installation = $app->make(Installation::class);
        $this->issueSessionTransition('open', 'open-bridge', ['owner-1', 1, 1, 1, 'evidence:open-bridge']);
        $installation->openSession('open-bridge', 'owner-1', 1, 1, 1, 'evidence:open-bridge');
        $this->issueSessionTransition('activate', 'activate-bridge', [1, 'certificate:bridge', 'admission:bridge', 'evidence:activate-bridge']);
        $installation->activateSession('activate-bridge', 1, 'certificate:bridge', 'admission:bridge', 'evidence:activate-bridge');
        $payload = base64_encode(serialize([$this->transitions, $this->owners]));
        file_put_contents($this->directory.'/authority.json', json_encode([
            'key' => $this->authorityKey,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($this->directory.'/authority.json', 0600);
        $this->admitProcess($app);
    }

    /** @param list<int|string> $args */
    private function issueSessionTransition(string $action, string $ref, array $args): void
    {
        $c = $this->caller;
        $digest = 'sha256:'.hash('sha256', json_encode([
            $action,
            $c->installationRef,
            $c->actorRef,
            $c->sellerRef,
            $c->environment,
            $c->sourceAuthorities,
            ...$args,
            ['intentOwnerNamespaces.v1' => $c->intentOwnerNamespaces],
        ], JSON_THROW_ON_ERROR));
        $prior = $this->owners[$c->installationRef] ?? null;
        if ($action === 'open') {
            $owner = new OwnerSessionIdentity($c->installationRef, (string) $args[0], (int) $args[1], (int) $args[2], (int) $args[3]);
            $retirement = new RetirementProof(
                'sim-retire:'.$ref,
                $prior['owner'] ?? $owner,
                $this->hmac('producers-stopped'),
                $this->hmac('workers-terminated'),
                $this->hmac('egress-revoked'),
                $this->hmac('inflight-empty'),
                'simulated-bridge-host',
                new DateTimeImmutable,
                $this->hmac($ref.'|retirement'),
            );
            $this->owners[$c->installationRef] = ['owner' => $owner, 'epoch' => ($prior['epoch'] ?? 0) + 1, 'retirement' => $retirement];
            $evidence = (string) $args[4];
        } else {
            $owner = $prior['owner'] ?? throw new LogicException('Owner missing before activate');
            $retirement = $prior['retirement'];
            $evidence = (string) $args[3];
        }
        $expires = new DateTimeImmutable('+1 hour');
        $nonce = bin2hex(random_bytes(16));
        $certificate = null;
        if ($action === 'activate') {
            $coverage = new RecoveryCoverage(
                'simulated-backup',
                new DateTimeImmutable('-1 day'),
                [],
                $this->hmac('dispatch-coverage'),
                $this->hmac('source-journal'),
                $this->hmac('tombstones'),
                ['projection-check'],
                ['downstream-check'],
                [],
                [new NamespaceDisposition(new FinancialScope($c->installationRef, null, null, null), 'active', $this->hmac('ns-coverage'))],
            );
            $certificate = new ActivationCertificate(
                (string) $args[1],
                $owner,
                (int) $args[0],
                $retirement->signatureDigest,
                $coverage,
                $c->actorRef,
                'activate',
                'simulated activation',
                $expires,
                $nonce,
                $this->hmac($ref.'|certificate'),
            );
        }
        $this->transitions[$c->installationRef.'|'.$ref] = new VerifiedSessionAuthorization(
            $ref,
            $action,
            $owner,
            $prior['epoch'] ?? null,
            $digest,
            $evidence,
            $certificate,
            $retirement,
            $action === 'activate' ? (string) $args[2] : 'sim-adm:'.$ref,
            'simulated-bridge-host',
            'billium.financial_session_control.v1',
            $expires,
            $nonce,
            $this->hmac($digest),
        );
    }

    private function hmac(string $value): string
    {
        return 'sha256:'.hash_hmac('sha256', $value, $this->authorityKey);
    }
}

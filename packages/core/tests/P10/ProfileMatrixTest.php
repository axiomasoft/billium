<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P10;

use Billium\Collection\Laravel\CollectionServiceProvider;
use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Money;
use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Data\Modules\CompatibilityInput;
use Billium\Core\Data\Modules\InstalledCheckpoint;
use Billium\Core\Data\Modules\MigrationIdentity;
use Billium\Core\Data\Modules\ModuleManifest;
use Billium\Core\Data\Modules\ModuleState;
use Billium\Core\Data\Modules\ModuleTransitionFacts;
use Billium\Core\Diagnostics\BilliumCompatibilityDoctorCheck;
use Billium\Core\Enums\Modules\CompatibilityFindingCode as Code;
use Billium\Core\Enums\Modules\ModuleLifecycleState as State;
use Billium\Core\Enums\Modules\ModuleOperationClass as Operation;
use Billium\Core\Internal\Modules\ModuleCatalog;
use Billium\Core\Internal\Modules\ModuleLifecycle;
use Billium\Documents\Contracts\DocumentProfileRegistry;
use Billium\Documents\Contracts\DocumentRenderService;
use Billium\Documents\Contracts\LegalDocumentIssuer;
use Billium\Documents\Laravel\DocumentsServiceProvider;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Laravel\ExecutionServiceProvider;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Laravel\LedgerServiceProvider;
use Billium\Platform\Laravel\PlatformServiceProvider;
use Billium\Server\BilliumServerServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Enums\CheckStatus;
use Packman\Plugins\Contracts\Contributions\Capabilities;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('P10.1')]
#[Group('D71')]
#[Group('D72')]
final class ProfileMatrixTest extends TestCase
{
    private const array LEDGER_PORTS = [
        'AuthContext',
        'SellerContext',
        'AuthorizationVerdict',
        'FinancialSessionAdmission',
        'FinancialAuthorityEvidence',
    ];

    private const array EXECUTION_PORTS = [
        'PaymentDrivers',
        'WriterAdmission',
        'PaymentObservationSink',
        'DispatchEgress',
        'MerchantCredentials',
        'ProviderAbsenceVerifier',
        'ExecutionOriginResolver',
        'AdapterQualification',
        'ExecutionReadiness',
    ];

    public function test_omitted_execution_registers_zero_provider_dispatch_paths(): void
    {
        $catalog = ModuleCatalog::packaged();

        // Embedded-host (ledger-only) profile: execution and WriterAdmission are forbidden.
        $embedded = $catalog->check(self::input('ledger-only', ['platform', 'ledger']));
        self::assertTrue($embedded->isReady(), json_encode($embedded->findings, JSON_THROW_ON_ERROR));
        self::assertSame(['platform', 'ledger'], $embedded->bootOrder);

        $forbiddenExecution = $catalog->check(self::input('ledger-only', ['platform', 'ledger', 'execution']));
        self::assertTrue($forbiddenExecution->has(Code::CompositionForbidden, 'execution'));

        $forbiddenWriter = $catalog->check(self::input('ledger-only', ['platform', 'ledger'], [...self::LEDGER_PORTS, 'WriterAdmission']));
        self::assertTrue($forbiddenWriter->has(Code::PortForbidden));

        // Standalone profile with execution omitted: valid ledger-only runtime, zero provider dispatch.
        $standaloneNoExec = $catalog->check(self::input('standalone-billium', ['platform', 'ledger']));
        self::assertTrue($standaloneNoExec->isReady(), json_encode($standaloneNoExec->findings, JSON_THROW_ON_ERROR));

        foreach (['ledger-only', 'standalone-billium'] as $composition) {
            self::withBootedApp([
                'modules' => [
                    'composition' => $composition,
                    'enabled' => ['platform', 'ledger'],
                ],
                'execution' => [
                    'outgoing_enabled' => false,
                ],
            ], static function (Application $app): void {
                self::assertNotEmpty($app->getProviders(PlatformServiceProvider::class));
                self::assertNotEmpty($app->getProviders(LedgerServiceProvider::class));
                self::assertSame([], $app->getProviders(ExecutionServiceProvider::class));
                self::assertSame([], $app->getProviders(CollectionServiceProvider::class));
                self::assertFalse($app->bound(PaymentDrivers::class));
                self::assertFalse($app->bound(WriterAdmission::class));
            });
        }
    }

    public function test_disabled_documents_preserves_ledger_only_paths_without_document_ports(): void
    {
        $catalog = ModuleCatalog::packaged();
        $report = $catalog->check(self::input('ledger-only', ['platform', 'ledger'], self::LEDGER_PORTS));

        self::assertTrue($report->isReady(), json_encode($report->findings, JSON_THROW_ON_ERROR));
        self::assertFalse($report->has(Code::PortMissing, 'documents'));
        self::assertSame(['platform', 'ledger'], $report->bootOrder);

        self::withBootedApp([
            'modules' => [
                'composition' => 'ledger-only',
                'enabled' => ['platform', 'ledger'],
            ],
            'documents' => [
                'enabled' => false,
            ],
        ], static function (Application $app): void {
            $capabilities = $app->make(Capabilities::class);

            self::assertTrue($capabilities->has(Ledger::class));
            self::assertTrue($app->bound(Ledger::class));
            self::assertSame([], $app->getProviders(DocumentsServiceProvider::class));
            self::assertFalse($app->bound(LegalDocumentIssuer::class));
            self::assertFalse($app->bound(DocumentRenderService::class));
            self::assertFalse($app->bound(DocumentProfileRegistry::class));
        });
    }

    public function test_history_read_and_receipt_replay_remain_allowed_after_module_disable(): void
    {
        $catalog = ModuleCatalog::packaged();
        $lifecycle = new ModuleLifecycle($catalog);

        foreach (['documents', 'usage', 'execution', 'ledger'] as $moduleId) {
            foreach ([State::Draining, State::RecoveryOnly, State::Retired] as $disabledState) {
                $state = new ModuleState('inst-p10', $moduleId, $disabledState, 1, 0);

                self::assertFalse(
                    $lifecycle->admit($state, Operation::NewIntent)->allowed,
                    "{$moduleId} in {$disabledState->value} must refuse new intents",
                );
                self::assertTrue(
                    $lifecycle->admit($state, Operation::HistoricalRead)->allowed,
                    "{$moduleId} in {$disabledState->value} must keep historical_read",
                );
                self::assertTrue(
                    $lifecycle->admit($state, Operation::ReceiptReplay)->allowed,
                    "{$moduleId} in {$disabledState->value} must keep receipt_replay",
                );
            }

            $drainPreview = $lifecycle->previewTransition(
                new ModuleState('inst-p10', $moduleId, State::Accepting, 1, 0),
                State::Draining,
                new ModuleTransitionFacts,
            );
            self::assertTrue($drainPreview->allowed());
            self::assertFalse($drainPreview->purgesData);
            self::assertContains(Operation::HistoricalRead, $drainPreview->stillServed);
            self::assertContains(Operation::ReceiptReplay, $drainPreview->stillServed);
        }

        // Disabled module with retained history requires a historical reader at compatibility check time.
        $missingReader = $catalog->check(new CompatibilityInput(
            composition: 'ledger-only',
            enabled: ['platform', 'ledger'],
            providedPorts: self::LEDGER_PORTS,
            checkpoints: self::migrated('platform', 'ledger'),
            modulesWithHistory: ['documents'],
            historicalReaders: [],
        ));
        self::assertTrue($missingReader->has(Code::HistoricalReaderMissing, 'documents'));

        $withReader = $catalog->check(new CompatibilityInput(
            composition: 'ledger-only',
            enabled: ['platform', 'ledger'],
            providedPorts: self::LEDGER_PORTS,
            checkpoints: self::migrated('platform', 'ledger'),
            modulesWithHistory: ['documents'],
            historicalReaders: ['documents'],
        ));
        self::assertTrue($withReader->isReady(), json_encode($withReader->findings, JSON_THROW_ON_ERROR));
    }

    public function test_incompatible_module_dependency_and_version_are_rejected_at_startup(): void
    {
        $catalog = ModuleCatalog::packaged();

        // Missing module dependencies in DAG.
        self::assertTrue($catalog->check(self::input('ledger-only', ['platform', 'ledger', 'schedule']))->has(Code::DependencyMissing, 'schedule'));
        self::assertTrue($catalog->check(self::input('standalone-billium', ['platform', 'ledger', 'collection'], [...self::LEDGER_PORTS, 'CollectionConsentGuard']))->has(Code::DependencyMissing, 'collection'));

        // Remote profile requires execution.
        self::assertTrue($catalog->check(self::input('remote-billium', ['platform', 'ledger']))->has(Code::CompositionRequired, 'execution'));

        // Incompatible dependency API version range.
        $badDependencyRange = self::catalogWith(static function (array $doc): array {
            foreach ($doc['modules'] as $i => $module) {
                if ($module['moduleId'] === 'ledger') {
                    $doc['modules'][$i]['requiresModules'] = [
                        ['moduleId' => 'platform', 'apiRange' => ['min' => '2.0.0', 'maxExclusive' => '3.0.0']],
                    ];
                }
            }

            return $doc;
        })->check(self::input('ledger-only', ['platform', 'ledger']));
        self::assertTrue($badDependencyRange->has(Code::DependencyRange, 'ledger'));

        // Incompatible core API version.
        $badCoreApi = self::catalogWith(static fn (array $doc): array => [...$doc, 'coreApiVersion' => '2.0.0'])
            ->check(self::input('ledger-only', ['platform', 'ledger']));
        self::assertTrue($badCoreApi->has(Code::CoreApiUnsupported, 'ledger'));

        // Unapplied schema version.
        $staleSchema = $catalog->check(new CompatibilityInput(
            composition: 'ledger-only',
            enabled: ['platform', 'ledger'],
            providedPorts: self::LEDGER_PORTS,
            checkpoints: [
                'platform' => self::migrated('platform')['platform'],
                'ledger' => new InstalledCheckpoint('ledger', 0, []),
            ],
        ));
        self::assertTrue($staleSchema->has(Code::MigrationUnapplied, 'ledger'));
        self::assertTrue($staleSchema->has(Code::SchemaReadUnsupported, 'ledger'));

        // Runtime boot guard rejects execution enabled under ledger-only composition.
        $bootFailed = false;
        try {
            self::withBootedApp([
                'modules' => [
                    'composition' => 'ledger-only',
                    'enabled' => ['platform', 'ledger', 'execution'],
                ],
                'execution' => [
                    'outgoing_enabled' => false,
                ],
            ], static function (): void {});
        } catch (InvalidConfiguration) {
            $bootFailed = true;
        }
        self::assertTrue($bootFailed, 'Booting execution under ledger-only composition must throw InvalidConfiguration');

        // Doctor check fails in production when composition is unknown.
        $raw = require dirname(__DIR__, 2).'/config/billium.php';
        $raw['modules'] = ['composition' => 'unknown-profile', 'enabled' => ['platform', 'ledger']];
        $check = new BilliumCompatibilityDoctorCheck(
            new Repository(['app' => ['key' => 'base64:'.base64_encode(str_repeat('k', 32))], 'billium' => $raw]),
            $catalog,
            portsResolver: static fn (): array => self::LEDGER_PORTS,
            checkpointsResolver: static fn (): array => self::migrated('platform', 'ledger'),
        );
        $findings = $check->run(new DoctorContext(production: true));
        self::assertNotEmpty($findings);
        self::assertSame(CheckStatus::Error, $findings[0]->status);
    }

    public function test_php_and_dart_contract_packages_have_no_transitive_core_server_or_provider_imports(): void
    {
        $repoRoot = dirname(__DIR__, 4);

        // 1. PHP contracts composer.json must not depend on core, server, framework, DB, or provider SDKs.
        $phpComposer = json_decode((string) file_get_contents($repoRoot.'/packages/contracts/composer.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($phpComposer);
        $phpRequires = array_keys(is_array($phpComposer['require'] ?? null) ? $phpComposer['require'] : []);
        foreach ($phpRequires as $package) {
            self::assertDoesNotMatchRegularExpression(
                '/^(?:axiomasoft\/billium-(?:core|server|platform|ledger|execution|documents|collection|usage|rating|schedule)|laravel\/|illuminate\/|ext-pdo|stripe\/|adyen\/|yookassa\/|tinkoff\/|cloudpayments\/|paypal\/|braintree\/)/i',
                $package,
                "PHP contracts package must not require {$package}",
            );
        }

        // 2. PHP contracts source files must not import core, server, framework, DB, or provider namespaces.
        $phpFiles = self::filesWithExtension($repoRoot.'/packages/contracts/src', 'php');
        self::assertNotEmpty($phpFiles);
        foreach ($phpFiles as $file) {
            $source = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression(
                '/\b(?:Billium\\\\(?:Core|Server|Platform|Ledger|Execution|Documents|Collection|Usage|Rating|Schedule)|Illuminate\\\\|PDO\b|Stripe\\\\|Adyen\\\\|YooKassa\\\\)/',
                $source,
                "Forbidden import in {$file}",
            );
        }

        // 3. Dart contracts pubspec.yaml must stay pure-Dart with no core/server/HTTP/DB/provider dependencies.
        $pubspec = (string) file_get_contents($repoRoot.'/packages/contracts-dart/pubspec.yaml');
        self::assertMatchesRegularExpression('/^name:\s*billium_contracts\b/m', $pubspec);
        preg_match('/^dependencies:\s*\n((?:[ \t]+.*\n?)*)/m', $pubspec, $depMatch);
        $depBlock = $depMatch[1] ?? '';
        self::assertDoesNotMatchRegularExpression(
            '/^\s*(?:billium_core|billium_server|http|dio|postgres|sqlite3|sqflite|stripe|adyen|flutter)\s*:/mi',
            $depBlock,
            'Dart contracts pubspec.yaml contains forbidden dependency',
        );

        // 4. Dart contracts lib/ files must not import dart:io, dart:ffi, HTTP clients, DB drivers, or core/server.
        $dartFiles = self::filesWithExtension($repoRoot.'/packages/contracts-dart/lib', 'dart');
        self::assertNotEmpty($dartFiles);
        foreach ($dartFiles as $file) {
            $source = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression(
                '/^\s*import\s+[\'"](?:dart:(?:io|ffi)|package:(?:http|dio|postgres|sqlite3|billium_core|billium_server|flutter)\/)/m',
                $source,
                "Forbidden import in {$file}",
            );
        }

        // 5. Contract-only runtime smoke (Money + Codec) operates standalone.
        $money = Money::parse('1490.00', 'RUB');
        self::assertSame(149000, $money->minor);
        $codec = new Codec;
        self::assertSame('{"amount":"1490.00","currency":"RUB"}', $codec->canonicalize(['currency' => 'RUB', 'amount' => '1490.00']));
    }

    public function test_profile_matrix_compositions_and_remote_server_provider_boot_cleanly(): void
    {
        $catalog = ModuleCatalog::packaged();
        $fullPorts = [...self::LEDGER_PORTS, ...self::EXECUTION_PORTS, 'CollectionConsentGuard'];

        $standalone = $catalog->check(self::input('standalone-billium', ['collection', 'execution', 'ledger', 'platform'], $fullPorts));
        self::assertTrue($standalone->isReady(), json_encode($standalone->findings, JSON_THROW_ON_ERROR));
        self::assertSame(['platform', 'ledger', 'execution', 'collection'], $standalone->bootOrder);

        $remote = $catalog->check(self::input('remote-billium', ['platform', 'ledger', 'execution'], $fullPorts));
        self::assertTrue($remote->isReady(), json_encode($remote->findings, JSON_THROW_ON_ERROR));
        self::assertSame(['platform', 'ledger', 'execution'], $remote->bootOrder);

        self::withBootedApp([
            'modules' => [
                'composition' => 'remote-billium',
                'enabled' => ['platform', 'ledger', 'execution'],
            ],
            'execution' => [
                'outgoing_enabled' => false,
            ],
        ], static function (Application $app): void {
            self::assertNotEmpty($app->getProviders(PlatformServiceProvider::class));
            self::assertNotEmpty($app->getProviders(LedgerServiceProvider::class));
            self::assertNotEmpty($app->getProviders(ExecutionServiceProvider::class));
            self::assertNotEmpty($app->getProviders(BilliumServerServiceProvider::class));
        }, withServer: true);
    }

    /**
     * @param  list<string>  $enabled
     * @param  list<string>  $ports
     */
    private static function input(string $composition, array $enabled, array $ports = self::LEDGER_PORTS): CompatibilityInput
    {
        return new CompatibilityInput($composition, $enabled, $ports, self::migrated(...$enabled));
    }

    /** @return array<string, InstalledCheckpoint> */
    private static function migrated(string ...$modules): array
    {
        $catalog = ModuleCatalog::packaged();
        $checkpoints = [];
        foreach ($modules as $module) {
            $descriptor = $catalog->descriptor($module);
            if ($descriptor !== null) {
                $checkpoints[$module] = new InstalledCheckpoint(
                    $module,
                    $descriptor->currentSchemaVersion,
                    array_map(static fn (MigrationIdentity $m): string => $m->id, $descriptor->migrationIdentities),
                );
            }
        }

        return $checkpoints;
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    private static function catalogWith(callable $mutate): ModuleCatalog
    {
        $json = (string) file_get_contents(dirname(__DIR__, 2).'/resources/modules.json');
        /** @var array<string, mixed> $doc */
        $doc = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

        return new ModuleCatalog(ModuleManifest::fromArray($mutate($doc)));
    }

    /**
     * @param  array<string, mixed>  $billiumConfig
     * @param  callable(Application): void  $assert
     */
    private static function withBootedApp(array $billiumConfig, callable $assert, bool $withServer = false): void
    {
        $backup = Container::getInstance();
        try {
            $app = new Application(dirname(__DIR__, 4));
            Container::setInstance($app);
            $app->instance('config', new Repository([
                'app' => [
                    'key' => 'base64:'.base64_encode(str_repeat('k', 32)),
                    'cipher' => 'AES-256-CBC',
                ],
                'database' => [
                    'default' => 'pgsql',
                    'connections' => [
                        'pgsql' => ['driver' => 'pgsql', 'database' => 'billium_test'],
                    ],
                ],
                'billium' => [
                    'feed' => ['signing_key' => str_repeat('k', 32)],
                    ...$billiumConfig,
                ],
            ]));
            $app->register(DatabaseServiceProvider::class);
            $app->register(EncryptionServiceProvider::class);
            $app->register(FilesystemServiceProvider::class);
            $app->register(BilliumCoreServiceProvider::class);
            if ($withServer) {
                $app->register(BilliumServerServiceProvider::class);
            }
            $app->boot();

            $assert($app);
        } finally {
            Container::setInstance($backup);
        }
    }

    /** @return list<string> */
    private static function filesWithExtension(string $directory, string $extension): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $item) {
            if ($item instanceof \SplFileInfo && $item->getExtension() === $extension) {
                $files[] = $item->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}

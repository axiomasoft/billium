<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Errors\BilliumErrorCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\EventPublisher;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Modules\CompatibilityInput;
use Billium\Core\Data\Modules\CompatibilityReport;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Diagnostics\BilliumCompatibilityDoctorCheck;
use Billium\Core\Errors\BilliumCoreErrorCode;
use Billium\Core\Exceptions\Modules\ModuleAdmissionRefused;
use Billium\Core\Exceptions\Runner\LockOrderViolation;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Internal\Observation\ObservationHandler;
use Billium\Ledger\Internal\Snapshot\LedgerSnapshotSource;
use Billium\Ledger\Laravel\LedgerServiceProvider;
use Billium\Platform\Contracts\Feed\EventFeed;
use Billium\Platform\Contracts\Feed\SnapshotSource;
use Billium\Platform\Contracts\Inbox\BootstrapItemHandler;
use Billium\Platform\Contracts\Inbox\CustodyHandler;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Platform\Data\Feed\FeedSelection;
use Billium\Platform\Exceptions\TransitionRefused;
use Billium\Platform\Internal\Outbox\EventFeedReader;
use Billium\Platform\Laravel\PlatformServiceProvider;
use Billium\Rating\Contracts\RatingQuoter;
use Billium\Rating\Laravel\RatingServiceProvider;
use Billium\Schedule\Laravel\ScheduleServiceProvider;
use Billium\Usage\Laravel\UsageServiceProvider;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Override;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigSectionRegistration;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Enums\CheckStatus;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Plugins\Contracts\Contributions\Capabilities;
use Packman\Plugins\Contracts\Contributions\Extensions;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Packman\Testkit\Data\Database\TestDatabaseIdentity;
use Packman\Testkit\Exceptions\Database\UnsafeTestDatabaseException;
use Packman\Testkit\PackageTestCase;
use Packman\Testkit\Support\Database\TestDatabaseGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

final class PackmanIntegrationTest extends PackageTestCase
{
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [BilliumCoreServiceProvider::class];
    }

    public function test_problem_registry_registers_contracts_and_core_error_codes(): void
    {
        $registry = $this->app->make(ProblemRegistry::class);

        $this->assertAllErrorCodesRegistered($registry, BilliumErrorCode::class);
        $this->assertAllErrorCodesRegistered($registry, BilliumCoreErrorCode::class);

        $this->assertCodedExceptionRegistered($registry, new ProblemException(ProblemCode::ValidationInvalid, 'bad field'), 422);
        $this->assertCodedExceptionRegistered($registry, new ProblemException(ProblemCode::ReferenceUnknown, 'missing'), 404);
        $registry->assertOccurrence((new LockOrderViolation('out of order'))->errorDescriptor(), 500);
        $this->assertCodedExceptionRegistered($registry, new LockPlanChanged('changed'), 409);
        $this->assertCodedExceptionRegistered($registry, new ModuleAdmissionRefused('ledger', 'retired'), 503);
        $this->assertCodedExceptionRegistered($registry, new TransitionRefused('blocked', ['pending_operations']), 409);
    }

    public function test_config_section_ownership_enforces_closed_billium_schema(): void
    {
        $billium = config('billium');
        self::assertIsArray($billium);

        ConfigSectionRegistration::assertKnownKeys($this->app, ['billium' => $billium]);

        try {
            ConfigSectionRegistration::assertKnownKeys($this->app, ['billium' => ['runnr' => []]]);
            self::fail('Unknown key inside billium section must fail');
        } catch (InvalidConfiguration $exception) {
            self::assertStringContainsString('did you mean "runner"', $exception->getMessage());
        }

        $this->expectException(InvalidConfiguration::class);
        ConfigSectionRegistration::assertKnownKeys($this->app, ['unknown_root' => []]);
    }

    public function test_options_singleton_freezes_once_across_container_resolutions(): void
    {
        $first = $this->app->make(BilliumOptions::class);
        config(['billium.runner.max_attempts' => 9]);
        $second = $this->app->make(BilliumOptions::class);

        self::assertSame($first, $second);
        self::assertSame(3, $second->runner->maxAttempts);
    }

    #[DataProvider('hostOptionsBindings')]
    public function test_host_prebound_options_control_module_registration(string $binding): void
    {
        $containerBackup = Container::getInstance();
        try {
            $app = new Application(dirname(__DIR__, 4));
            $app->instance('config', new Repository);
            $options = BilliumOptions::fromArray([
                'modules' => ['enabled' => ['platform', 'rating']],
                'rating' => ['enabled' => true],
            ], 'billium');
            if ($binding === 'instance') {
                $app->instance(BilliumOptions::class, $options);
            } else {
                $app->singleton(BilliumOptions::class, static fn (): BilliumOptions => $options);
            }

            $app->register(BilliumCoreServiceProvider::class);

            self::assertSame($options, $app->make(BilliumOptions::class));
            self::assertNotEmpty($app->getProviders(PlatformServiceProvider::class));
            self::assertNotEmpty($app->getProviders(RatingServiceProvider::class));
            foreach ([LedgerServiceProvider::class, UsageServiceProvider::class, ScheduleServiceProvider::class] as $provider) {
                self::assertSame([], $app->getProviders($provider));
            }
            self::assertFalse($app->bound(Ledger::class));
            self::assertInstanceOf(RatingQuoter::class, $app->make(RatingQuoter::class));
        } finally {
            Container::setInstance($containerBackup);
        }
    }

    public static function hostOptionsBindings(): array
    {
        return ['instance' => ['instance'], 'singleton' => ['singleton']];
    }

    public function test_doctor_check_queries_real_db_checkpoints_and_rejects_short_keys_or_bad_connections(): void
    {
        $this->app->instance(CallerContext::class, FixedCallerContext::default());
        $this->app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token('inst-test')));
        SimulatedFinancialAuthorityEvidence::admitCurrent($this->app);

        // 1. Empty SQLite DB has no applied migrations: must NOT report fake readiness.
        config(['billium.feed.signing_key' => str_repeat('k', 32)]);
        $check = $this->app->make(BilliumCompatibilityDoctorCheck::class);
        $unmigrated = $check->run(new DoctorContext(production: true));
        $codes = array_map(static fn ($f): string => $f->code, $unmigrated);
        self::assertContains('billium.modules.module.migration_unapplied', $codes);

        // 2. Short signing key (< 32 bytes) fails readiness immediately.
        config(['billium.feed.signing_key' => 'x']);
        $shortKey = $check->run(new DoctorContext(production: false));
        $shortCodes = array_map(static fn ($f): string => $f->code, $shortKey);
        self::assertContains('billium.feed.signing_key_too_short', $shortCodes);

        // 3. Nonexistent database connection fails readiness explicitly.
        config([
            'billium.connection' => 'nonexistent_billium_conn',
            'billium.feed.signing_key' => str_repeat('k', 32),
        ]);
        $badDb = $check->run(new DoctorContext(production: true));
        self::assertCount(1, $badDb);
        self::assertSame(CheckStatus::Error, $badDb[0]->status);
        self::assertSame('billium.database.unavailable', $badDb[0]->code);

        // 4. With real applied migrations in DB and valid 32+ byte signing key, doctor reports OK.
        config(['billium.connection' => null]);
        $db = $this->app->make(DatabaseManager::class)->connection();
        $db->getSchemaBuilder()->create('migrations', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        $registry = $this->app->make(ModuleRegistry::class);
        foreach (['platform', 'ledger'] as $moduleId) {
            $descriptor = $registry->descriptor($moduleId);
            self::assertNotNull($descriptor);
            foreach ($descriptor->migrationIdentities as $migration) {
                $db->table('migrations')->insert(['migration' => $migration->id, 'batch' => 1]);
            }
        }
        $ok = $check->run(new DoctorContext(production: true));
        self::assertCount(1, $ok);
        self::assertSame(CheckStatus::Ok, $ok[0]->status);
        self::assertSame('billium.modules.compatibility', $ok[0]->code);

        // 5. Unknown composition produces Error.
        config(['billium.modules.composition' => 'nonexistent-composition']);
        $badComp = $check->run(new DoctorContext(production: true));
        self::assertNotEmpty($badComp);
        self::assertSame(CheckStatus::Error, $badComp[0]->status);
    }

    public function test_schema_sets_declare_platform_as_platform_and_modules_as_module_lifecycle(): void
    {
        $sets = $this->app->make(SchemaSets::class);

        $platform = $sets->get('axiomasoft/billium-core:billium-platform');
        self::assertSame(SchemaScope::Central, $platform->scope);
        self::assertSame(SchemaLifecycle::Platform, $platform->lifecycle);
        self::assertSame(['pgsql'], $platform->drivers);

        foreach (['billium-ledger', 'billium-usage', 'billium-schedule', 'billium-rating'] as $moduleSet) {
            $set = $sets->get('axiomasoft/billium-core:'.$moduleSet);
            self::assertSame(SchemaScope::Central, $set->scope);
            self::assertSame(SchemaLifecycle::Module, $set->lifecycle);
            self::assertSame(['axiomasoft/billium-core'], $set->requires);
        }

        $nonModule = $sets->forScope(SchemaScope::Central, includeModules: false);
        self::assertSame(['axiomasoft/billium-core:billium-platform'], array_map(static fn ($s): string => $s->id(), $nonModule));
    }

    public function test_plugin_graph_exposes_real_capability_providers_and_ledger_slot_contributions(): void
    {
        $inspector = $this->app->make(PluginInspector::class);
        $capabilities = $this->app->make(Capabilities::class);
        $extensions = $this->app->make(Extensions::class);

        self::assertSame(
            ['axiomasoft.billium.platform', 'axiomasoft.billium.ledger'],
            array_map(static fn ($d): string => $d->id, $inspector->describe()),
        );
        self::assertTrue($capabilities->has(OperationRunner::class));
        self::assertTrue($capabilities->has(Installation::class));
        self::assertTrue($capabilities->has(Ledger::class));
        self::assertInstanceOf(OperationRunner::class, $capabilities->require(OperationRunner::class));
        self::assertInstanceOf(Ledger::class, $capabilities->require(Ledger::class));

        $snapshotContributions = $extensions->contributions(SnapshotSource::class);
        self::assertCount(5, $snapshotContributions);
        self::assertSame(
            LedgerSnapshotSource::KEYS,
            array_map(static fn ($c): ?string => $c->key, $snapshotContributions),
        );
        self::assertSame(ObservationHandler::class, $extensions->handlerClass(CustodyHandler::class, 'payment.observation'));
        self::assertSame(ObservationHandler::class, $extensions->handlerClass(BootstrapItemHandler::class, 'payment.observation'));
    }

    public function test_legacy_contract_container_bindings_forward_both_before_and_after_registration(): void
    {
        $containerBackup = Container::getInstance();
        try {
            // Pre-registered host legacy bindings (registered before BilliumCoreServiceProvider):
            $preApp = new Application(dirname(__DIR__, 4));
            $preApp->instance('config', new Repository([
                'database' => [
                    'default' => 'sqlite',
                    'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
                ],
            ]));
            $preApp->register(DatabaseServiceProvider::class);
            $preCaller = FixedCallerContext::default('pre-legacy');
            $preSession = new FixedSessionAdmission(FixedSessionAdmission::token('pre-legacy'));
            $prePublisher = new readonly class implements EventPublisher
            {
                public function publish(string $destinationRef, array $message): void {}
            };
            $preApp->singleton('Billium\\Core\\Contracts\\CallerContext', static fn (): CallerContext => $preCaller);
            $preApp->singleton('Billium\\Core\\Contracts\\FinancialSessionAdmission', static fn (): FinancialSessionAdmission => $preSession);
            $preApp->singleton('Billium\\Core\\Contracts\\EventPublisher', static fn (): EventPublisher => $prePublisher);
            $preRunner = new class implements OperationRunner
            {
                public function run(OperationSpec $spec, Closure $body): StoredOutcome
                {
                    throw new TransitionRefused('stub');
                }

                public function assertCurrent(TransactionContext $context, ?string $moduleId = null): void {}
            };
            $preApp->singleton('Billium\\Core\\Contracts\\OperationRunner', static fn (): OperationRunner => $preRunner);
            $preOptions = BilliumOptions::fromArray(['runner' => ['max_attempts' => 7]], 'billium');
            $preApp->instance(BilliumOptions::class, $preOptions);
            $preApp->register(BilliumCoreServiceProvider::class);

            self::assertSame($preOptions, $preApp->make(BilliumOptions::class));
            self::assertSame(7, $preApp->make(BilliumOptions::class)->runner->maxAttempts);
            self::assertSame($preCaller, $preApp->make(CallerContext::class));
            self::assertSame($preCaller, $preApp->make('Billium\\Core\\Contracts\\CallerContext'));
            self::assertSame($preSession, $preApp->make(FinancialSessionAdmission::class));
            self::assertSame($prePublisher, $preApp->make(EventPublisher::class));
            self::assertSame($preRunner, $preApp->make(OperationRunner::class));
            self::assertSame($preRunner, $preApp->make('Billium\\Core\\Contracts\\OperationRunner'));

            // Host-registered container alias targeting legacy interface before and after registration:
            $preAliasApp = new Application(dirname(__DIR__, 4));
            $preAliasApp->instance('config', new Repository([
                'database' => [
                    'default' => 'sqlite',
                    'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
                ],
            ]));
            $preAliasApp->register(DatabaseServiceProvider::class);
            $aliasCaller = FixedCallerContext::default('alias-legacy');
            $preAliasApp->instance(FixedCallerContext::class, $aliasCaller);
            $preAliasApp->alias(FixedCallerContext::class, 'Billium\\Core\\Contracts\\CallerContext');
            $preAliasApp->register(BilliumCoreServiceProvider::class);
            self::assertSame($aliasCaller, $preAliasApp->make(CallerContext::class));

            // Post-registered host legacy bindings (bound after BilliumCoreServiceProvider):
            $postApp = new Application(dirname(__DIR__, 4));
            $postApp->instance('config', new Repository([
                'database' => [
                    'default' => 'sqlite',
                    'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
                ],
            ]));
            $postApp->register(DatabaseServiceProvider::class);
            $postApp->register(BilliumCoreServiceProvider::class);
            $postCaller = FixedCallerContext::default('post-legacy');
            $postSession = new FixedSessionAdmission(FixedSessionAdmission::token('post-legacy'));
            $postPublisher = new readonly class implements EventPublisher
            {
                public function publish(string $destinationRef, array $message): void {}
            };
            $postApp->instance('Billium\\Core\\Contracts\\CallerContext', $postCaller);
            $postApp->instance('Billium\\Core\\Contracts\\FinancialSessionAdmission', $postSession);
            $postApp->instance('Billium\\Core\\Contracts\\EventPublisher', $postPublisher);

            self::assertSame($postCaller, $postApp->make(CallerContext::class));
            self::assertSame($postSession, $postApp->make(FinancialSessionAdmission::class));
            self::assertSame($postPublisher, $postApp->make(EventPublisher::class));

            $postAliasApp = new Application(dirname(__DIR__, 4));
            $postAliasApp->instance('config', new Repository([
                'database' => [
                    'default' => 'sqlite',
                    'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
                ],
            ]));
            $postAliasApp->register(DatabaseServiceProvider::class);
            $postAliasApp->register(BilliumCoreServiceProvider::class);
            $postAliasCaller = FixedCallerContext::default('post-alias-legacy');
            $postAliasApp->instance(FixedCallerContext::class, $postAliasCaller);
            $postAliasApp->alias(FixedCallerContext::class, 'Billium\\Core\\Contracts\\CallerContext');
            self::assertSame($postAliasCaller, $postAliasApp->make(CallerContext::class));
        } finally {
            Container::setInstance($containerBackup);
        }
    }

    #[DataProvider('hostSnapshotBindings')]
    public function test_host_snapshot_key_overrides_default_plugin_slot(string $binding, bool $beforeRegistration): void
    {
        $containerBackup = Container::getInstance();
        try {
            $app = new Application(dirname(__DIR__, 4));
            $app->instance('config', new Repository([
                'database' => [
                    'default' => 'sqlite',
                    'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
                ],
                'billium' => ['feed' => ['signing_key' => str_repeat('k', 32)]],
            ]));
            $app->register(DatabaseServiceProvider::class);
            if (! $beforeRegistration) {
                $app->register(BilliumCoreServiceProvider::class);
            }

            $override = new class implements SnapshotSource
            {
                public function key(): string
                {
                    return 'ledger.obligations';
                }

                public function snapshot(FeedSelection $selection, string $connection): array
                {
                    return [];
                }
            };
            $key = 'billium.snapshot.ledger.obligations';
            if ($binding === 'instance') {
                $app->instance($key, $override);
            } elseif ($binding === 'binding') {
                $app->bind($key, static fn (): SnapshotSource => $override);
            } else {
                $app->instance($override::class, $override);
                $app->alias($override::class, 'host.snapshot.obligations');
                $app->alias('host.snapshot.obligations', $key);
            }
            if ($beforeRegistration) {
                $app->register(BilliumCoreServiceProvider::class);
            }

            self::assertSame($override, $app->make($key));
            $reader = $app->make(EventFeed::class);
            self::assertInstanceOf(EventFeedReader::class, $reader);
            /** @var callable(): iterable<SnapshotSource> $resolver */
            $resolver = new ReflectionProperty(EventFeedReader::class, 'sources')->getValue($reader);
            $sources = iterator_to_array($resolver(), false);
            self::assertSame(LedgerSnapshotSource::KEYS, array_map(static fn (SnapshotSource $source): string => $source->key(), $sources));
            self::assertSame($override, $sources[0]);
        } finally {
            Container::setInstance($containerBackup);
        }
    }

    public static function hostSnapshotBindings(): array
    {
        return [
            'prebound instance' => ['instance', true],
            'prebound binding' => ['binding', true],
            'prebound alias chain' => ['alias', true],
            'late instance' => ['instance', false],
            'late binding' => ['binding', false],
            'late alias chain' => ['alias', false],
        ];
    }

    public function test_host_tagged_snapshot_source_overrides_default_plugin_slot(): void
    {
        $containerBackup = Container::getInstance();
        try {
            $app = new Application(dirname(__DIR__, 4));
            $app->instance('config', new Repository([
                'database' => [
                    'default' => 'sqlite',
                    'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
                ],
                'billium' => [
                    'feed' => ['signing_key' => str_repeat('k', 32)],
                ],
            ]));
            $app->register(DatabaseServiceProvider::class);
            $app->register(BilliumCoreServiceProvider::class);

            $override = new class implements SnapshotSource
            {
                public function key(): string
                {
                    return 'ledger.obligations';
                }

                public function snapshot(FeedSelection $selection, string $connection): array
                {
                    return [];
                }
            };
            $app->instance($override::class, $override);
            $app->tag($override::class, 'billium.snapshot_sources');

            $reader = $app->make(EventFeed::class);
            self::assertInstanceOf(EventFeedReader::class, $reader);
            /** @var callable(): iterable<SnapshotSource> $resolver */
            $resolver = new ReflectionProperty(EventFeedReader::class, 'sources')->getValue($reader);
            $resolved = [];
            foreach ($resolver() as $source) {
                $resolved[$source->key()] = $source;
            }
            self::assertSame($override, $resolved['ledger.obligations'] ?? null);
            self::assertSame(LedgerSnapshotSource::KEYS, array_keys($resolved));
        } finally {
            Container::setInstance($containerBackup);
        }
    }

    public function test_destructive_test_database_guard_refuses_without_opt_in(): void
    {
        $phpunit = file_get_contents(dirname(__DIR__, 4).'/phpunit.xml');
        self::assertIsString($phpunit);
        self::assertStringNotContainsString('PACKMAN_TEST_DESTRUCTIVE_OPT_IN', $phpunit);

        $this->expectException(UnsafeTestDatabaseException::class);
        TestDatabaseGuard::assertDestructiveSafe(
            new TestDatabaseIdentity('pgsql', '127.0.0.1', 54329, 'billium_test', 'skm'),
            optInOverride: false,
        );
    }

    public function test_module_catalog_report_smoke(): void
    {
        self::assertInstanceOf(CompatibilityReport::class, $this->app->make(ModuleRegistry::class)->check(
            CompatibilityInput::fromConfig(
                ['composition' => 'embedded-commerz', 'enabled' => ['platform', 'ledger']],
                [],
                [],
            ),
        ));
    }
}

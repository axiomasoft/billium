<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit\Modules;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Data\Modules\InstalledCheckpoint;
use Billium\Core\Tests\TestEnvironment;
use Billium\Rating\Contracts\PricingStrategyRegistry;
use Billium\Rating\Internal\RatingServiceProvider;
use Billium\Schedule\Internal\ScheduleServiceProvider;
use Billium\Usage\Internal\UsageServiceProvider;
use BilliumConformance\LedgerAdapter;
use BilliumConformance\P4RuntimeAdapter;
use Composer\Autoload\ClassLoader;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Group;
use stdClass;
use Symfony\Component\Process\Process;

final class P4BootstrapTest extends ModuleTestCase
{
    public function test_composer_installs_runtime_and_test_prefixes_before_module_implementation(): void
    {
        $prefixes = [];
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $prefixes += $loader->getPrefixesPsr4();
        }
        foreach (['Usage', 'Rating', 'Schedule'] as $module) {
            self::assertArrayHasKey("Billium\\{$module}\\", $prefixes);
            self::assertArrayHasKey("Billium\\{$module}\\Tests\\", $prefixes);
        }
        $discovery = file_get_contents(dirname(__DIR__, 5).'/phpunit.xml');
        self::assertIsString($discovery);
        self::assertStringContainsString('packages/core/modules/*/tests', $discovery);
    }

    public function test_rating_descriptor_has_a_real_installable_checkpoint(): void
    {
        foreach (['rating'] as $module) {
            $descriptor = self::catalog()->descriptor($module);
            self::assertNotNull($descriptor);
            $checkpoint = new InstalledCheckpoint($module);
            self::assertSame(0, $checkpoint->schemaVersion);
            self::assertSame(2, $descriptor->currentSchemaVersion);
            self::assertCount(2, $descriptor->migrationIdentities);
            self::assertCount(13, $descriptor->ownedTables);
            self::assertSame([], $checkpoint->appliedMigrations);
        }
    }

    public function test_provider_defaults_do_not_load_absent_or_disabled_modules(): void
    {
        $app = new Application(dirname(__DIR__, 5));
        $app->instance('config', new Repository);
        $app->register(BilliumCoreServiceProvider::class);
        self::assertSame(['platform', 'ledger'], $app['config']->get('billium.modules.enabled'));
        foreach (['usage', 'rating', 'schedule'] as $module) {
            self::assertFalse($app['config']->get("billium.{$module}.enabled"));
        }
        self::assertFalse($app->bound(PricingStrategyRegistry::class));
        // A new host compiles its configured composition before registering any module providers.
        $app = new Application(dirname(__DIR__, 5));
        $app->instance('config', new Repository([
            'billium' => [
                'modules' => ['enabled' => ['platform', 'ledger', 'usage', 'rating', 'schedule']],
                'usage' => ['enabled' => true],
                'rating' => ['enabled' => true],
                'schedule' => ['enabled' => true],
            ],
        ]));
        $app->register(BilliumCoreServiceProvider::class);
        self::assertArrayHasKey(UsageServiceProvider::class, $app->getLoadedProviders());
        self::assertArrayHasKey(ScheduleServiceProvider::class, $app->getLoadedProviders());
        self::assertArrayHasKey(RatingServiceProvider::class, $app->getLoadedProviders());
        self::assertInstanceOf(PricingStrategyRegistry::class, $app->make(PricingStrategyRegistry::class));
        Container::setInstance(null);
    }

    public function test_rating_and_schedule_admit_unposted_basis_without_ledger(): void
    {
        foreach (['rating', 'schedule'] as $module) {
            self::assertNotContains('ledger', self::catalog()->descriptor($module)->requiredModuleIds());
        }
    }

    public function test_every_unavailable_runtime_operation_refuses_instead_of_inventing_zero(): void
    {
        $adapter = new P4RuntimeAdapter(new Container);
        foreach (P4RuntimeAdapter::OPERATIONS as $operation) {
            try {
                $adapter->run(operation: $operation, arguments: new stdClass);
                self::fail("{$operation} claimed an algorithm");
            } catch (ProblemException $exception) {
                self::assertSame(ProblemCode::PolicyUnknown, $exception->problem);
            }
        }
    }

    public function test_deferred_dispatch_calls_only_the_module_owned_binding(): void
    {
        $container = new Container;
        $arguments = (object) ['case' => 'provider enrollment only'];
        $container->instance('billium.conformance.rating', static function (string $operation, stdClass $input) use ($arguments): array {
            self::assertSame('rating.rate', $operation);
            self::assertSame($arguments, $input);

            return ['enrolled' => true];
        });
        self::assertSame(['enrolled' => true], new P4RuntimeAdapter($container)->run(operation: 'rating.rate', arguments: $arguments));
    }

    #[Group('pg')]
    public function test_php_protocol_enrolls_runtime_operations_and_refuses_without_database_setup(): void
    {
        $root = dirname(__DIR__, 5);
        // The adapter joins the parent's verified DB lease, including when earlier tests used committed data (D50).
        $process = new Process(command: [PHP_BINARY, 'conformance/adapters/php/bin/adapter.php'], cwd: $root,
            env: TestEnvironment::suiteLeaseEnvironment());
        $messages = [
            ['type' => 'start', 'protocol' => 1],
            ['type' => 'config', 'profile' => 'php'],
            ['type' => 'run', 'id' => 'p4/bootstrap', 'given' => (object) ['modules' => ['usage', 'rating', 'schedule']], 'script' => array_map(
                static fn (string $operation): array => ['op' => $operation, 'args' => new stdClass], P4RuntimeAdapter::OPERATIONS,
            )],
            ['type' => 'stop'],
        ];
        $process->setInput(implode("\n", array_map(static fn (array $message): string => json_encode($message, JSON_THROW_ON_ERROR), $messages))."\n");
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        $outputs = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", trim($process->getOutput())));
        self::assertSame('ready', $outputs[0]['type']);
        foreach (P4RuntimeAdapter::OPERATIONS as $operation) {
            self::assertContains($operation, $outputs[0]['ops']);
        }
        self::assertCount(count(P4RuntimeAdapter::OPERATIONS), $outputs[2]['outputs']);
        foreach ($outputs[2]['outputs'] as $output) {
            self::assertSame(ProblemCode::PolicyUnknown->value, $output['error']['code']);
        }
    }

    public function test_runtime_host_selection_refuses_unknown_modules_and_non_lists(): void
    {
        foreach ([['execution'], 'usage'] as $modules) {
            try {
                (new LedgerAdapter)->given((object) ['modules' => $modules]);
                self::fail('invalid runtime host module selection was accepted');
            } catch (ProblemException $exception) {
                self::assertSame(ProblemCode::ValidationInvalid, $exception->problem);
            }
        }
    }

    #[Group('pg')]
    public function test_php_protocol_reaches_rating_quote_after_runtime_module_selection(): void
    {
        $root = dirname(__DIR__, 5);
        $fixture = json_decode(file_get_contents($root.'/fixtures/vectors/rating/pipeline/graduated-original.json'), true, 128, JSON_THROW_ON_ERROR);
        $process = new Process(command: [PHP_BINARY, 'conformance/adapters/php/bin/adapter.php'], cwd: $root,
            env: TestEnvironment::suiteLeaseEnvironment());
        $messages = [
            ['type' => 'start', 'protocol' => 1],
            ['type' => 'config', 'profile' => 'php'],
            ['type' => 'run', 'id' => $fixture['id'], 'given' => $fixture['given'], 'script' => $fixture['script']],
            ['type' => 'stop'],
        ];
        $process->setInput(implode("\n", array_map(static fn (array $message): string => json_encode($message, JSON_THROW_ON_ERROR), $messages))."\n");
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        $outputs = array_map(static fn (string $line): array => json_decode($line, true, 128, JSON_THROW_ON_ERROR), explode("\n", trim($process->getOutput())));
        self::assertSame($fixture['expect'], $outputs[2]['outputs']);
    }
}

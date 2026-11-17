<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Integration;

use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Platform\Internal\Installation\DatabaseInstallation;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\TestCase;

/** Fresh database per composition, committed roots and distinct migration/runtime roles (D50/D55). */
abstract class CompositionTestCase extends TestCase
{
    private string $database;

    /** @return list<string> */
    protected function modules(): array
    {
        return ['platform', 'rating', 'schedule'];
    }

    /** @param Application $app */
    protected function getPackageProviders($app): array
    {
        TestEnvironment::configure($app);
        $config = $app->make(Repository::class);
        foreach (['pgsql', 'billium_owner'] as $connection) {
            $config->set("database.connections.{$connection}.database", $this->database);
        }
        $config->set('billium.modules.enabled', $this->modules());
        foreach (['usage', 'rating', 'schedule'] as $module) {
            $config->set("billium.{$module}.enabled", in_array($module, $this->modules(), true));
        }

        return [BilliumCoreServiceProvider::class];
    }

    protected function setUp(): void
    {
        TestEnvironment::preflight();
        TestEnvironment::acquireSuiteLease();
        $this->database = 'billium_p49_'.bin2hex(random_bytes(6)).'_test';
        TestEnvironment::pdo(owner: true)->exec('CREATE DATABASE "'.$this->database.'"');
        parent::setUp();
        SimulatedFinancialAuthorityEvidence::reset();
        $this->owner()->unprepared('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
        $this->application()->instance(CallerContext::class, FixedCallerContext::default());
        $this->application()->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token()));
        SimulatedFinancialAuthorityEvidence::admitCurrent($this->application());
        // Boot is migration discovery, never installation.
        self::assertNull($this->owner()->scalar("SELECT to_regclass('public.billium_module_state')::text"));
        $this->migrateModules();
        $installation = $this->application()->make(Installation::class);
        $installation->init('inst-test');
        SimulatedFinancialAuthorityEvidence::install($this->application())->issueTransition('open', 'open-1', ['owner-1', 1,
            1, 1, 'test-open']);
        $installation->openSession(transitionRef: 'open-1', ownerSessionRef: 'owner-1', deploymentGeneration: 1,
            attachmentGeneration: 1, authorityEpoch: 1, evidenceRef: 'test-open');
        SimulatedFinancialAuthorityEvidence::install($this->application())->issueTransition('activate', 'activate-1', [1, 'test-certificate',
            'test-admission', 'test-activate']);
        $installation->activateSession(transitionRef: 'activate-1', epoch: 1, activationCertificateRef: 'test-certificate',
            externalAdmissionRef: 'test-admission', evidenceRef: 'test-activate');
    }

    protected function owner(): Connection
    {
        return $this->application()->make(DatabaseManager::class)->connection('billium_owner');
    }

    protected function application(): Application
    {
        return $this->app ?? self::fail('Testbench application is not booted');
    }

    protected function migrateModules(): void
    {
        $command = $this->artisan('migrate', ['--database' => 'billium_owner', '--force' => true]);
        self::assertInstanceOf(PendingCommand::class, $command);
        $command->assertExitCode(0);
    }

    /** @param class-string<ServiceProvider> $provider */
    protected function attachModule(string $module, string $provider): void
    {
        $app = $this->application();
        $config = $app->make(Repository::class);
        $enabled = $app->make(BilliumOptions::class)->modules->enabled;
        $config->set('billium.modules.enabled', array_values(array_unique([...$enabled, $module])));
        if (in_array($module, ['usage', 'rating', 'schedule'], true)) {
            $config->set("billium.{$module}.enabled", true);
        }
        // Simulate a host reconfiguration: rebuild immutable options and the installation facade.
        // Existing domain objects retain their previous snapshot and must be resolved anew by callers.
        $app->forgetInstance(BilliumOptions::class);
        self::assertContains($module, $app->make(BilliumOptions::class)->modules->enabled);
        $app->register($provider);
        $this->migrateModules();
        $app->forgetInstance(DatabaseInstallation::class);
        $app->make(Installation::class)->init('inst-test');
        self::assertSame('accepting', $this->owner()->table('billium_module_state')
            ->where('installation_ref', 'inst-test')->where('module_id', $module)->value('state'));
    }

    protected function tearDown(): void
    {
        try {
            $databases = $this->application()->make(DatabaseManager::class);
            foreach (array_keys($databases->getConnections()) as $name) {
                $databases->purge($name);
            }
            parent::tearDown();
        } finally {
            TestEnvironment::pdo(owner: true)->exec('DROP DATABASE IF EXISTS "'.$this->database.'" WITH (FORCE)');
        }
    }
}

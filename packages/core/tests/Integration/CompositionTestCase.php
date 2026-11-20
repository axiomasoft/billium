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
use Orchestra\Testbench\TestCase;
use Packman\Schema\Contracts\Migration\SchemaMigrator;

/** Fresh schema per composition in the leased test database, with committed roots and distinct roles (D50/D55). */
abstract class CompositionTestCase extends TestCase
{
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
        $config->set('billium.modules.enabled', $this->modules());
        foreach (['usage', 'rating', 'schedule', 'documents', 'collection'] as $module) {
            $config->set("billium.{$module}.enabled", in_array($module, $this->modules(), true));
        }

        return [BilliumCoreServiceProvider::class];
    }

    protected function setUp(): void
    {
        TestEnvironment::preflight();
        TestEnvironment::acquireSuiteLease();
        self::resetSchema();
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
        $map = [
            'platform' => 'axiomasoft/billium-core:billium-platform',
            'ledger' => 'axiomasoft/billium-core:billium-ledger',
            'usage' => 'axiomasoft/billium-core:billium-usage',
            'schedule' => 'axiomasoft/billium-core:billium-schedule',
            'rating' => 'axiomasoft/billium-core:billium-rating',
            'documents' => 'axiomasoft/billium-core:billium-documents',
            'execution' => 'axiomasoft/billium-core:billium-execution',
            'collection' => 'axiomasoft/billium-core:billium-collection',
        ];
        $enabled = $this->application()->make(Repository::class)->get('billium.modules.enabled', $this->modules());
        /** @var list<string> $ids */
        $ids = [];
        foreach (is_array($enabled) ? $enabled : $this->modules() as $module) {
            if (is_string($module) && isset($map[$module])) {
                $ids[] = $map[$module];
            }
        }
        $this->application()->make(SchemaMigrator::class)->migrateSets($ids, 'billium_owner');
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
            self::resetSchema();
        }
    }

    private static function resetSchema(): void
    {
        TestEnvironment::assertDestructiveResetAllowed();
        $runtime = '"'.str_replace('"', '""', TestEnvironment::identity()->username).'"';
        TestEnvironment::pdo(owner: true)->exec(<<<SQL
            DROP FUNCTION IF EXISTS
                public.billium_collection_consent_guard_check(),
                public.billium_collection_customer_action_guard(),
                public.billium_collection_recurring_owner_guard(),
                public.billium_collection_dunning_case_guard(),
                public.billium_collection_step_guard(),
                public.billium_lock_collection_dunning_case(bigint[],text,text,boolean),
                public.billium_collection_select_work(text,text,text,text,text,boolean,bigint,integer),
                public.billium_collection_notification_guard()
            CASCADE;
            DROP SCHEMA public CASCADE;
            CREATE SCHEMA public AUTHORIZATION pg_database_owner;
            REVOKE CREATE ON SCHEMA public FROM PUBLIC;
            GRANT USAGE ON SCHEMA public TO PUBLIC;
            GRANT USAGE ON SCHEMA public TO {$runtime};
            ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO {$runtime};
            ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
            SQL);
    }
}

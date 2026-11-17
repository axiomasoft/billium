<?php

declare(strict_types=1);

namespace Billium\Core\Tests;

use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\Support\SimulatedExecutionReadiness;
use Billium\Execution\Internal\ExecutionServiceProvider;
use Billium\Platform\Tests\PlatformTestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Packman\Schema\Contracts\Migration\SchemaMigrator;

/** Committed PostgreSQL roots with execution module admission and an explicitly simulated authority. */
abstract class ExecutionTestCase extends PlatformTestCase
{
    /** @param Application $app */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ExecutionServiceProvider::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->make(Repository::class)->set('billium.modules.enabled', ['platform', 'ledger', 'execution']);
        SimulatedExecutionReadiness::install($app);
    }

    /** @param list<string> $modules */
    protected function spec(
        string $kind = 'ledger.test_effect',
        ?string $key = null,
        ?string $digest = null,
        FinancialMode $mode = FinancialMode::Active,
        ModuleOperationClass $class = ModuleOperationClass::NewIntent,
        array $modules = ['ledger', 'platform'],
        ?string $commit = 'ledger.test_effect',
        ?string $seller = null,
        ?string $environment = null,
        ?FinancialScope $financialScope = null,
    ): OperationSpec {
        if ($financialScope === null && in_array('execution', $modules, true)) {
            $caller = $this->callers->current();
            // The simulated fixture has one known binding. Alternative bindings pass an explicit selection.
            $financialScope = new FinancialScope($caller->installationRef, $seller ?? $caller->sellerRef,
                $environment ?? $caller->environment, 'source-a', 'remote-billium');
        }

        return parent::spec($kind, $key, $digest, $mode, $class, $modules, $commit, $seller, $environment, $financialScope);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->application()->make(SchemaMigrator::class)->migrateSets(
            ['axiomasoft/billium-core:billium-execution'], 'billium_owner',
        );
        $caller = $this->callers->current();
        $this->callers->set(new Caller($caller->sellerRef, $caller->environment, $caller->installationRef,
            $caller->actorRef, ['remote-billium'], ['source-a']));
        $this->activeSession();
    }
}

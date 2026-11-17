<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Registrars;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Plugins\ExecutionPlugin;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Internal\Authority\DispatchAuthority;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Internal\Storage\DatabaseExecutionStore;
use Billium\Platform\Contracts\Installation\Installation;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Schema\Laravel\SchemaExtensions;

#[Internal]
final class ExecutionRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        ExtensionRegistrar::builtIn(ExecutionPlugin::class);
        $app->singletonIf(ExecutionPlugin::class);
        SchemaExtensions::register($app, ExecutionPlugin::class, 'axiomasoft.billium.execution');
        $app->singleton(DatabaseExecutionStore::class, static fn (Application $app): DatabaseExecutionStore => new DatabaseExecutionStore(
            $app->make(DatabaseManager::class),
            $app->make(OperationRunner::class),
            $app->make(CallerContext::class),
            $app->make(BilliumOptions::class),
            $app->make(FinancialSessionAdmission::class),
        ));
        $app->alias(DatabaseExecutionStore::class, ExecutionStore::class);
        $app->singleton(MerchantRegistry::class, DatabaseMerchantRegistry::class);
        $app->singleton(DispatchAuthority::class, static fn (Application $app): DispatchAuthority => new DispatchAuthority(
            $app->make(DatabaseManager::class),
            static fn (): OperationRunner => $app->make(OperationRunner::class),
            static fn (): FinancialSessionAdmission => $app->make(FinancialSessionAdmission::class),
            static fn (): FinancialAuthorityEvidence => $app->make(FinancialAuthorityEvidence::class),
            $app->make(CallerContext::class),
            $app->make(Installation::class),
            $app->make(ExecutionStore::class),
            $app->make(WriterAdmission::class),
            $app->make(DispatchEgress::class),
            $app->make(BilliumOptions::class)->connection ?? $app->make(DatabaseManager::class)->getDefaultConnection(),
        ));
    }

    public function boot(Application $app): void {}
}

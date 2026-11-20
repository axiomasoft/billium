<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Registrars;

use Billium\Collection\Contracts\CollectionConsentGuard;
use Billium\Collection\Contracts\ConsentBindingService;
use Billium\Collection\Contracts\ConsentQueries;
use Billium\Collection\Contracts\DunningCaseService;
use Billium\Collection\Contracts\DunningPolicyService;
use Billium\Collection\Contracts\DunningQueries;
use Billium\Collection\Contracts\SavedMethodCapabilityValidator;
use Billium\Collection\Internal\Queries\DatabaseConsentQueries;
use Billium\Collection\Internal\Queries\DatabaseDunningQueries;
use Billium\Collection\Internal\Services\DatabaseConsentBindingService;
use Billium\Collection\Internal\Services\DatabaseDunningCaseService;
use Billium\Collection\Internal\Services\DatabaseDunningPolicyService;
use Billium\Collection\Internal\UnboundCollectionConsentGuard;
use Billium\Collection\Internal\Validation\DefaultSavedMethodCapabilityValidator;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runtime\CallerContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Override;
use Packman\Attributes\Internal;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Internal]
final class CollectionRuntimeRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->bindIf(CollectionConsentGuard::class, UnboundCollectionConsentGuard::class);
        $app->bind(SavedMethodCapabilityValidator::class, DefaultSavedMethodCapabilityValidator::class);
        $app->bind(ConsentQueries::class, DatabaseConsentQueries::class);
        $app->bind(DunningQueries::class, DatabaseDunningQueries::class);
        $app->bind(DunningPolicyService::class, DatabaseDunningPolicyService::class);
        $app->bind(DunningCaseService::class, DatabaseDunningCaseService::class);
        $app->bind(ConsentBindingService::class, static fn (Application $app): DatabaseConsentBindingService => new DatabaseConsentBindingService(
            databases: $app->make(DatabaseManager::class),
            runner: $app->make(OperationRunner::class),
            callers: $app->make(CallerContext::class),
            options: $app->make(BilliumOptions::class),
            capabilityValidator: $app->make(SavedMethodCapabilityValidator::class),
            hostConsentGuardResolver: static fn (): CollectionConsentGuard => $app->make(CollectionConsentGuard::class),
        ));
    }

    #[Override]
    public function boot(Application $app): void {}
}

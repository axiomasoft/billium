<?php

declare(strict_types=1);

namespace Billium\Core;

use Billium\Core\Laravel\Registrars\BilliumCoreRegistrar;
use Billium\Ledger\Internal\LedgerServiceProvider;
use Billium\Platform\Internal\PlatformServiceProvider;
use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;

/**
 * Slim Laravel service provider bootstrapping Billium Core, Platform, and Ledger registrars.
 */
#[Api(since: '1.0')]
final class BilliumCoreServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        register as protected registerRegistrars;
        boot as protected bootRegistrars;
    }

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        BilliumCoreRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->registerRegistrars();
        $this->app->register(PlatformServiceProvider::class);
        $this->app->register(LedgerServiceProvider::class);
    }

    public function boot(): void
    {
        $this->bootRegistrars();
        $this->loadMigrationsFrom([
            __DIR__.'/../database/migrations/platform',
            __DIR__.'/../database/migrations/ledger',
        ]);
    }
}

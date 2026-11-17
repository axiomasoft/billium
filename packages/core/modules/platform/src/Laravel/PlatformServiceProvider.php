<?php

declare(strict_types=1);

namespace Billium\Platform\Laravel;

use Billium\Platform\Internal\Console\PruneCommand;
use Billium\Platform\Internal\Console\RegisterEventContractsCommand;
use Billium\Platform\Internal\Console\RelayCommand;
use Billium\Platform\Internal\Installation\InitInstallationCommand;
use Billium\Platform\Internal\Registrars\PlatformInstallationRegistrar;
use Billium\Platform\Internal\Registrars\PlatformOutboxRegistrar;
use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;

/**
 * Laravel service provider registering the Billium Platform module runtime and console commands.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class PlatformServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        register as protected registerRegistrars;
        boot as protected bootRegistrars;
    }

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        PlatformOutboxRegistrar::class,
        PlatformInstallationRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->registerRegistrars();
    }

    public function boot(): void
    {
        $this->bootRegistrars();

        if ($this->app->runningInConsole()) {
            $this->commands([
                InitInstallationCommand::class,
                RelayCommand::class,
                PruneCommand::class,
                RegisterEventContractsCommand::class,
            ]);
        }
    }
}

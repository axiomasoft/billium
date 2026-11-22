<?php

declare(strict_types=1);

namespace Billium\Platform\Laravel;

use Billium\Platform\Contracts\Migration\AuthorityHandoff;
use Billium\Platform\Contracts\Migration\AuthorityRouter;
use Billium\Platform\Internal\Console\PruneCommand;
use Billium\Platform\Internal\Console\RegisterEventContractsCommand;
use Billium\Platform\Internal\Console\RelayCommand;
use Billium\Platform\Internal\Installation\InitInstallationCommand;
use Billium\Platform\Internal\Migration\DatabaseAuthorityRouter;
use Billium\Platform\Internal\Migration\HostAuthorityHandoff;
use Billium\Platform\Internal\Registrars\PlatformInstallationRegistrar;
use Billium\Platform\Internal\Registrars\PlatformOutboxRegistrar;
use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
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
        $this->app->bind(AuthorityHandoff::class, HostAuthorityHandoff::class);
        $this->app->bind(AuthorityRouter::class, static fn (Application $app): AuthorityRouter => new DatabaseAuthorityRouter($app->make(DatabaseManager::class)->connection('billium-routing')));
    }

    public function boot(): void
    {
        $this->bootRegistrars();

        if ($this->app->runningInConsole()) {
            $this->app->make(Dispatcher::class)->listen(ArtisanStarting::class, static function (ArtisanStarting $event): void {
                $event->artisan->resolveCommands([
                    InitInstallationCommand::class,
                    RelayCommand::class,
                    PruneCommand::class,
                    RegisterEventContractsCommand::class,
                ]);
            });
        }
    }
}

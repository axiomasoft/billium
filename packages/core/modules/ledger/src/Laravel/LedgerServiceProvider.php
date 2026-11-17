<?php

declare(strict_types=1);

namespace Billium\Ledger\Laravel;

use Billium\Ledger\Internal\Console\DrainParkedCommand;
use Billium\Ledger\Internal\Console\ReconcileRefundsCommand;
use Billium\Ledger\Internal\Registrars\LedgerRuntimeRegistrar;
use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;

/**
 * Laravel service provider registering the Billium Ledger module runtime and console commands.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class LedgerServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        register as protected registerRegistrars;
        boot as protected bootRegistrars;
    }

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        LedgerRuntimeRegistrar::class,
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
                DrainParkedCommand::class,
                ReconcileRefundsCommand::class,
            ]);
        }
    }
}

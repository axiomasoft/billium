<?php

declare(strict_types=1);

namespace Billium\Core;

use Billium\Collection\Laravel\CollectionServiceProvider;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Internal\Runtime\ExecutionCompositionGuard;
use Billium\Core\Laravel\Registrars\BilliumCoreRegistrar;
use Billium\Documents\Laravel\DocumentsServiceProvider;
use Billium\Execution\Laravel\ExecutionServiceProvider;
use Billium\Ledger\Laravel\LedgerServiceProvider;
use Billium\Platform\Laravel\PlatformServiceProvider;
use Billium\Rating\Laravel\RatingServiceProvider;
use Billium\Schedule\Laravel\ScheduleServiceProvider;
use Billium\Usage\Laravel\UsageServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Contracts\Registry\PluginInspector;

#[Api(since: '1.0')]
final class BilliumCoreServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars;

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [BilliumCoreRegistrar::class];

    /** @var array<string, class-string<ServiceProvider>> */
    private const array OPTIONAL_MODULES = [
        'ledger' => LedgerServiceProvider::class, 'execution' => ExecutionServiceProvider::class,
        'usage' => UsageServiceProvider::class, 'schedule' => ScheduleServiceProvider::class,
        'rating' => RatingServiceProvider::class, 'documents' => DocumentsServiceProvider::class,
        'collection' => CollectionServiceProvider::class,
    ];

    #[Override]
    public function register(): void
    {
        if ($this->app->getProviders(self::class) !== []) {
            return;
        }
        $this->registerPackageRegistrars();
        if ($this->app instanceof Application && $this->app->isBooted()) {
            $this->activateModules();
        } else {
            $this->app->booting($this->activateModules(...));
        }
    }

    private function activateModules(): void
    {
        $active = array_column($this->app->make(PluginInspector::class)->describe(), null, 'id');
        if (! isset($active['axiomasoft.billium.platform'])) {
            return;
        }
        $this->app->register(PlatformServiceProvider::class);
        foreach (self::OPTIONAL_MODULES as $module => $providerClass) {
            if (! isset($active['axiomasoft.billium.'.$module])) {
                continue;
            }
            if ($module === 'execution') {
                (new ExecutionCompositionGuard)->assertReady($this->app, $this->app->make(BilliumOptions::class), isset($active['axiomasoft.billium.ledger']));
            }
            $this->app->register($providerClass);
        }
    }

    public function boot(): void
    {
        $this->bootPackageRegistrars();
    }
}

<?php

declare(strict_types=1);

namespace Billium\Core;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Internal\Runtime\ExecutionCompositionGuard;
use Billium\Core\Laravel\Registrars\BilliumCoreRegistrar;
use Billium\Execution\Laravel\ExecutionServiceProvider;
use Billium\Ledger\Laravel\LedgerServiceProvider;
use Billium\Platform\Laravel\PlatformServiceProvider;
use Billium\Rating\Laravel\RatingServiceProvider;
use Billium\Schedule\Laravel\ScheduleServiceProvider;
use Billium\Usage\Laravel\UsageServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Api(since: '1.0')]
final class BilliumCoreServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        register as protected registerRegistrars;
        boot as protected bootRegistrars;
    }

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [BilliumCoreRegistrar::class];

    /** @var array<string, class-string<ServiceProvider>> */
    private const array OPTIONAL_MODULES = [
        'ledger' => LedgerServiceProvider::class,
        'execution' => ExecutionServiceProvider::class,
        'usage' => UsageServiceProvider::class,
        'schedule' => ScheduleServiceProvider::class,
        'rating' => RatingServiceProvider::class,
    ];

    #[Override]
    public function register(): void
    {
        $options = $this->app->bound(BilliumOptions::class) ? $this->app->make(BilliumOptions::class) : null;
        $this->registerRegistrars();
        $this->app->register(PlatformServiceProvider::class);

        if ($options === null) {
            $raw = $this->app->bound(Repository::class) ? $this->app->make(Repository::class)->get('billium', []) : [];
            $options = BilliumOptions::fromArray(is_array($raw) ? $raw : ['__invalid_root' => $raw], 'billium');
        }

        foreach (self::OPTIONAL_MODULES as $module => $providerClass) {
            if (! in_array($module, $options->modules->enabled, true)) {
                continue;
            }
            if ($module === 'execution') {
                (new ExecutionCompositionGuard)->assertReady($this->app, $options);
            }
            $enabled = match ($module) {
                'usage' => $options->usage->enabled,
                'rating' => $options->rating->enabled,
                'schedule' => $options->schedule->enabled,
                default => true,
            };
            if ($enabled) {
                $this->app->register($providerClass);
            }
        }
    }

    public function boot(): void
    {
        $this->bootRegistrars();
    }
}

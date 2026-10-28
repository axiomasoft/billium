<?php

declare(strict_types=1);

namespace Billium\Core;

use Billium\Ledger\Internal\LedgerServiceProvider;
use Billium\Platform\Internal\PlatformServiceProvider;
use Illuminate\Support\ServiceProvider;

final class BilliumCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/billium.php', 'billium');
        $this->app->register(PlatformServiceProvider::class);
        $this->app->register(LedgerServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom([
            __DIR__.'/../database/migrations/platform',
            __DIR__.'/../database/migrations/ledger',
        ]);
    }
}

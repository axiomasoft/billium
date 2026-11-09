<?php

declare(strict_types=1);

namespace Billium\Usage\Internal;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Contracts\CallerContext;
use Billium\Core\Contracts\OperationRunner;
use Billium\Usage\Contracts\Usage;
use Billium\Usage\Contracts\UsageAttribution;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

final class UsageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(UsageAttribution::class, UnresolvedAttribution::class);
        $this->app->bind(Usage::class, static fn (Application $app): DatabaseUsage => new DatabaseUsage(
            databases: $app->make(DatabaseManager::class),
            runner: $app->make(OperationRunner::class),
            callers: $app->make(CallerContext::class),
            attribution: $app->make(UsageAttribution::class),
            codec: new Codec,
        ));
    }

    public function boot(): void
    {
        // D55: discovery only; install/upgrade and module registration are explicit host operations.
        $this->loadMigrationsFrom(__DIR__.'/../../../../database/migrations/usage');
    }
}

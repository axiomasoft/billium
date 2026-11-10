<?php

declare(strict_types=1);

namespace Billium\Execution\Internal;

use Billium\Execution\Contracts\CapabilityGate;
use Illuminate\Support\ServiceProvider;

/** Contract-only composition. No workers, migrations, provider SDK or default mutation authority (D55/D62). */
final class ExecutionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CapabilityGate::class);
    }
}

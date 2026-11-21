<?php

declare(strict_types=1);

namespace Billium\Server;

use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Server\Internal\Http\HttpLimits;
use Billium\Server\Internal\Http\RequestCaller;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final class BilliumServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/billium-server.php', 'billium-server');
        $this->app->singleton(HttpLimits::class, static fn (Application $app): HttpLimits => HttpLimits::fromArray(
            $app->make(Repository::class)->get('billium-server'),
        ));
        $this->app->extend(CallerContext::class, static fn (CallerContext $fallback, Application $app): RequestCaller => new RequestCaller($app, $fallback));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/http.php');
    }
}

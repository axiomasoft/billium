<?php

declare(strict_types=1);

namespace Billium\Server;

use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Server\Internal\Http\RequestCaller;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final class BilliumServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(CallerContext::class, static fn (CallerContext $fallback, Application $app): RequestCaller => new RequestCaller($app, $fallback));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/http.php');
    }
}

<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Support\Concurrency;

use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Tests\TestEnvironment;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Foundation\Application as Testbench;

final class WorkerApplication
{
    public static function create(): Application
    {
        $app = Testbench::create(options: [
            'extra' => ['providers' => [BilliumCoreServiceProvider::class]],
        ]);
        TestEnvironment::configure($app);
        $app['db']->connection('pgsql')->getPdo();

        return $app;
    }
}

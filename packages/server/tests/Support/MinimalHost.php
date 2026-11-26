<?php

declare(strict_types=1);

namespace Billium\Server\Tests\Support;

use Billium\Core\BilliumCoreServiceProvider;
use Billium\Server\BilliumServerServiceProvider;
use BilliumMinimalHost\Host;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\PackageManifest;

final class MinimalHost
{
    public static function load(): void
    {
        require_once self::root().'/tests/minimal-host/server/Database.php';
        require_once self::root().'/tests/minimal-host/server/Host.php';
    }

    /** @param list<string> $modules */
    public static function application(Host $host, string $database, string $profile, array $modules, bool $server = true): Application
    {
        $providers = $server ? [BilliumCoreServiceProvider::class, BilliumServerServiceProvider::class] : [BilliumCoreServiceProvider::class];
        $base = self::root().'/build/server-host-'.getmypid();
        if (! is_dir($base.'/bootstrap/cache')) {
            mkdir($base.'/bootstrap/cache', recursive: true);
            touch($base.'/.env');
        }
        $app = $host->application($base, $database, $profile, $modules, $providers);
        // Root development discovery includes the server. Resolve delivery explicitly for the matrix.
        $app->make(PackageManifest::class)->manifest = [];
        $app->afterBootstrapping(HandleExceptions::class, static function (): void {
            restore_error_handler();
            restore_exception_handler();
        });

        return $app;
    }

    public static function root(): string
    {
        return dirname(__DIR__, 4);
    }
}

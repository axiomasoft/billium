<?php

declare(strict_types=1);

use Billium\Contracts\Contracts\Codec;

/**
 * Loads the test client from the workspace, a pinned archive, or a standalone install.
 */
function billium_client_bootstrap(): void
{
    $autoload = getenv('BILLIUM_AUTOLOAD');
    if (is_string($autoload) && $autoload !== '') {
        require $autoload;
        billium_client_guard_standalone();

        return;
    }

    $archive = getenv('BILLIUM_ARCHIVE_ROOT');
    if (is_string($archive) && $archive !== '') {
        require rtrim($archive, '/').'/bootstrap.php';

        return;
    }

    require dirname(__DIR__, 4).'/vendor/autoload.php';
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Billium\\ClientConformance\\';
        if (! str_starts_with($class, $prefix)) {
            return;
        }
        $path = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) {
            require $path;
        }
    });
}

function billium_client_guard_standalone(): void
{
    $consumer = getenv('BILLIUM_CONSUMER_ROOT');
    $monorepo = getenv('BILLIUM_MONOREPO_ROOT');
    $file = (new ReflectionClass(Codec::class))->getFileName();
    if (! is_string($file)) {
        throw new RuntimeException('standalone codec file is unknown');
    }
    $real = realpath($file);
    if (! is_string($real)) {
        throw new RuntimeException('standalone codec file is unknown');
    }
    if (is_string($consumer) && $consumer !== '' && ! str_starts_with($real, rtrim((string) realpath($consumer), '/').'/')) {
        throw new RuntimeException('standalone codec resolved outside the consumer');
    }
    if (is_string($monorepo) && $monorepo !== '' && str_starts_with($real, rtrim((string) realpath($monorepo), '/').'/')) {
        throw new RuntimeException('standalone codec resolved inside the monorepo');
    }
}

billium_client_bootstrap();

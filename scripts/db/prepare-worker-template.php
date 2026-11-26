<?php

declare(strict_types=1);

use Billium\Core\Tests\Support\Concurrency\WorkerApplication;
use Billium\Core\Tests\TestEnvironment;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

TestEnvironment::provisionWorkerTemplate();
$app = WorkerApplication::create();
$code = $app->make(Kernel::class)->call('packman:schema:migrate', [
    '--scope' => 'central',
    '--database' => 'billium_owner',
    '--modules' => true,
    '--force' => true,
]);
if ($code !== 0) {
    fwrite(STDERR, "worker-template: migrate failed\n");
    exit(1);
}
TestEnvironment::assertRuntimeSafe($app['db']->connection('pgsql')->getPdo());
$count = (int) $app['db']->connection('billium_owner')->scalar('SELECT count(*) FROM public.migrations');
$expected = count(glob(dirname(__DIR__, 2).'/packages/core/database/migrations/*/*.php') ?: []);
if ($count !== $expected) {
    fwrite(STDERR, "worker-template: migration count {$count} != {$expected}\n");
    exit(1);
}
foreach (array_keys($app['db']->getConnections()) as $connection) {
    $app['db']->purge($connection);
}
$app->terminate();
TestEnvironment::sealWorkerTemplate();
fwrite(STDOUT, "worker-template: {$count} migrations\n");

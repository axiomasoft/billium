<?php

declare(strict_types=1);

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\Support\Concurrency\WorkerApplication;

require dirname(__DIR__, 6).'/vendor/autoload.php';

$pdo = null;
$locked = false;
$exit = 0;
try {
    if ($argc !== 5 || ! ctype_digit($argv[2]) || ! ctype_digit($argv[4])) {
        throw new InvalidArgumentException('Expected scenario, worker index, base64 JSON arguments and barrier key');
    }
    $scenario = $argv[1];
    $index = (int) $argv[2];
    $key = (int) $argv[4];
    $json = base64_decode($argv[3], strict: true);
    if ($json === false) {
        throw new InvalidArgumentException('Invalid base64 worker arguments');
    }
    $args = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($args) || ! is_subclass_of($scenario, Scenario::class)) {
        throw new InvalidArgumentException('Invalid scenario or worker arguments');
    }
    $app = WorkerApplication::create();
    $instance = $app->make($scenario, ['index' => $index, 'args' => $args]);
    $pdo = $app['db']->connection('pgsql')->getPdo();
    $barrier = $pdo->prepare('SELECT pg_advisory_lock_shared(?)');
    $barrier->execute([$key]);
    $locked = true;
    try {
        $result = ['index' => $index, 'ok' => $instance->run($app, $index, $args)];
    } catch (ProblemException $exception) {
        $result = ['index' => $index, 'error' => ['code' => $exception->problem->value]];
    }
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    $exit = 1;
} finally {
    if ($pdo !== null && $locked) {
        $unlock = $pdo->prepare('SELECT pg_advisory_unlock_shared(?)');
        $unlock->execute([$key]);
    }
}
exit($exit);

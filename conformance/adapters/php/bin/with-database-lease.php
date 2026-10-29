<?php

declare(strict_types=1);

use Billium\Core\Tests\TestEnvironment;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$command = array_slice($_SERVER['argv'], 1);
if ($command === []) {
    fwrite(STDERR, "Usage: with-database-lease.php COMMAND [ARG ...]\n");
    exit(2);
}

TestEnvironment::preflight();
// Reserve the database before the runner starts its per-vector clock (BC-CNF-12, D50).
$process = new Process($command, dirname(__DIR__, 4), TestEnvironment::suiteLeaseEnvironment(), timeout: null);
exit($process->run(static function (string $type, string $buffer): void {
    fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
}));

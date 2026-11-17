<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$process = new Process([PHP_BINARY, 'vendor/bin/pest', 'packages/core/tests/Drill/ExecutionRestoreTest.php', 'packages/core/tests/Drill/LedgerOnlyRestoreAdmissionTest.php'], $root, timeout: 240);
exit($process->run(static function (string $type, string $output): void {
    fwrite($type === Process::ERR ? STDERR : STDOUT, $output);
}));

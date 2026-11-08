<?php

declare(strict_types=1);

use Billium\Core\Tests\TestEnvironment;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    TestEnvironment::preflight();
    fwrite(STDOUT, "devops:db-test-preflight: PASS\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'devops:db-test-preflight: '.$exception->getMessage()."\n");
    exit(1);
}

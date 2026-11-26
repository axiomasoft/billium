<?php

declare(strict_types=1);

use Billium\Conformance\ConsumerBridge\BridgeReferenceAdapter;

require __DIR__.'/bootstrap.php';

set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, (string) $error."\n");
    exit(1);
});

$adapter = new BridgeReferenceAdapter(dirname(__DIR__, 3));
while (($line = fgets(STDIN)) !== false) {
    $trimmed = trim($line);
    if ($trimmed === '') {
        continue;
    }
    /** @var array<string, mixed> $message */
    $message = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
    if (($message['type'] ?? '') === 'stop') {
        exit(0);
    }
    $reply = $adapter->handle($message);
    fwrite(STDOUT, json_encode($reply, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    fflush(STDOUT);
}

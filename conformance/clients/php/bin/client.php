<?php

declare(strict_types=1);

use Billium\ClientConformance\ConformanceClient;

/*
 * JSON stdin/stdout for the test-only consumer. Public contracts only (D9).
 */

require __DIR__.'/bootstrap.php';

try {
    $message = json_decode(stream_get_contents(STDIN), true, 128, JSON_THROW_ON_ERROR);
    if (! is_array($message)) {
        throw new RuntimeException('client input must be an object');
    }
    $client = new ConformanceClient;
    if (($message['op'] ?? null) === 'batch') {
        $results = [];
        foreach ($message['cases'] ?? [] as $case) {
            if (! is_array($case)) {
                throw new RuntimeException('case must be an object');
            }
            $results[] = $client->execute($case);
        }
        $output = ['results' => $results];
    } else {
        $output = $client->execute($message);
    }
    echo json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage()."\n");
    exit(1);
}

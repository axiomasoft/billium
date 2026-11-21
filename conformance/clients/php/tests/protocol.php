<?php

declare(strict_types=1);

use Billium\ClientConformance\ConformanceClient;

require dirname(__DIR__).'/bin/bootstrap.php';

$failures = 0;

function check(bool $ok, string $detail): void
{
    global $failures;
    if ($ok) {
        return;
    }
    $failures++;
    fwrite(STDERR, $detail."\n");
}

$root = dirname(__DIR__, 4);
$source = file_get_contents(dirname(__DIR__).'/src/ConformanceClient.php');
check(is_string($source) && ! str_contains($source, 'CancelOperation'), 'client must not construct CancelOperation');
foreach (['Billium\\Contracts\\Internal', 'Illuminate\\', 'Billium\\Core\\', 'Billium\\Server\\'] as $forbidden) {
    check(is_string($source) && ! str_contains($source, $forbidden), "client imports {$forbidden}");
}

$client = new ConformanceClient;
$pilot = '{"schema":"payment.execute.v1","commandKey":"7e0c4a52-91d3-4b6f-8a25-c3d1e9f04b87","operationRef":"op-8f3a21","operationKind":"charge","source":{"context":"commerz","orderRef":"ord-10234","intentRef":"int-10234-1"},"installationRef":"inst-eu-1","environment":"test","executionProfile":"remote-billium","routingEpoch":1,"sellerRef":"seller-acme","merchantAccountRef":"ma-main","money":{"amount":"1490.00","currency":"RUB"},"bindingRevision":"bind-7c91d0e2","returnContextRef":"rc-10234","requestedAt":"2026-10-17T12:00:00Z"}';
$golden = rtrim((string) file_get_contents($root.'/fixtures/golden/canon/digest/pilot-execute-canonical.txt'), "\n");
$prepared = $client->execute(['id' => 'pilot', 'op' => 'prepare', 'jsonText' => $pilot]);
check(($prepared['prepared']['bytes'] ?? null) === $golden, 'pilot bytes differ from the P2 golden');
check($prepared['attempts'] === [], 'prepare sent a request');

$cancelled = $client->execute([
    'id' => 'cancel',
    'op' => 'send',
    'jsonText' => $pilot,
    'path' => '/billium/v1/sellers/seller-acme/payments/execute',
    'transport' => [['transport' => 'cancelled-after-send']],
]);
check($cancelled['outcome'] === 'unresolved', 'cancellation was terminal');
check($cancelled['cancelInvoked'] === false, 'cancellation invoked cancel');
check($cancelled['newKey'] === false, 'cancellation minted a key');
check($cancelled['recovery']['commandKey'] === '7e0c4a52-91d3-4b6f-8a25-c3d1e9f04b87', 'recovery lost the command key');
check($cancelled['attempts'][0]['path'] === '/billium/v1/sellers/seller-acme/payments/execute', 'cancel changed the path');

$unknown = $client->execute(['id' => 'unknown', 'op' => 'prepare', 'jsonText' => substr($pilot, 0, -1).',"note":"x"}']);
check($unknown['outcome'] === 'refused' && ($unknown['error']['code'] ?? null) === 'billium.validation.invalid', 'unknown command field was accepted');
check($unknown['attempts'] === [], 'unknown command field was sent');

function field(mixed $value, string $path): mixed
{
    foreach ($path === '' ? [] : explode('.', $path) as $name) {
        if ($name === '#') {
            $value = is_array($value) ? count($value) : throw new RuntimeException('size of a non-array');
        } elseif (is_array($value)) {
            $value = array_is_list($value) ? $value[(int) $name] : $value[$name];
        } else {
            throw new RuntimeException("missing {$path}");
        }
    }

    return $value;
}

foreach (['client', 'source-authority'] as $directory) {
    foreach (glob($root.'/fixtures/integration/http/vectors/'.$directory.'/*.json') ?: [] as $path) {
        $doc = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        if (! is_array($doc) || ($doc['protocol'] ?? null) !== 'billium.client.prepared.v1') {
            continue;
        }
        if (isset($doc['fixture'])) {
            $fixture = json_decode((string) file_get_contents($root.'/'.$doc['fixture']), true, 128, JSON_THROW_ON_ERROR);
            $doc['jsonText'] = field($fixture, (string) $doc['fixturePointer']);
        }
        $result = $client->execute($doc);
        foreach ($doc['expect'] ?? [] as $expectPath => $value) {
            try {
                $found = field($result, (string) $expectPath);
            } catch (RuntimeException) {
                check(false, "{$doc['id']}.{$expectPath}: missing");

                continue;
            }
            check($found === $value && get_debug_type($found) === get_debug_type($value), "{$doc['id']}.{$expectPath}: expected ".json_encode($value).' got '.json_encode($found));
        }
    }
}

if ($failures !== 0) {
    fwrite(STDERR, "protocol failures: {$failures}\n");
    exit(1);
}

echo "protocol php: ok\n";

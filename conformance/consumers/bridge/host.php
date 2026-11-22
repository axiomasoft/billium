<?php

declare(strict_types=1);

use Billium\Conformance\ConsumerBridge\BridgeHost;
use Billium\Conformance\ConsumerBridge\BridgeScenarioActions;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessBarrier;
use Billium\Testkit\Fixtures\ProcessIdentity;
use BilliumMinimalHost\Database;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Request;

require __DIR__.'/bootstrap.php';

set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, (string) $error."\n");
    exit(1);
});

$directory = PHP_SAPI === 'cli-server' ? getenv('BILLIUM_BRIDGE_WORK') : ($argv[2] ?? null);
if (! is_string($directory) || ! is_dir($directory)) {
    throw new RuntimeException('An isolated bridge work directory is required');
}
$stateFile = $directory.'/database.json';

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'create') {
    $profile = $argv[3] ?? '';
    if (! in_array($profile, ['execution', 'ledger-external-executor'], true) || is_file($stateFile)) {
        throw new RuntimeException('Expected a fresh directory and a supported bridge stand profile');
    }
    mkdir($directory.'/bootstrap/cache', 0700, true);
    touch($directory.'/.env');
    $database = Database::create();
    try {
        $host = new BridgeHost($directory, $profile);
        $app = $host->application($database);
        $host->install($app);
        $version = BridgeHost::database($app)->scalar('SELECT version()');
        file_put_contents($stateFile, json_encode([$database, $profile], JSON_THROW_ON_ERROR));
        echo json_encode(['php' => PHP_VERSION, 'postgres' => $version], JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        Database::drop($database);
        throw $error;
    }

    return;
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'refund_contender') {
    $port = (int) $argv[3];
    $idx = (int) $argv[4];
    $originalRef = (string) $argv[5];
    $amount = (string) $argv[6];
    $journal = new DurableJournal($directory.'/ct06-race.journal');
    $barrier = new ProcessBarrier($journal);
    $barrier->arrive('ready');
    $barrier->await('go', 1, 30_000);

    $cmdKey = sprintf('00000000-0000-4100-8800-0000000006%02d', $idx + 10);
    $opRef = sprintf('ct06-race-ref-%d', $idx + 1);
    $payload = json_encode([
        'schema' => 'payment.refund.v1',
        'installationRef' => BridgeHost::INSTALLATION,
        'environment' => 'test',
        'sellerRef' => BridgeHost::SELLER,
        'commandKey' => $cmdKey,
        'operationRef' => $opRef,
        'originalOperationRef' => $originalRef,
        'source' => ['context' => 'checkout', 'intentRef' => 'intent-'.$opRef],
        'money' => ['amount' => $amount, 'currency' => 'RUB'],
        'requestedAt' => '2026-11-22T10:00:00Z',
        'reasonCode' => 'return',
        'category' => 'customer_request',
    ], JSON_THROW_ON_ERROR);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Accept: application/json\r\nContent-Type: application/json\r\nAuthorization: Bearer owner\r\nIdempotency-Key: {$cmdKey}",
            'content' => $payload,
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);
    $raw = (string) @file_get_contents("http://127.0.0.1:{$port}/billium/v1/sellers/seller-a/payments/refund", false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
            $status = (int) $m[1];
        }
    }
    $decoded = json_decode($raw, true);
    $journal->append(new JournalEvent('refund_result', [
        'index' => $idx,
        'status' => $status,
        'code' => is_array($decoded) && is_string($decoded['code'] ?? null) ? $decoded['code'] : '',
    ], ProcessIdentity::current()));

    return;
}

[$database, $profile] = json_decode((string) file_get_contents($stateFile), true, flags: JSON_THROW_ON_ERROR);

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'drop') {
    $candidates = [
        $database,
        is_file($directory.'/original') ? file_get_contents($directory.'/original') : null,
        is_file($directory.'/checkpoint') ? file_get_contents($directory.'/checkpoint') : null,
    ];
    foreach (array_unique(array_filter($candidates, is_string(...))) as $disposable) {
        if ($disposable !== '') {
            Database::drop($disposable);
        }
    }
    unlink($stateFile);

    return;
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'checkpoint') {
    $copy = 'billium_server_'.bin2hex(random_bytes(8)).'_test';
    $config = Database::connection(getenv('DB_DATABASE') ?: 'billium_test', owner: true);
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec('CREATE DATABASE "'.$copy.'" TEMPLATE "'.$database.'"');
    file_put_contents($directory.'/checkpoint', $copy);
    echo '{"checkpoint":true}';

    return;
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'restore_quarantined') {
    $copy = (string) file_get_contents($directory.'/checkpoint');
    file_put_contents($directory.'/original', $database);
    touch($directory.'/attachment-quarantined');
    file_put_contents($stateFile, json_encode([$copy, $profile], JSON_THROW_ON_ERROR), LOCK_EX);
    echo '{"restored":true,"admission":"quarantined"}';

    return;
}

$host = new BridgeHost($directory, $profile);
$app = $host->application($database, writer: ! is_file($directory.'/writer-disabled'));

if (PHP_SAPI === 'cli-server') {
    if (! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        http_response_code(403);

        return;
    }
    $host->admitProcess($app);
    $raceConfigFile = $directory.'/ct06-server-race.json';
    $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    /** @var array<string, int>|null $activeTxMeta */
    $activeTxMeta = null;
    $raceJournal = null;

    if (is_file($raceConfigFile) && $requestPath === '/billium/v1/sellers/seller-a/payments/refund') {
        /** @var array{expectedWorkers?: int} $raceCfg */
        $raceCfg = json_decode((string) file_get_contents($raceConfigFile), true, flags: JSON_THROW_ON_ERROR);
        $expectedServerWorkers = (int) ($raceCfg['expectedWorkers'] ?? 6);
        $raceJournal = new DurableJournal($directory.'/ct06-race.journal');
        $raceBarrier = new ProcessBarrier($raceJournal);
        $syncedOnce = false;

        $runtimeDb = BridgeHost::database($app);
        if ($app->bound('events')) {
            $runtimeDb->setEventDispatcher($app->make('events'));
        }

        $host->setProcessAdmissionTxHook(static function () use (
            $runtimeDb,
            &$syncedOnce,
            &$activeTxMeta,
            $expectedServerWorkers,
            $raceBarrier,
        ): void {
            if ($syncedOnce || $runtimeDb->transactionLevel() < 1) {
                return;
            }
            $syncedOnce = true;
            $row = $runtimeDb->selectOne('SELECT pg_backend_pid() AS backend_pid, txid_current() AS xid');
            $activeTxMeta = [
                'serverPid' => (int) getmypid(),
                'pgBackendPid' => is_object($row) ? (int) $row->backend_pid : 0,
                'xid' => is_object($row) ? (int) $row->xid : 0,
                'enteredNs' => (int) hrtime(true),
            ];
            $raceBarrier->arrive('server_tx_open');
            $raceBarrier->await('server_tx_open', $expectedServerWorkers, 20_000);
        });

        if ($app->bound('events')) {
            $recordTxClose = static function (mixed $event) use (&$activeTxMeta, $raceJournal): void {
                if ($activeTxMeta === null || $event->connection->transactionLevel() !== 0) {
                    return;
                }
                $meta = $activeTxMeta;
                $activeTxMeta = null;
                $meta['exitedNs'] = (int) hrtime(true);
                $raceJournal->append(new JournalEvent('server_refund_tx', $meta, ProcessIdentity::current()));
            };
            $app->make('events')->listen(TransactionCommitted::class, $recordTxClose);
            $app->make('events')->listen(TransactionRolledBack::class, $recordTxClose);
        }
    }

    $kernel = $app->make(Kernel::class);
    $request = Request::capture();
    $response = $kernel->handle($request);
    if ($activeTxMeta !== null && $raceJournal !== null) {
        $meta = $activeTxMeta;
        $activeTxMeta = null;
        $meta['exitedNs'] = (int) hrtime(true);
        $raceJournal->append(new JournalEvent('server_refund_tx', $meta, ProcessIdentity::current()));
    }
    $kernel->terminate($request, $response);
    $response->send();

    return;
}

set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, (string) $error."\n");
    exit(1);
});

$host->admitProcess($app);
$rawInput = stream_get_contents(STDIN);
/** @var array<string, mixed> $input */
$input = is_string($rawInput) && trim($rawInput) !== ''
    ? json_decode($rawInput, true, flags: JSON_THROW_ON_ERROR)
    : [];
$actions = new BridgeScenarioActions($host, $app);
echo json_encode($actions->execute((string) $argv[1], $input), JSON_THROW_ON_ERROR);

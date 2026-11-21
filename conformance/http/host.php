<?php

declare(strict_types=1);

use Billium\Conformance\Http\LoopbackHost;
use BilliumMinimalHost\Database;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/bootstrap.php';

$directory = PHP_SAPI === 'cli-server' ? getenv('BILLIUM_HTTP_WORK') : ($argv[2] ?? null);
if (! is_string($directory) || ! is_dir($directory)) {
    throw new RuntimeException('An isolated work directory is required');
}
$stateFile = $directory.'/database.json';
if (PHP_SAPI === 'cli' && $argv[1] === 'create') {
    $profile = $argv[3] ?? '';
    if (! in_array($profile, ['execution', 'ledger', 'custody'], true) || is_file($stateFile)) {
        throw new RuntimeException('Expected a fresh directory and a supported fixture profile');
    }
    mkdir($directory.'/bootstrap/cache', 0700, true);
    touch($directory.'/.env');
    $database = Database::create();
    try {
        $host = new LoopbackHost($directory, $profile);
        $app = $host->application($database);
        $host->install($app);
        $version = LoopbackHost::database($app)->scalar('SELECT version()');
        file_put_contents($stateFile, json_encode([$database, $profile], JSON_THROW_ON_ERROR));
        echo json_encode(['php' => PHP_VERSION, 'postgres' => $version], JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        Database::drop($database);
        throw $error;
    }

    return;
}

[$database, $profile] = json_decode((string) file_get_contents($stateFile), true, flags: JSON_THROW_ON_ERROR);
if (PHP_SAPI === 'cli' && $argv[1] === 'drop') {
    foreach (array_unique(array_filter([$database, is_file($directory.'/original') ? file_get_contents($directory.'/original') : null,
        is_file($directory.'/checkpoint') ? file_get_contents($directory.'/checkpoint') : null])) as $disposable) {
        Database::drop($disposable);
    }
    unlink($stateFile);

    return;
}
if (PHP_SAPI === 'cli' && $argv[1] === 'checkpoint') {
    if (is_file($directory.'/checkpoint') || preg_match('/^billium_server_[0-9a-f]{16}_test$/D', $database) !== 1) {
        throw new RuntimeException('Expected a disposable source and no prior checkpoint');
    }
    $copy = 'billium_server_'.bin2hex(random_bytes(8)).'_test';
    $config = Database::connection(getenv('DB_DATABASE') ?: 'billium_test', owner: true);
    $pdo = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
        $config['username'], $config['password']);
    $pdo->exec('CREATE DATABASE "'.$copy.'" TEMPLATE "'.$database.'"');
    file_put_contents($directory.'/checkpoint', $copy);
    echo '{"checkpoint":true}';

    return;
}
if (PHP_SAPI === 'cli' && $argv[1] === 'restore') {
    $copy = file_get_contents($directory.'/checkpoint');
    if (! is_string($copy) || preg_match('/^billium_server_[0-9a-f]{16}_test$/D', $copy) !== 1 || $copy === $database) {
        throw new RuntimeException('Expected a disposable checkpoint from this transcript');
    }
    file_put_contents($directory.'/original', $database);
    touch($directory.'/attachment-quarantined');
    file_put_contents($stateFile, json_encode([$copy, $profile], JSON_THROW_ON_ERROR), LOCK_EX);
    echo '{"restored":true,"admission":"quarantined"}';

    return;
}
$host = new LoopbackHost($directory, $profile);
$app = $host->application($database, writer: ! is_file($directory.'/writer-disabled'));
if (PHP_SAPI === 'cli-server') {
    if (! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        http_response_code(403);

        return;
    }
    $host->admitProcess($app);
    $kernel = $app->make(Kernel::class);
    $request = Request::capture();
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $response->send();

    return;
}

set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, (string) $error."\n");
    exit(1);
});
$host->admitProcess($app);
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$result = match ($argv[1]) {
    'snapshot' => $host->snapshot($app),
    'provider' => $host->providerEvidence(),
    'worker' => $host->worker($app, $input['operation'], $input['action'], $input['mode'] ?? 'success'),
    'late_evidence' => $host->lateEvidence($app, $input['operation'], $input['changes']),
    'drain' => $host->drain($app),
    'stop_new' => $host->stopNew($app),
    'deliver' => $host->deliver($app),
    'seed_seller' => $host->seedOtherSeller($app),
    'activate_custody' => $host->activateCustodyStand($app),
    'disable_writer' => touch($directory.'/writer-disabled'),
    default => throw new RuntimeException('Unknown stand action'),
};
echo json_encode($result, JSON_THROW_ON_ERROR);

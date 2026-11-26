<?php

declare(strict_types=1);

use Billium\Collection\Contracts\DunningCaseService;
use Billium\Documents\Contracts\DocumentRenderer;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Server\BilliumServerServiceProvider;
use BilliumMinimalHost\Database;
use BilliumMinimalHost\Host;
use Composer\InstalledVersions;
use Illuminate\Database\DatabaseManager;
use Illuminate\Routing\Router;

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/Database.php';
require __DIR__.'/Host.php';

function verify(bool $value, string $message): void
{
    if (! $value) {
        throw new RuntimeException($message);
    }
}

$vendor = realpath(__DIR__.'/vendor');
$packages = array_values(array_filter(
    InstalledVersions::getInstalledPackages(),
    static fn (string $name): bool => str_starts_with($name, 'axiomasoft/') && InstalledVersions::getInstallPath($name) !== null,
));
sort($packages);
verify($packages === ['axiomasoft/billium-contracts', 'axiomasoft/billium-core', 'axiomasoft/billium-server', 'axiomasoft/packman', 'axiomasoft/packman-plugins', 'axiomasoft/packman-schema'], 'Unexpected Billium/Packman package footprint');
foreach ($packages as $package) {
    $path = InstalledVersions::getInstallPath($package);
    verify(is_string($path) && ! is_link($path) && str_starts_with((string) realpath($path), $vendor.'/'), 'Package is outside the installed archive host');
}

$database = Database::create();
try {
    $host = new Host(bin2hex(random_bytes(24)));
    $app = $host->application(__DIR__, $database);
    verify($host->request($app, '/billium/live')->getStatusCode() === 200, 'Discovered liveness route is unavailable');
    verify(count($app->getProviders(BilliumServerServiceProvider::class)) === 1, 'Composer did not discover the server');
    $db = $app->make(DatabaseManager::class)->connection('server_owner');
    verify($host->request($app, '/billium/ready')->getStatusCode() === 503, 'Empty schema should refuse readiness');
    verify(! $db->getSchemaBuilder()->hasTable('migrations'), 'Boot ran migrations');
    Host::migrate($app, ['platform', 'ledger']);
    verify($host->request($app, '/billium/ready')->getStatusCode() === 503, 'Money readiness must require a host-authorized worker');
    $capabilities = $host->request($app, '/billium/v1/capabilities');
    verify($capabilities->getStatusCode() === 200 && str_contains((string) $capabilities->getContent(), 'billium.capabilities.v1'), 'Packaged server limits or capabilities are missing');
    $query = json_encode([
        'schema' => 'billium.query.v1', 'installationRef' => Host::INSTALLATION, 'environment' => 'test', 'sellerRef' => Host::SELLER,
        'query' => 'obligation', 'selector' => ['obligationRef' => 'missing'],
    ], JSON_THROW_ON_ERROR);
    $found = $host->request($app, '/billium/v1/sellers/'.Host::SELLER.'/queries', $query);
    verify($found->getStatusCode() === 403 && str_contains((string) $found->getContent(), 'billium.auth.denied'), 'Discovered query route did not enforce scoped lookup');
    foreach ([AttemptWorker::class, DunningCaseService::class, WriterAdmission::class, PaymentDrivers::class, DocumentRenderer::class] as $port) {
        verify(! $app->bound($port), 'Disabled module provided '.$port);
    }
    verify(! $app->make(Router::class)->getRoutes()->hasNamedRoute('billium.server.execution.execute'), 'Disabled execution contributed a route');
    verify(! $db->getSchemaBuilder()->hasTable('billium_execution_operation'), 'Disabled execution installed a table');
    fwrite(STDOUT, "smoke server ledger-only archive: ok\n");
} finally {
    Database::drop($database);
}

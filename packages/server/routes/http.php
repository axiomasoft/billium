<?php

declare(strict_types=1);

use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Server\Internal\Http\ExecutionController;
use Billium\Server\Internal\Http\HealthController;
use Billium\Server\Internal\Http\LedgerController;
use Billium\Server\Internal\Readiness;
use Illuminate\Routing\Router;

$router = $this->app->make(Router::class);
$composition = $this->app->make(Readiness::class);

$router->get('/billium/live', [HealthController::class, 'live'])->name('billium.server.live');
$router->get('/billium/ready', [HealthController::class, 'ready'])->name('billium.server.ready');

if ($this->app->bound(LedgerQueries::class)) {
    $router->post('/billium/v1/sellers/{seller}/query', [LedgerController::class, 'query'])->name('billium.server.ledger.query');
    if ($composition->acceptsObservations()) {
        $router->post('/billium/v1/sellers/{seller}/observations', [LedgerController::class, 'observe'])->name('billium.server.ledger.observations');
    }
}

if ($composition->hasExecutionDelivery()) {
    $router->post('/billium/v1/sellers/{seller}/commands', [ExecutionController::class, 'command'])->name('billium.server.execution.commands');
    $router->post('/billium/v1/sellers/{seller}/find', [ExecutionController::class, 'find'])->name('billium.server.execution.find');
}

$router->getRoutes()->refreshNameLookups();

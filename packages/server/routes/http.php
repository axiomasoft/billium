<?php

declare(strict_types=1);

use Billium\Collection\Contracts\DunningCaseService;
use Billium\Execution\Contracts\AuthorizationCommands;
use Billium\Execution\Contracts\ExecutionQueries;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Contracts\ObservationApplier;
use Billium\Platform\Contracts\Feed\EventFeed;
use Billium\Platform\Contracts\Idempotency\IdempotencyReceiptQueries;
use Billium\Rating\Contracts\RatingBooking;
use Billium\Schedule\Contracts\OccurrenceService;
use Billium\Server\Internal\Http\CapabilitiesController;
use Billium\Server\Internal\Http\ExecutionController;
use Billium\Server\Internal\Http\HealthController;
use Billium\Server\Internal\Http\LedgerController;
use Billium\Server\Internal\Http\ModuleCommandsController;
use Billium\Server\Internal\Http\RecoveryController;
use Billium\Server\Internal\Readiness;
use Billium\Usage\Contracts\Usage;
use Illuminate\Routing\Router;

$router = $this->app->make(Router::class);
$composition = $this->app->make(Readiness::class);

$router->get('/billium/live', [HealthController::class, 'live'])->name('billium.server.live');
$router->get('/billium/ready', [HealthController::class, 'ready'])->name('billium.server.ready');
$router->get('/billium/v1/capabilities', [CapabilitiesController::class, 'show'])->name('billium.server.capabilities');

if ($this->app->bound(LedgerQueries::class)) {
    $router->post('/billium/v1/sellers/{sellerRef}/queries', [LedgerController::class, 'query'])->name('billium.server.ledger.query');
}
if ($composition->acceptsObservations() && $this->app->bound(ObservationApplier::class)) {
    $router->post('/billium/v1/sellers/{sellerRef}/observations', [LedgerController::class, 'observe'])->name('billium.server.ledger.observations');
}
if ($this->app->bound(Ledger::class)) {
    $router->post('/billium/v1/sellers/{sellerRef}/allocations/reallocate', [LedgerController::class, 'reallocate'])->name('billium.server.ledger.reallocate');
}

if ($composition->hasExecutionDelivery()) {
    $router->post('/billium/v1/sellers/{sellerRef}/payments/execute', [ExecutionController::class, 'execute'])->name('billium.server.execution.execute');
    $router->post('/billium/v1/sellers/{sellerRef}/payments/authorize', [ExecutionController::class, 'authorize'])->name('billium.server.execution.authorize');
    $router->post('/billium/v1/sellers/{sellerRef}/operations/{operationRef}/cancel', [ExecutionController::class, 'cancel'])->name('billium.server.execution.cancel');
    if ($this->app->bound(RefundCommands::class)) {
        $router->post('/billium/v1/sellers/{sellerRef}/payments/refund', [ExecutionController::class, 'refund'])->name('billium.server.execution.refund');
    }
    if ($this->app->bound(AuthorizationCommands::class)) {
        $router->post('/billium/v1/sellers/{sellerRef}/payments/capture', [ExecutionController::class, 'capture'])->name('billium.server.execution.capture');
        $router->post('/billium/v1/sellers/{sellerRef}/payments/void-authorization', [ExecutionController::class, 'voidAuthorization'])->name('billium.server.execution.void-authorization');
    }
}
if ($this->app->bound(ExecutionQueries::class)) {
    $router->post('/billium/v1/sellers/{sellerRef}/operations/find', [ExecutionController::class, 'find'])->name('billium.server.execution.find');
}

if ($this->app->bound(RatingBooking::class)) {
    $router->post('/billium/v1/sellers/{sellerRef}/obligations/prepare', [ModuleCommandsController::class, 'prepare'])->name('billium.server.rating.prepare');
}
if ($this->app->bound(OccurrenceService::class)) {
    $router->post('/billium/v1/sellers/{sellerRef}/occurrences/ensure', [ModuleCommandsController::class, 'ensure'])->name('billium.server.schedule.ensure');
}
if ($this->app->bound(DunningCaseService::class)) {
    $router->post('/billium/v1/sellers/{sellerRef}/collection/authorize', [ModuleCommandsController::class, 'authorize'])->name('billium.server.collection.authorize');
}
if ($this->app->bound(Usage::class)) {
    $router->post('/billium/v1/sellers/{sellerRef}/usage/facts', [ModuleCommandsController::class, 'usage'])->name('billium.server.usage.accept');
}
if ($this->app->bound(IdempotencyReceiptQueries::class)) {
    $router->post('/billium/v1/sellers/{sellerRef}/receipts/find', [RecoveryController::class, 'findReceipt'])->name('billium.server.receipts.find');
}
if ($this->app->bound(EventFeed::class)) {
    $router->post('/billium/v1/sellers/{sellerRef}/feed/open', [RecoveryController::class, 'openFeed'])->name('billium.server.feed.open');
    $router->post('/billium/v1/sellers/{sellerRef}/feed/read', [RecoveryController::class, 'readFeed'])->name('billium.server.feed.read');
    $router->post('/billium/v1/sellers/{sellerRef}/feed/bootstrap', [RecoveryController::class, 'bootstrapFeed'])->name('billium.server.feed.bootstrap');
}

$router->getRoutes()->refreshNameLookups();

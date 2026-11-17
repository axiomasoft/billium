<?php

declare(strict_types=1);

use Billium\Core\Tests\Support\Concurrency\WorkerApplication;
use Billium\Schedule\Contracts\OccurrenceService;
use Billium\Schedule\Data\Commands\MaterializeNext;
use Billium\Schedule\Tests\Support\OccurrenceScenario;

require dirname(__DIR__, 6).'/vendor/autoload.php';

$app = WorkerApplication::create();
OccurrenceScenario::prepare($app);
$app->make(OccurrenceService::class)->materializeNext(new MaterializeNext(sellerRef: 'seller-a', environment: 'test', scheduleRef: 'schedule-a',
    expectedRowVersion: 1, expectedCancelGeneration: 0, asOf: new DateTimeImmutable('2024-03-01Z'), commandKey: 'crash-restart'));

<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Support;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\CallerContext;
use Billium\Core\Contracts\FinancialSessionAdmission;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Schedule\Contracts\OccurrenceService;
use Billium\Schedule\Data\Commands\ApplyScheduleRevision;
use Billium\Schedule\Data\Commands\CancelSchedule;
use Billium\Schedule\Data\Commands\EnsureOccurrence;
use Billium\Schedule\Data\Commands\MaterializeNext;
use Billium\Schedule\Enums\OccurrencePurpose;
use Billium\Schedule\Internal\ScheduleServiceProvider;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;

final class OccurrenceScenario implements Scenario
{
    public function run(Application $app, int $index, array $args): array
    {
        self::prepare($app);
        $service = $app->make(OccurrenceService::class);
        $pid = $app->make(DatabaseManager::class)->connection('pgsql')->scalar('SELECT pg_backend_pid()');
        try {
            $kind = $args['kind'] ?? 'ensure';
            if ($kind === 'race') {
                $kind = ['cancel', 'revision', 'materialize'][$index % 3];
            }
            $command = match ($kind) {
                'cancel' => new CancelSchedule(sellerRef: 'seller-a', environment: 'test', scheduleRef: 'schedule-a', expectedRevision: 1, expectedRowVersion: 1, expectedCancelGeneration: 0, commandKey: 'cancel-'.$index),
                'revision' => new ApplyScheduleRevision(sellerRef: 'seller-a', environment: 'test', scheduleRef: 'schedule-a', expectedRevision: 1, expectedRowVersion: 1, newRevision: 2, terms: Terms::make(['purpose' => 'recurring_charge', 'anchorLocal' => '2024-02-07T00:00:00']), effectiveFrom: new DateTimeImmutable('2024-01-01Z'), inputsDigest: 'sha256:'.str_repeat('a', 64), commandKey: 'worker-revision-'.$index),
                'materialize' => new MaterializeNext(sellerRef: 'seller-a', environment: 'test', scheduleRef: 'schedule-a', expectedRowVersion: 1, expectedCancelGeneration: 0, asOf: new DateTimeImmutable('2024-03-01Z'), commandKey: 'materialize-'.$index),
                default => new EnsureOccurrence(sellerRef: 'seller-a', environment: 'test', componentRef: 'component-a', purpose: OccurrencePurpose::RecurringCharge, periodStart: new DateTimeImmutable('2024-01-31Z'), periodEnd: new DateTimeImmutable('2024-02-29Z'), scheduleRef: 'schedule-a', scheduleRevision: 1, inputsDigest: 'sha256:'.str_repeat('a', 64), commandKey: 'worker-'.$index),
            };
            $receipt = match ($kind) {
                'cancel' => $service->cancelSchedule($command),
                'revision' => $service->applyScheduleRevision($command),
                'materialize' => $service->materializeNext($command),
                default => $service->ensureOccurrence($command),
            };

            return ['accepted' => true, 'body' => $receipt->body(), 'pid' => $pid];
        } catch (ProblemException $e) {
            return ['accepted' => false, 'detail' => $e->detail, 'pid' => $pid];
        }
    }

    public static function prepare(Application $app): void
    {
        $app->instance(CallerContext::class, FixedCallerContext::default());
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token()));
        $app->register(ScheduleServiceProvider::class);
    }
}

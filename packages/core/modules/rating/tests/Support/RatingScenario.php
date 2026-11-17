<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Support;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Core\Tests\Support\FixtureValues;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Rating\Contracts\RatingBooking;
use Billium\Rating\Data\Rating\BookRatedBasis;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Internal\RatingServiceProvider;
use Billium\Rating\Internal\Storage\FrozenInputsCodec;
use Billium\Schedule\Contracts\OccurrenceService;
use Billium\Schedule\Data\Commands\ApplyScheduleRevision;
use Billium\Schedule\Internal\ScheduleServiceProvider;
use Billium\Schedule\Tests\Support\Terms;
use Billium\Usage\Internal\UsageServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;

final class RatingScenario implements Scenario
{
    public static function prepare(Application $app): void
    {
        $app->instance(CallerContext::class, FixedCallerContext::default());
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token()));
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);
        $app->make(Repository::class)->set('billium.modules.enabled', ['platform', 'ledger', 'usage', 'rating', 'schedule']);
        $app->register(UsageServiceProvider::class);
        $app->register(RatingServiceProvider::class);
        $app->register(ScheduleServiceProvider::class);
    }

    public function run(Application $app, int $index, array $args): array
    {
        self::prepare($app);
        if (($args['revise'] ?? false) && $index < 2) {
            try {
                $app->make(OccurrenceService::class)->applyScheduleRevision(new ApplyScheduleRevision(sellerRef: 'seller-a', environment: 'test', scheduleRef: 'schedule-a', expectedRevision: 1, expectedRowVersion: 1, newRevision: 2, terms: Terms::make(['componentRef' => 'component-1', 'anchorLocal' => '2026-10-15T00:00:00', 'purpose' => 'usage_settlement']), effectiveFrom: new \DateTimeImmutable('2026-01-01Z'), inputsDigest: 'sha256:'.str_repeat('b', 64), commandKey: 'revision-worker-'.$index));

                return ['accepted' => true, 'action' => 'revision'];
            } catch (ProblemException $error) {
                return ['accepted' => false, 'action' => 'revision', 'detail' => $error->detail];
            }
        }
        $in = FrozenInputsCodec::decode(FixtureValues::text($args['inputs']));
        if (($args['stale'] ?? false) && $index > 0) {
            $in = FixtureArguments::construct(class: FrozenRatingInputs::class, arguments: [...get_object_vars($in), 'sourceDigest' => 'competing-source-frontier']);
        }
        $prepared = new PreparedRating(inputs: $in, draft: $app->make(RatingComputation::class)->compute($in));
        try {
            $receipt = $app->make(RatingBooking::class)->book(new BookRatedBasis(prepared: $prepared, operationKey: 'worker-'.$index));

            return ['accepted' => true, 'body' => $receipt->body(), 'pid' => $app->make(DatabaseManager::class)->connection('pgsql')->scalar('SELECT pg_backend_pid()')];
        } catch (ProblemException $error) {
            return ['accepted' => false, 'detail' => $error->detail];
        }
    }
}

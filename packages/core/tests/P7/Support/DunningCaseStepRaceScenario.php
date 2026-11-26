<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P7\Support;

use Billium\Collection\Contracts\DunningCaseService;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\PlanCollectionStep;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Application;

final class DunningCaseStepRaceScenario implements Scenario
{
    /** @param array<string, mixed> $args */
    public function run(Application $app, int $index, array $args): array
    {
        ConsentGuardRaceScenario::prepare($app);

        /** @var DunningCaseService $service */
        $service = $app->make(DunningCaseService::class);
        $caseRef = (string) ($args['caseRef'] ?? 'case-race-1');
        $expectedCaseRevision = isset($args['expectedCaseRevision']) && is_int($args['expectedCaseRevision'])
            ? $args['expectedCaseRevision']
            : 1;

        try {
            $step = $service->planStep(new PlanCollectionStep(
                commandKey: 'race-step-'.$index,
                scope: new CollectionScope('seller-a', 'test'),
                caseRef: $caseRef,
                stepRef: 'step-race-'.$index,
                stepNo: 1,
                businessPaymentIntentRef: 'bpi-race-'.$index,
                operationRef: 'op-race-'.$index,
                amountMinor: 5000,
                scheduledAt: new DateTimeImmutable('2026-11-20T01:00:00Z', new DateTimeZone('UTC')),
                expectedCaseRevision: $expectedCaseRevision,
            ));

            return [
                'accepted' => true,
                'stepId' => $step->id,
                'stepRef' => $step->stepRef,
                'stepNo' => $step->stepNo,
            ];
        } catch (ProblemException $error) {
            return [
                'accepted' => false,
                'problem' => $error->problem->value,
                'detail' => $error->detail,
            ];
        }
    }
}

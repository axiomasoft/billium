<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P7\Support;

use Billium\Collection\Contracts\CollectionManualInterventionService;
use Billium\Collection\Data\ApplyManualIntervention;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Enums\ManualOverrideAction;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\Support\FixedCallerContext;
use Illuminate\Foundation\Application;

final class ManualInterventionRaceScenario implements Scenario
{
    /** @param array<string, mixed> $args */
    public function run(Application $app, int $index, array $args): array
    {
        ConsentGuardRaceScenario::prepare($app);

        $base = FixedCallerContext::default()->current();
        $app->instance(CallerContext::class, new FixedCallerContext(new Caller(
            $base->sellerRef,
            $base->environment,
            $base->installationRef,
            'operator-'.$index,
            ['remote-billium'],
            ['source-a'],
            [CollectionManualInterventionService::PRIVILEGE_MANUAL_OVERRIDE.':'.$base->sellerRef],
        )));

        /** @var CollectionManualInterventionService $service */
        $service = $app->make(CollectionManualInterventionService::class);
        $caseRef = (string) ($args['caseRef'] ?? 'case-race-override');
        $expectedCaseRevision = isset($args['expectedCaseRevision']) && is_int($args['expectedCaseRevision'])
            ? $args['expectedCaseRevision']
            : 1;

        try {
            $receipt = $service->applyOverride(new ApplyManualIntervention(
                commandKey: 'race-override-cmd-'.$index,
                interventionRef: 'race-int-'.$index,
                scope: new CollectionScope('seller-a', 'test'),
                caseRef: $caseRef,
                action: ManualOverrideAction::PauseCase,
                expectedCaseRevision: $expectedCaseRevision,
                reason: 'Concurrent operator pause request '.$index,
                recoveryProofRef: 'ticket://ops/race-'.$index,
                recoveryProofDigest: 'sha256:'.str_repeat('d', 64),
            ));

            return [
                'accepted' => true,
                'interventionRef' => $receipt->interventionRef,
                'toCaseRevision' => $receipt->toCaseRevision,
                'afterStatus' => $receipt->afterStatus->value,
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

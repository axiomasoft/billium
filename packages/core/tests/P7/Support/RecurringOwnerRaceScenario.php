<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P7\Support;

use Billium\Collection\Contracts\DunningPolicyService;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\RegisterRecurringOwner;
use Billium\Collection\Enums\RecurringOwnerMode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Illuminate\Foundation\Application;

final class RecurringOwnerRaceScenario implements Scenario
{
    /** @param array<string, mixed> $args */
    public function run(Application $app, int $index, array $args): array
    {
        ConsentGuardRaceScenario::prepare($app);

        /** @var DunningPolicyService $service */
        $service = $app->make(DunningPolicyService::class);
        $componentRef = (string) ($args['componentRef'] ?? 'comp-race-1');
        $agreementRef = (string) ($args['agreementRef'] ?? 'agr-race-1');
        $mode = $index % 2 === 0
            ? RecurringOwnerMode::BilliumScheduler
            : RecurringOwnerMode::ProviderAutonomousSubscription;

        try {
            $owner = $service->registerRecurringOwner(new RegisterRecurringOwner(
                commandKey: 'race-owner-'.$index,
                scope: new CollectionScope('seller-a', 'test'),
                agreementRef: $agreementRef,
                componentRef: $componentRef,
                ownerMode: $mode,
                ownerRef: $mode === RecurringOwnerMode::BilliumScheduler ? 'sched-race-'.$index : 'prov-sub-race-'.$index,
            ));

            return [
                'accepted' => true,
                'ownerId' => $owner->id,
                'ownerMode' => $owner->ownerMode->value,
                'ownerRevision' => $owner->ownerRevision,
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

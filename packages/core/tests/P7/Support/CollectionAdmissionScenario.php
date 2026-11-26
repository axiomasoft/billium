<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P7\Support;

use Billium\Collection\Contracts\CollectionConsentGuard;
use Billium\Collection\Contracts\DunningCaseService;
use Billium\Collection\Data\AuthorizeCollection;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\HostConsentAuthoritySnapshot;
use Billium\Collection\Data\PlanCollectionStep;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use DateTimeImmutable;
use Illuminate\Foundation\Application;

final class CollectionAdmissionScenario implements CollectionConsentGuard, Scenario
{
    public function currentAuthority(CollectionScope $scope, string $billingAccountRef, string $consentRef): HostConsentAuthoritySnapshot
    {
        return new HostConsentAuthoritySnapshot($scope->sellerRef, $scope->environment, $billingAccountRef, $consentRef,
            1, 1, 0, true, null, 'sha256:'.str_repeat('a', 64), new DateTimeImmutable);
    }

    public static function command(string $id = 'a', string $case = 'case-a', int $amount = 5000, ?DateTimeImmutable $scheduled = null): AuthorizeCollection
    {
        $scope = Fixture::scope();

        return new AuthorizeCollection(
            new PlanCollectionStep('authorize-'.$id, new CollectionScope($scope->sellerRef, $scope->environment), $case,
                'step-'.$id, 1, 'intent-'.$id, 'root-'.$id, $amount, $scheduled ?? new DateTimeImmutable('2026-01-01T00:00:00Z')),
            $scope, 'consent-1', 1, 1, 'remote-billium',
        );
    }

    public function run(Application $app, int $index, array $args): array
    {
        ConsentGuardRaceScenario::prepare($app);
        $app->instance(CollectionConsentGuard::class, $this);
        try {
            $id = ($args['replaySameCommand'] ?? false) === true ? 'a' : 'worker-'.$index;
            $step = $app->make(DunningCaseService::class)->authorizeCollection(self::command($id));

            return ['accepted' => true, 'id' => $step->id];
        } catch (ProblemException $e) {
            return ['accepted' => false, 'problem' => $e->problem->value];
        }
    }
}

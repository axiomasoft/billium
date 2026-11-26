<?php

declare(strict_types=1);

namespace Billium\Collection\Contracts;

use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\CollectionStepView;
use Billium\Collection\Data\DunningCasePolicyMigrationView;
use Billium\Collection\Data\DunningCaseView;
use Billium\Collection\Data\DunningPolicyView;
use Billium\Collection\Data\RecurringOwnerView;
use Packman\Attributes\Api;

/**
 * Seller- and environment-scoped read queries for DunningPolicy, recurring ownership, dunning_case, and collection_step.
 */
#[Api(since: '1.0')]
interface DunningQueries
{
    public function findPolicyVersion(CollectionScope $scope, string $policyRef, int $policyVersion): ?DunningPolicyView;

    public function findLatestPolicyVersion(CollectionScope $scope, string $policyRef): ?DunningPolicyView;

    /** @return list<DunningPolicyView> */
    public function listPolicyVersions(CollectionScope $scope, string $policyRef): array;

    public function findActiveRecurringOwner(CollectionScope $scope, string $componentRef): ?RecurringOwnerView;

    public function findCase(CollectionScope $scope, string $caseRef): ?DunningCaseView;

    public function findCaseByObligation(CollectionScope $scope, string $obligationRef, int $collectionGeneration): ?DunningCaseView;

    /** @return list<CollectionStepView> */
    public function listCaseSteps(CollectionScope $scope, string $caseRef): array;

    public function findStep(CollectionScope $scope, string $stepRef): ?CollectionStepView;

    /** @return list<DunningCasePolicyMigrationView> */
    public function listCasePolicyMigrations(CollectionScope $scope, string $caseRef): array;
}

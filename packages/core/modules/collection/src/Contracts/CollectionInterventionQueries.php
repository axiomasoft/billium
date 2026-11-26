<?php

declare(strict_types=1);

namespace Billium\Collection\Contracts;

use Billium\Collection\Data\CollectionHostSignalView;
use Billium\Collection\Data\CollectionNotificationView;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\ManualInterventionReceipt;
use Billium\Collection\Enums\NotificationChannel;
use Packman\Attributes\Api;

/**
 * Seller- and environment-scoped read queries for collection notifications, host signals, and manual interventions.
 */
#[Api(since: '1.0')]
interface CollectionInterventionQueries
{
    public function findNotification(CollectionScope $scope, string $messageId): ?CollectionNotificationView;

    public function findNotificationByKey(
        CollectionScope $scope,
        string $caseRef,
        int $stepNo,
        string $templateRef,
        string $templateRevision,
        NotificationChannel $channel,
    ): ?CollectionNotificationView;

    /** @return list<CollectionNotificationView> */
    public function listCaseNotifications(CollectionScope $scope, string $caseRef): array;

    /** @return list<CollectionHostSignalView> */
    public function listCaseSignals(CollectionScope $scope, string $caseRef): array;

    public function findManualIntervention(CollectionScope $scope, string $interventionRef): ?ManualInterventionReceipt;

    /** @return list<ManualInterventionReceipt> */
    public function listCaseManualInterventions(CollectionScope $scope, string $caseRef): array;
}

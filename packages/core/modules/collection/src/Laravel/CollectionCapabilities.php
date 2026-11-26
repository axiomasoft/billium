<?php

declare(strict_types=1);

namespace Billium\Collection\Laravel;

use Billium\Collection\Contracts\CollectionConsentGuard;
use Billium\Collection\Contracts\CollectionInterventionQueries;
use Billium\Collection\Contracts\CollectionManualInterventionService;
use Billium\Collection\Contracts\CollectionNotificationPort;
use Billium\Collection\Contracts\CollectionNotificationService;
use Billium\Collection\Contracts\CollectionSignalService;
use Billium\Collection\Contracts\ConsentBindingService;
use Billium\Collection\Contracts\ConsentQueries;
use Billium\Collection\Contracts\DunningCaseService;
use Billium\Collection\Contracts\DunningPolicyService;
use Billium\Collection\Contracts\DunningQueries;
use Billium\Collection\Contracts\SavedMethodCapabilityValidator;
use Billium\Collection\Internal\Queries\DatabaseCollectionInterventionQueries;
use Billium\Collection\Internal\Queries\DatabaseConsentQueries;
use Billium\Collection\Internal\Queries\DatabaseDunningQueries;
use Billium\Collection\Internal\Services\DatabaseCollectionManualInterventionService;
use Billium\Collection\Internal\Services\DatabaseCollectionNotificationService;
use Billium\Collection\Internal\Services\DatabaseCollectionSignalService;
use Billium\Collection\Internal\Services\DatabaseConsentBindingService;
use Billium\Collection\Internal\Services\DatabaseDunningCaseService;
use Billium\Collection\Internal\Services\DatabaseDunningPolicyService;
use Billium\Collection\Internal\UnboundCollectionConsentGuard;
use Billium\Collection\Internal\UnboundCollectionNotificationPort;
use Billium\Collection\Internal\Validation\DefaultSavedMethodCapabilityValidator;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Capability declarations provided by the Billium Collection module.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class CollectionCapabilities
{
    /** @return array<class-string, class-string> */
    public static function map(): array
    {
        return [
            CollectionConsentGuard::class => UnboundCollectionConsentGuard::class,
            CollectionNotificationPort::class => UnboundCollectionNotificationPort::class,
            SavedMethodCapabilityValidator::class => DefaultSavedMethodCapabilityValidator::class,
            ConsentBindingService::class => DatabaseConsentBindingService::class,
            ConsentQueries::class => DatabaseConsentQueries::class,
            DunningPolicyService::class => DatabaseDunningPolicyService::class,
            DunningCaseService::class => DatabaseDunningCaseService::class,
            DunningQueries::class => DatabaseDunningQueries::class,
            CollectionNotificationService::class => DatabaseCollectionNotificationService::class,
            CollectionSignalService::class => DatabaseCollectionSignalService::class,
            CollectionManualInterventionService::class => DatabaseCollectionManualInterventionService::class,
            CollectionInterventionQueries::class => DatabaseCollectionInterventionQueries::class,
        ];
    }

    /** @return class-string */
    public static function unboundConsentGuard(): string
    {
        return UnboundCollectionConsentGuard::class;
    }
}

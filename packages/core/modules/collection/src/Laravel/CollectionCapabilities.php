<?php

declare(strict_types=1);

namespace Billium\Collection\Laravel;

use Billium\Collection\Contracts\CollectionConsentGuard;
use Billium\Collection\Contracts\ConsentBindingService;
use Billium\Collection\Contracts\ConsentQueries;
use Billium\Collection\Contracts\DunningCaseService;
use Billium\Collection\Contracts\DunningPolicyService;
use Billium\Collection\Contracts\DunningQueries;
use Billium\Collection\Contracts\SavedMethodCapabilityValidator;
use Billium\Collection\Internal\Queries\DatabaseConsentQueries;
use Billium\Collection\Internal\Queries\DatabaseDunningQueries;
use Billium\Collection\Internal\Services\DatabaseConsentBindingService;
use Billium\Collection\Internal\Services\DatabaseDunningCaseService;
use Billium\Collection\Internal\Services\DatabaseDunningPolicyService;
use Billium\Collection\Internal\UnboundCollectionConsentGuard;
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
            SavedMethodCapabilityValidator::class => DefaultSavedMethodCapabilityValidator::class,
            ConsentBindingService::class => DatabaseConsentBindingService::class,
            ConsentQueries::class => DatabaseConsentQueries::class,
            DunningPolicyService::class => DatabaseDunningPolicyService::class,
            DunningCaseService::class => DatabaseDunningCaseService::class,
            DunningQueries::class => DatabaseDunningQueries::class,
        ];
    }

    /** @return class-string */
    public static function unboundConsentGuard(): string
    {
        return UnboundCollectionConsentGuard::class;
    }
}

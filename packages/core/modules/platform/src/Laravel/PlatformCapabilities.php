<?php

declare(strict_types=1);

namespace Billium\Platform\Laravel;

use Billium\Core\Contracts\Modules\ModuleStateReader;
use Billium\Platform\Contracts\EventContracts\EventContractRegistrar;
use Billium\Platform\Contracts\Feed\EventFeed;
use Billium\Platform\Contracts\Feed\FeedPartitions;
use Billium\Platform\Contracts\Feed\FeedRecoveryQueries;
use Billium\Platform\Contracts\Idempotency\IdempotencyStore;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Platform\Contracts\Installation\RootAdmission;
use Billium\Platform\Contracts\Outbox\CommandOutbox;
use Billium\Platform\Contracts\Outbox\Outbox;
use Billium\Platform\Internal\CommandOutbox\CommandRecorder;
use Billium\Platform\Internal\EventContracts\DatabaseEventContractRegistrar;
use Billium\Platform\Internal\Feed\DatabaseFeedPartitions;
use Billium\Platform\Internal\Feed\DatabaseFeedRecoveryQueries;
use Billium\Platform\Internal\Idempotency\DatabaseIdempotencyStore;
use Billium\Platform\Internal\Installation\DatabaseInstallation;
use Billium\Platform\Internal\Installation\PlatformRootAdmission;
use Billium\Platform\Internal\Outbox\EventFeedReader;
use Billium\Platform\Internal\Outbox\OutboxRecorder;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Capability bindings declared by the Billium Platform module for Core plugin wiring.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class PlatformCapabilities
{
    /** @return array<class-string, class-string> */
    public static function map(): array
    {
        return [
            ModuleStateReader::class => DatabaseInstallation::class,
            Installation::class => DatabaseInstallation::class,
            IdempotencyStore::class => DatabaseIdempotencyStore::class,
            Outbox::class => OutboxRecorder::class,
            CommandOutbox::class => CommandRecorder::class,
            EventFeed::class => EventFeedReader::class,
            FeedPartitions::class => DatabaseFeedPartitions::class,
            FeedRecoveryQueries::class => DatabaseFeedRecoveryQueries::class,
            RootAdmission::class => PlatformRootAdmission::class,
            EventContractRegistrar::class => DatabaseEventContractRegistrar::class,
        ];
    }
}

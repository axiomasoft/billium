<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Registrars;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\EventPublisher;
use Billium\Platform\Contracts\Feed\EventFeed;
use Billium\Platform\Contracts\Feed\FeedPartitions;
use Billium\Platform\Contracts\Feed\FeedRecoveryQueries;
use Billium\Platform\Contracts\Feed\SnapshotSource;
use Billium\Platform\Contracts\Outbox\CommandOutbox;
use Billium\Platform\Contracts\Outbox\Outbox;
use Billium\Platform\Internal\CommandOutbox\CommandOutboxRelay;
use Billium\Platform\Internal\CommandOutbox\CommandRecorder;
use Billium\Platform\Internal\Feed\BootstrapCoordinator;
use Billium\Platform\Internal\Feed\DatabaseFeedPartitions;
use Billium\Platform\Internal\Feed\DatabaseFeedRecoveryQueries;
use Billium\Platform\Internal\Feed\PartitionStore;
use Billium\Platform\Internal\Inbox\CustodyProcessor;
use Billium\Platform\Internal\Outbox\EventFeedReader;
use Billium\Platform\Internal\Outbox\FeedTokens;
use Billium\Platform\Internal\Outbox\OutboxPruner;
use Billium\Platform\Internal\Outbox\OutboxRecorder;
use Billium\Platform\Internal\Outbox\OutboxRelay;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Internal]
final class PlatformOutboxRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->bind(FeedRecoveryQueries::class, DatabaseFeedRecoveryQueries::class);

        $app->singleton(CommandOutbox::class, static fn (Application $app): CommandRecorder => new CommandRecorder(
            $app->make(DatabaseManager::class),
            $app->make(ModuleRegistry::class),
            static fn (): OperationRunner => $app->make(OperationRunner::class),
        ));

        $app->singleton(OutboxRelay::class, static function (Application $app): OutboxRelay {
            $options = $app->make(BilliumOptions::class);
            $databases = $app->make(DatabaseManager::class);

            return new OutboxRelay(
                $databases->connection($options->connection),
                $app->make(EventPublisher::class),
                $options->outbox->claimSeconds,
                $options->outbox->deliveryBackoffSeconds,
                $options->outbox->deliveryBudget,
            );
        });

        $app->singleton(CommandOutboxRelay::class, static function (Application $app): CommandOutboxRelay {
            $options = $app->make(BilliumOptions::class);
            $databases = $app->make(DatabaseManager::class);

            return new CommandOutboxRelay(
                $databases->connection($options->connection),
                $app->make(EventPublisher::class),
                $options->outbox->claimSeconds,
                $options->outbox->deliveryBackoffSeconds,
                $options->outbox->deliveryBudget,
            );
        });

        $app->singleton(EventFeed::class, static function (Application $app): EventFeedReader {
            $options = $app->make(BilliumOptions::class);
            $databases = $app->make(DatabaseManager::class);
            $appKey = $app->bound(Repository::class) ? $app->make(Repository::class)->get('app.key') : null;
            $key = $options->feed->decodedSigningKey(is_string($appKey) ? $appKey : null);

            return new EventFeedReader(
                $databases->connection($options->connection),
                new FeedTokens($key),
                static fn (): CallerContext => $app->make(CallerContext::class),
                static fn (): iterable => self::sources($app),
                $options->feed->bootstrapLeaseSeconds,
            );
        });

        $app->singleton(PartitionStore::class, static fn (Application $app): PartitionStore => new PartitionStore($app->make(DatabaseManager::class)));

        $app->singleton(FeedPartitions::class, static function (Application $app): DatabaseFeedPartitions {
            $options = $app->make(BilliumOptions::class);

            return new DatabaseFeedPartitions(
                new CustodyProcessor(
                    $app->make(PartitionStore::class),
                    static fn (): OperationRunner => $app->make(OperationRunner::class),
                    $options->inbox->retryBackoffSeconds,
                    $options->inbox->retryBudget,
                ),
                new BootstrapCoordinator($app->make(PartitionStore::class), static fn (): OperationRunner => $app->make(OperationRunner::class)),
            );
        });

        $app->singleton(OutboxPruner::class, static function (Application $app): OutboxPruner {
            $options = $app->make(BilliumOptions::class);
            $databases = $app->make(DatabaseManager::class);

            return new OutboxPruner(
                $databases->connection($options->outbox->maintenanceConnection ?? $options->connection),
                $options->outbox->retentionFloorSeconds,
            );
        });

        $app->singleton(Outbox::class, static fn (Application $app): OutboxRecorder => new OutboxRecorder(
            $app->make(DatabaseManager::class),
            new Codec,
            static fn (): OperationRunner => $app->make(OperationRunner::class),
        ));
    }

    #[Override]
    public function boot(Application $app): void {}

    /** @return iterable<SnapshotSource> */
    private static function sources(Application $app): iterable
    {
        foreach ($app->tagged('billium.snapshot_sources') as $source) {
            if (! $source instanceof SnapshotSource) {
                throw new LogicException('Snapshot source tag contains an invalid reader');
            }

            yield $source;
        }
    }
}

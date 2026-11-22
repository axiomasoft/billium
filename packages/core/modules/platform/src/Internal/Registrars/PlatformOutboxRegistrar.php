<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Registrars;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runtime\AuthenticatedObservationContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\EventPublisher;
use Billium\Core\Contracts\Runtime\ScopedCallerContext;
use Billium\Platform\Contracts\Accounting\AccountingMapper;
use Billium\Platform\Contracts\Feed\EventFeed;
use Billium\Platform\Contracts\Feed\FeedPartitions;
use Billium\Platform\Contracts\Feed\FeedRecoveryQueries;
use Billium\Platform\Contracts\Feed\SnapshotSource;
use Billium\Platform\Contracts\Idempotency\IdempotencyReceiptQueries;
use Billium\Platform\Contracts\Idempotency\IdempotencyStore;
use Billium\Platform\Contracts\Import\ImportBatchApplier;
use Billium\Platform\Contracts\Import\ImportIdentityStore;
use Billium\Platform\Contracts\Import\ImportMonetaryPort;
use Billium\Platform\Contracts\Import\ImportPreviewEvaluator;
use Billium\Platform\Contracts\Import\ImportReconciler;
use Billium\Platform\Contracts\Inbox\AuthenticatedObservationBootstrap;
use Billium\Platform\Contracts\Inbox\ObservationBootstrapAuthenticator;
use Billium\Platform\Contracts\Outbox\CommandOutbox;
use Billium\Platform\Contracts\Outbox\FactualCommandOutbox;
use Billium\Platform\Contracts\Outbox\Outbox;
use Billium\Platform\Contracts\Outbox\OutboxDeliveryQueries;
use Billium\Platform\Data\EventContracts\PublicEventDestinations;
use Billium\Platform\Internal\Accounting\AccountingFactMapper;
use Billium\Platform\Internal\CommandOutbox\CommandOutboxRelay;
use Billium\Platform\Internal\CommandOutbox\CommandRecorder;
use Billium\Platform\Internal\CommandOutbox\FactualCommandRecorder;
use Billium\Platform\Internal\Feed\AuthenticatedBootstrapContext;
use Billium\Platform\Internal\Feed\BootstrapCoordinator;
use Billium\Platform\Internal\Feed\DatabaseFeedPartitions;
use Billium\Platform\Internal\Feed\DatabaseFeedRecoveryQueries;
use Billium\Platform\Internal\Feed\PartitionStore;
use Billium\Platform\Internal\Import\DatabaseImportBatchApplier;
use Billium\Platform\Internal\Import\DatabaseImportIdentityStore;
use Billium\Platform\Internal\Import\DatabaseImportReconciler;
use Billium\Platform\Internal\Import\DefaultImportPreviewEvaluator;
use Billium\Platform\Internal\Inbox\CustodyProcessor;
use Billium\Platform\Internal\Outbox\DatabaseOutboxDeliveryQueries;
use Billium\Platform\Internal\Outbox\EventFeedReader;
use Billium\Platform\Internal\Outbox\FeedCommandResults;
use Billium\Platform\Internal\Outbox\FeedTokens;
use Billium\Platform\Internal\Outbox\OutboxPruner;
use Billium\Platform\Internal\Outbox\OutboxRecorder;
use Billium\Platform\Internal\Outbox\OutboxRelay;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Contracts\Contributions\Extensions;
use ReflectionProperty;

#[Internal]
final class PlatformOutboxRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->bind(FeedRecoveryQueries::class, DatabaseFeedRecoveryQueries::class);
        $app->bind(OutboxDeliveryQueries::class, DatabaseOutboxDeliveryQueries::class);
        $app->singleton(AuthenticatedBootstrapContext::class, static fn (Application $app): AuthenticatedBootstrapContext => new AuthenticatedBootstrapContext(
            static fn (): ObservationBootstrapAuthenticator => $app->make(ObservationBootstrapAuthenticator::class),
            static fn (): ScopedCallerContext => $app->make(ScopedCallerContext::class),
            $app->make(DatabaseManager::class),
        ));
        $app->alias(AuthenticatedBootstrapContext::class, AuthenticatedObservationBootstrap::class);

        $app->singleton(CommandOutbox::class, static fn (Application $app): CommandRecorder => new CommandRecorder(
            $app->make(DatabaseManager::class),
            $app->make(ModuleRegistry::class),
            static fn (): OperationRunner => $app->make(OperationRunner::class),
        ));
        $app->singleton(FactualCommandOutbox::class, static fn (Application $app): FactualCommandRecorder => new FactualCommandRecorder(
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
            $connection = $databases->connection($options->connection);
            $claims = $app->make(IdempotencyStore::class);

            return new EventFeedReader(
                $connection,
                new FeedTokens($key),
                static fn (): CallerContext => $app->make(CallerContext::class),
                static fn (): iterable => self::sources($app),
                new FeedCommandResults($connection, $claims, $app->make(IdempotencyReceiptQueries::class)),
                $options->feed->bootstrapLeaseSeconds,
            );
        });

        $app->singleton(PartitionStore::class, static fn (Application $app): PartitionStore => new PartitionStore($app->make(DatabaseManager::class), $app->make(BilliumOptions::class)));

        $app->singleton(CustodyProcessor::class, static function (Application $app): CustodyProcessor {
            $options = $app->make(BilliumOptions::class);

            return new CustodyProcessor(
                $app->make(PartitionStore::class),
                static fn (): OperationRunner => $app->make(OperationRunner::class),
                static fn (): CallerContext => $app->make(CallerContext::class),
                $options->inbox->retryBackoffSeconds,
                $options->inbox->retryBudget,
                $app->make(AuthenticatedObservationContext::class),
            );
        });

        $app->singleton(FeedPartitions::class, static function (Application $app): DatabaseFeedPartitions {
            return new DatabaseFeedPartitions(
                $app->make(CustodyProcessor::class),
                new BootstrapCoordinator(
                    $app->make(PartitionStore::class),
                    static fn (): OperationRunner => $app->make(OperationRunner::class),
                    $app->make(AuthenticatedObservationBootstrap::class),
                ),
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
            $app->bound(PublicEventDestinations::class) ? $app->make(PublicEventDestinations::class) : null,
        ));

        $app->singleton(AccountingMapper::class, static fn (): AccountingFactMapper => new AccountingFactMapper);
        $app->singleton(ImportIdentityStore::class, static fn (Application $app): DatabaseImportIdentityStore => new DatabaseImportIdentityStore(
            $app->make(DatabaseManager::class),
            $app->make(BilliumOptions::class),
            new Codec,
        ));
        $app->singleton(ImportPreviewEvaluator::class, static fn (Application $app): DefaultImportPreviewEvaluator => new DefaultImportPreviewEvaluator(
            $app->make(ImportIdentityStore::class),
            new Codec,
        ));
        $app->singleton(ImportReconciler::class, static fn (Application $app): DatabaseImportReconciler => new DatabaseImportReconciler(
            $app->make(DatabaseManager::class),
            $app->make(BilliumOptions::class),
            $app->make(ImportIdentityStore::class),
            $app->make(AccountingMapper::class),
            static fn (): CallerContext => $app->make(CallerContext::class),
            static fn (): ImportMonetaryPort => $app->make(ImportMonetaryPort::class),
            new Codec,
        ));
        $app->singleton(ImportBatchApplier::class, static fn (Application $app): DatabaseImportBatchApplier => new DatabaseImportBatchApplier(
            $app->make(DatabaseManager::class),
            $app->make(BilliumOptions::class),
            $app->make(ImportIdentityStore::class),
            $app->make(ImportPreviewEvaluator::class),
            $app->make(ImportReconciler::class),
            $app->make(PartitionStore::class),
            $app->make(CustodyProcessor::class),
            static fn (): OperationRunner => $app->make(OperationRunner::class),
            static fn (): CallerContext => $app->make(CallerContext::class),
            static fn (): ImportMonetaryPort => $app->make(ImportMonetaryPort::class),
            new Codec,
        ));
    }

    #[Override]
    public function boot(Application $app): void {}

    /** @return iterable<SnapshotSource> */
    private static function sources(Application $app): iterable
    {
        $seen = [];
        $contributions = $app->bound(Extensions::class)
            ? $app->make(Extensions::class)->contributions(SnapshotSource::class)
            : [];
        $pluginImplementations = [];
        $explicitKeyOverrides = [];

        $aliases = $app instanceof Container ? new ReflectionProperty(Container::class, 'aliases')->getValue($app) : [];
        foreach ($contributions as $contribution) {
            $pluginImplementations[$contribution->implementation] = true;
            if ($contribution->key === null) {
                continue;
            }
            $overrideKey = 'billium.snapshot.'.$contribution->key;
            $isDefaultAlias = is_array($aliases) && ($aliases[$overrideKey] ?? null) === $contribution->implementation;
            if ($app->bound($overrideKey) && ! $isDefaultAlias) {
                $explicitKeyOverrides[$contribution->key] = true;
                $source = $app->make($overrideKey);
                if (! $source instanceof SnapshotSource) {
                    throw new LogicException('Snapshot source slot contains an invalid reader');
                }
                $key = $source->key();
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    yield $source;
                }
            }
        }

        foreach ($app->tagged('billium.snapshot_sources') as $source) {
            if (! $source instanceof SnapshotSource) {
                throw new LogicException('Snapshot source tag contains an invalid reader');
            }
            if (isset($pluginImplementations[$source::class])) {
                continue;
            }
            $key = $source->key();
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                yield $source;
            }
        }

        foreach ($contributions as $contribution) {
            if ($contribution->key !== null && isset($explicitKeyOverrides[$contribution->key])) {
                continue;
            }
            $source = $app->make($contribution->implementation);
            if (! $source instanceof SnapshotSource) {
                throw new LogicException('Snapshot source slot contains an invalid reader');
            }
            $key = $source->key();
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                yield $source;
            }
        }
    }
}

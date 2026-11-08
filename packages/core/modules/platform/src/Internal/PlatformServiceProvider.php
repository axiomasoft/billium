<?php

declare(strict_types=1);

namespace Billium\Platform\Internal;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Contracts\CallerContext;
use Billium\Core\Contracts\EventPublisher;
use Billium\Core\Contracts\FinancialSessionAdmission;
use Billium\Core\Contracts\Modules\ModuleAdmissionGate;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Modules\ModuleStateReader;
use Billium\Core\Contracts\Modules\ModuleTransitionEvidence;
use Billium\Core\Contracts\Modules\ModuleTransitionPlanner;
use Billium\Core\Contracts\OperationRunner;
use Billium\Platform\Contracts\CommandOutbox;
use Billium\Platform\Contracts\EventContractRegistrar;
use Billium\Platform\Contracts\EventFeed;
use Billium\Platform\Contracts\FeedPartitions;
use Billium\Platform\Contracts\FeedRecoveryQueries;
use Billium\Platform\Contracts\IdempotencyStore;
use Billium\Platform\Contracts\Installation;
use Billium\Platform\Contracts\Outbox;
use Billium\Platform\Contracts\RootAdmission;
use Billium\Platform\Contracts\SnapshotSource;
use Billium\Platform\Internal\CommandOutbox\CommandOutboxRelay;
use Billium\Platform\Internal\CommandOutbox\CommandRecorder;
use Billium\Platform\Internal\Console\PruneCommand;
use Billium\Platform\Internal\Console\RegisterEventContractsCommand;
use Billium\Platform\Internal\Console\RelayCommand;
use Billium\Platform\Internal\EventContracts\DatabaseEventContractRegistrar;
use Billium\Platform\Internal\Feed\BootstrapCoordinator;
use Billium\Platform\Internal\Feed\DatabaseFeedPartitions;
use Billium\Platform\Internal\Feed\DatabaseFeedRecoveryQueries;
use Billium\Platform\Internal\Feed\PartitionStore;
use Billium\Platform\Internal\Idempotency\DatabaseIdempotencyStore;
use Billium\Platform\Internal\Inbox\CustodyProcessor;
use Billium\Platform\Internal\Installation\DatabaseInstallation;
use Billium\Platform\Internal\Installation\InitInstallationCommand;
use Billium\Platform\Internal\Installation\PlatformRootAdmission;
use Billium\Platform\Internal\Installation\RuntimeGrants;
use Billium\Platform\Internal\Outbox\EventFeedReader;
use Billium\Platform\Internal\Outbox\FeedTokens;
use Billium\Platform\Internal\Outbox\OutboxPruner;
use Billium\Platform\Internal\Outbox\OutboxRecorder;
use Billium\Platform\Internal\Outbox\OutboxRelay;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FeedRecoveryQueries::class, DatabaseFeedRecoveryQueries::class);
        $this->app->singleton(EventContractRegistrar::class, static fn (Application $app): DatabaseEventContractRegistrar => new DatabaseEventContractRegistrar(
            $app->make(DatabaseManager::class)->connection(self::connection()), $app->make(ModuleRegistry::class),
        ));
        $this->app->singleton(CommandOutbox::class, static fn (Application $app): CommandRecorder => new CommandRecorder(
            $app->make(DatabaseManager::class), $app->make(ModuleRegistry::class), static fn (): OperationRunner => $app->make(OperationRunner::class),
        ));
        $this->app->singleton(OutboxRelay::class, static fn (Application $app): OutboxRelay => new OutboxRelay(
            $app->make(DatabaseManager::class)->connection(self::connection()), $app->make(EventPublisher::class),
            self::int('billium.outbox.claim_seconds', 60), self::int('billium.outbox.delivery_backoff_seconds', 5), self::int('billium.outbox.delivery_budget', 10),
        ));
        $this->app->singleton(CommandOutboxRelay::class, static fn (Application $app): CommandOutboxRelay => new CommandOutboxRelay(
            $app->make(DatabaseManager::class)->connection(self::connection()), $app->make(EventPublisher::class),
            self::int('billium.outbox.claim_seconds', 60), self::int('billium.outbox.delivery_backoff_seconds', 5), self::int('billium.outbox.delivery_budget', 10),
        ));
        $this->app->singleton(EventFeed::class, static function (Application $app): EventFeedReader {
            $key = config('billium.feed.signing_key') ?? config('app.key');
            $key = is_string($key) ? $key : '';
            if (str_starts_with($key, 'base64:')) {
                $key = base64_decode(substr($key, 7), true) ?: '';
            }

            return new EventFeedReader($app->make(DatabaseManager::class)->connection(self::connection()), new FeedTokens($key),
                static fn (): CallerContext => $app->make(CallerContext::class),
                static fn (): iterable => self::sources($app), self::int('billium.feed.bootstrap_lease_seconds', 60));
        });
        $this->app->singleton(PartitionStore::class, static fn (Application $app): PartitionStore => new PartitionStore($app->make(DatabaseManager::class)));
        $this->app->singleton(FeedPartitions::class, static fn (Application $app): DatabaseFeedPartitions => new DatabaseFeedPartitions(
            new CustodyProcessor(
                $app->make(PartitionStore::class), static fn (): OperationRunner => $app->make(OperationRunner::class),
                self::int('billium.inbox.retry_backoff_seconds', 5), self::int('billium.inbox.retry_budget', 10),
            ),
            new BootstrapCoordinator($app->make(PartitionStore::class), static fn (): OperationRunner => $app->make(OperationRunner::class)),
        ));
        $this->app->singleton(OutboxPruner::class, static function (Application $app): OutboxPruner {
            $maintenance = config('billium.outbox.maintenance_connection');
            $floor = config('billium.outbox.retention_floor_seconds');

            return new OutboxPruner($app->make(DatabaseManager::class)->connection(is_string($maintenance) ? $maintenance : self::connection()), is_int($floor) ? $floor : null);
        });
        $this->app->singleton(RuntimeGrants::class, static fn (): RuntimeGrants => new RuntimeGrants(self::connection()));
        $this->app->singleton(IdempotencyStore::class, static fn (Application $app): DatabaseIdempotencyStore => new DatabaseIdempotencyStore(
            $app->make(DatabaseManager::class)->connection(self::connection()),
            self::int('billium.idempotency.lock_timeout_ms', 5000),
        ));
        $this->app->singleton(RootAdmission::class, static fn (Application $app): PlatformRootAdmission => new PlatformRootAdmission(
            $app->make(DatabaseManager::class),
            static fn (): FinancialSessionAdmission => $app->make(FinancialSessionAdmission::class),
            $app->make(ModuleRegistry::class),
            $app->make(ModuleAdmissionGate::class),
        ));
        $this->app->singleton(Outbox::class, static fn (Application $app): OutboxRecorder => new OutboxRecorder(
            $app->make(DatabaseManager::class),
            new Codec,
            static fn (): OperationRunner => $app->make(OperationRunner::class),
        ));
        $this->app->singleton(DatabaseInstallation::class, static function (Application $app): DatabaseInstallation {
            $enabled = config('billium.modules.enabled');

            return new DatabaseInstallation(
                $app->make(DatabaseManager::class),
                self::connection(),
                $app->make(OperationRunner::class),
                static fn (): CallerContext => $app->make(CallerContext::class),
                $app->make(ModuleRegistry::class),
                $app->make(ModuleTransitionPlanner::class),
                is_array($enabled) ? array_values(array_filter($enabled, is_string(...))) : [],
                self::int('billium.financial_session.seal_lock_timeout_ms', 5000),
                static fn (): ?ModuleTransitionEvidence => $app->bound(ModuleTransitionEvidence::class) ? $app->make(ModuleTransitionEvidence::class) : null,
            );
        });
        $this->app->alias(DatabaseInstallation::class, Installation::class);
        $this->app->alias(DatabaseInstallation::class, ModuleStateReader::class);
    }

    public function boot(): void
    {
        // Capture the host runtime before migrate --database temporarily changes database.default to the owner.
        $this->app->make(RuntimeGrants::class);
        if ($this->app->runningInConsole()) {
            $this->commands([InitInstallationCommand::class, RelayCommand::class, PruneCommand::class, RegisterEventContractsCommand::class]);
        }
    }

    /** @return iterable<SnapshotSource> */
    private static function sources(Application $app): iterable
    {
        foreach ($app->tagged('billium.snapshot_sources') as $source) {
            if (! $source instanceof SnapshotSource) {
                throw new \LogicException('Snapshot source tag contains an invalid reader');
            }
            yield $source;
        }
    }

    private static function connection(): string
    {
        $name = config('billium.connection') ?? config('database.default');

        return is_string($name) ? $name : 'default';
    }

    private static function int(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) ? $value : $default;
    }
}

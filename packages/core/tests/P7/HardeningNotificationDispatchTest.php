<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P7;

use Billium\Collection\Contracts\CollectionNotificationPort;
use Billium\Collection\Data\EnqueueCollectionNotification;
use Billium\Collection\Enums\NotificationChannel;
use Billium\Collection\Enums\NotificationStatus;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Core\Tests\P7\Support\ExecutableCollectionTestCase;
use Billium\Testkit\Coordinator\InMemoryCollectionNotificationPort;
use Closure;
use PHPUnit\Framework\Attributes\Group;

/**
 * A rolled-back dispatch attempt can still enter the runner body. The attempt that
 * returns must be the replayed receipt, and that return must not deliver again.
 */
#[Group('pg')]
#[Group('P7.6')]
final class HardeningNotificationDispatchTest extends ExecutableCollectionTestCase
{
    public function test_replay_after_a_rolled_back_dispatch_attempt_does_not_send(): void
    {
        $app = $this->application();
        $inner = $app->make(OperationRunner::class);
        self::assertInstanceOf(OperationRunner::class, $inner);
        $app->instance(OperationRunner::class, new ReplayAfterRolledBackDispatchAttempt($inner));

        $scope = $this->collectionScope();
        $notification = $this->notificationService()->enqueue(new EnqueueCollectionNotification(
            'hardening-enqueue-replay',
            $scope,
            'case-a',
            null,
            'dunning_reminder',
            'v1',
            NotificationChannel::Email,
            'customer-channel://email/user-1',
            ['currency' => 'RUB'],
        ));
        $port = new InMemoryCollectionNotificationPort;
        $app->instance(CollectionNotificationPort::class, $port);

        $view = $this->notificationService()->dispatch($scope, $notification->messageId, 'hardening-dispatch-replay');

        self::assertSame(0, $port->deliveryCount($notification->messageId));
        self::assertSame(NotificationStatus::Pending, $view->status);
        self::assertSame(0, $view->deliveryAttempts);
        self::assertNull($view->transportAckRef);
    }
}

/**
 * Invokes the dispatch body, discards that attempt, then returns a replayed receipt.
 * DatabaseOperationRunner does this when LockPlanChanged or a deadlock rolls the first
 * attempt back and the retry observes another committed claim.
 */
final class ReplayAfterRolledBackDispatchAttempt implements OperationRunner
{
    private bool $dispatchAttempt = false;

    public function __construct(private OperationRunner $inner) {}

    public function run(OperationSpec $spec, Closure $body): StoredOutcome
    {
        if ($spec->commandKind !== 'collection.notification.dispatch.v1') {
            return $this->inner->run($spec, $body);
        }

        $this->dispatchAttempt = true;
        try {
            $body(new UnusedDispatchContext);
        } catch (LockPlanChanged) {
            // The attempt rolled back with the claim. A later attempt can still replay.
        } finally {
            $this->dispatchAttempt = false;
        }

        return StoredOutcome::fromResult('accepted', [
            'class' => 'collection.notification.dispatched',
            'body' => ['notificationId' => 1],
        ], 1, true);
    }

    public function assertCurrent(TransactionContext $context, ?string $moduleId = null): void
    {
        if ($this->dispatchAttempt) {
            throw new LockPlanChanged('dispatch attempt rolled back before commit');
        }

        $this->inner->assertCurrent($context, $moduleId);
    }
}

/** The rolled-back attempt ends in assertCurrent, before the context is used. */
final class UnusedDispatchContext implements TransactionContext
{
    public function caller(): never
    {
        throw new LockPlanChanged('unused');
    }

    public function spec(): never
    {
        throw new LockPlanChanged('unused');
    }

    public function connectionName(): never
    {
        throw new LockPlanChanged('unused');
    }

    public function attempt(): never
    {
        throw new LockPlanChanged('unused');
    }

    public function rootRef(): never
    {
        throw new LockPlanChanged('unused');
    }

    public function sessionEpoch(): never
    {
        throw new LockPlanChanged('unused');
    }

    public function admittedModules(): never
    {
        throw new LockPlanChanged('unused');
    }

    public function moduleGeneration(string $moduleId): never
    {
        throw new LockPlanChanged('unused');
    }

    public function isActive(): never
    {
        throw new LockPlanChanged('unused');
    }

    public function assertActive(?string $connectionName = null): never
    {
        throw new LockPlanChanged('unused');
    }

    public function assertParticipant(string $moduleId, ?string $connectionName = null): never
    {
        throw new LockPlanChanged('unused');
    }

    public function lock(LockRequest ...$requests): never
    {
        throw new LockPlanChanged('unused');
    }

    public function afterCommit(Closure $callback): never
    {
        throw new LockPlanChanged('unused');
    }
}

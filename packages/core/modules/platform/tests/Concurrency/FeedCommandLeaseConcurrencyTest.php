<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Concurrency;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\FeedBootstrapRequest;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Contracts\Feed\EventFeed;
use Billium\Platform\Contracts\Idempotency\IdempotencyReceiptQueries;
use Billium\Platform\Contracts\Idempotency\IdempotencyStore;
use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Data\Idempotency\PinnedCommand;
use Billium\Platform\Internal\Installation\Rows;
use Billium\Platform\Internal\Outbox\EventFeedReader;
use Billium\Platform\Internal\Outbox\FeedCommandResults;
use Billium\Platform\Tests\Outbox\OutboxTestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use PDOException;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class FeedCommandLeaseConcurrencyTest extends OutboxTestCase
{
    public function test_runtime_role_grants_and_pin_auth_isolation(): void
    {
        $pdo = TestEnvironment::pdo();
        self::assertSame('42501', self::sqlState($pdo, 'SELECT * FROM public.billium_feed_bootstrap_pin_auth'));
        self::assertSame('42501', self::sqlState($pdo, "UPDATE billium_feed_bootstrap_lease SET expires_at = clock_timestamp() + interval '1 day'"));

        $selection = $this->application()->make(EventFeed::class)->openFeed(
            new FeedScope(self::INSTALLATION, 'seller-a', 'test', '1'),
            'events-2026-10-v1',
            ['billium.event.v1'],
            ['obligation.created.v1'],
        );

        /** @var EventFeedReader $reader */
        $reader = $this->application()->make(EventFeed::class);
        $db = self::connection('pgsql');
        $leaseDirect = (string) Str::ulid();
        $snapDirect = (string) Str::ulid();
        $secretDirect = bin2hex(random_bytes(32));
        $db->insert(
            "INSERT INTO public.billium_feed_bootstrap_lease(lease_ref,subscription_ref,source_scope,selection_digest,floor_tx_id,floor_id,snapshot_ref,expires_at) VALUES (?,?,?,?,pg_current_xact_id()::xid8,0,?,clock_timestamp() + interval '30 seconds')",
            [$leaseDirect, $selection->subscriptionRef, $selection->scope->key(), $selection->selectionDigest, $snapDirect],
        );
        $db->selectOne('SELECT public.billium_feed_register_bootstrap_pin(?, ?, NULL)', [$leaseDirect, hash('sha256', $secretDirect)], false);

        // Wrong secret or empty secret returns null
        self::assertNull($reader->extendBootstrapPin($leaseDirect, $selection->subscriptionRef, $snapDirect, '', 60));
        self::assertNull($reader->extendBootstrapPin($leaseDirect, $selection->subscriptionRef, $snapDirect, 'wrong-secret', 60));
        // Direct pin cannot be called with a wire lease token
        self::assertNull($reader->extendBootstrapPin($leaseDirect, $selection->subscriptionRef, $snapDirect, $secretDirect, 60, str_repeat('a', 48)));
        // Valid direct pin renewal succeeds
        self::assertNotNull($reader->extendBootstrapPin($leaseDirect, $selection->subscriptionRef, $snapDirect, $secretDirect, 60));

        // Out-of-bounds TTL (> 300s) is rejected
        try {
            $reader->extendBootstrapPin($leaseDirect, $selection->subscriptionRef, $snapDirect, $secretDirect, 301);
            self::fail('Expected 22023 for TTL > 300s');
        } catch (PDOException|QueryException $e) {
            self::assertSame('22023', $e->getCode());
        }

        // Wire pin requires matching command lease token (p_lease_token = NULL returns NULL)
        $leaseWire = (string) Str::ulid();
        $snapWire = (string) Str::ulid();
        $secretWire = bin2hex(random_bytes(32));
        $cmdKey = 'sha256:'.hash('sha256', 'wire-cmd-1');
        $cmdToken = bin2hex(random_bytes(24));
        $db->insert(
            "INSERT INTO public.billium_feed_command_lease(lease_key,installation_ref,seller_ref,environment,lease_token,lease_epoch,owner_ref,lease_until) VALUES (?,'inst-1','seller-a','test',?,1,'worker-1',clock_timestamp() + interval '30 seconds')",
            [$cmdKey, $cmdToken],
        );
        $db->insert(
            "INSERT INTO public.billium_feed_bootstrap_lease(lease_ref,subscription_ref,source_scope,selection_digest,floor_tx_id,floor_id,snapshot_ref,expires_at) VALUES (?,?,?,?,pg_current_xact_id()::xid8,0,?,clock_timestamp() + interval '30 seconds')",
            [$leaseWire, $selection->subscriptionRef, $selection->scope->key(), $selection->selectionDigest, $snapWire],
        );
        $db->selectOne('SELECT public.billium_feed_register_bootstrap_pin(?, ?, ?)', [$leaseWire, hash('sha256', $secretWire), $cmdKey], false);

        // Attempting direct-branch renewal (NULL token) on a wire pin returns NULL
        self::assertNull($reader->extendBootstrapPin($leaseWire, $selection->subscriptionRef, $snapWire, $secretWire, 60, null));
        // Wrong token returns NULL
        self::assertNull($reader->extendBootstrapPin($leaseWire, $selection->subscriptionRef, $snapWire, $secretWire, 60, str_repeat('b', 48)));
        // Valid wire token extends both command lease and bootstrap pin
        self::assertNotNull($reader->extendBootstrapPin($leaseWire, $selection->subscriptionRef, $snapWire, $secretWire, 60, $cmdToken));
    }

    public function test_durable_lease_serializes_across_independent_connections_and_preserves_monotonic_epoch(): void
    {
        $dbA = self::connection('pgsql');
        $dbB = new Connection(TestEnvironment::pdo());
        $codec = new Codec;
        $leaseKey = $codec->digest(['lease-test', '1']);
        $tokenA = bin2hex(random_bytes(24));
        $tokenB = bin2hex(random_bytes(24));

        // Worker A acquires epoch 1 on connection A
        $rowA = $dbA->selectOne(<<<'SQL'
            INSERT INTO public.billium_feed_command_lease (
                lease_key, installation_ref, seller_ref, environment,
                lease_token, lease_epoch, owner_ref, acquired_at, lease_until
            ) VALUES (?, 'inst-1', 'seller-a', 'test', ?, 1, 'worker-a', clock_timestamp(), clock_timestamp() + interval '30 seconds')
            ON CONFLICT (lease_key) DO UPDATE
                SET lease_token = EXCLUDED.lease_token,
                    lease_epoch = public.billium_feed_command_lease.lease_epoch + 1,
                    owner_ref = EXCLUDED.owner_ref,
                    acquired_at = EXCLUDED.acquired_at,
                    lease_until = EXCLUDED.lease_until
                WHERE public.billium_feed_command_lease.lease_token IS NULL
                   OR public.billium_feed_command_lease.lease_until <= clock_timestamp()
            RETURNING lease_epoch
            SQL, [$leaseKey, $tokenA], false);
        self::assertNotNull($rowA);
        self::assertSame(1, Rows::int(Rows::field($rowA, 'lease_epoch')));

        // Worker B on independent connection B cannot acquire while A is live
        $rowB = $dbB->selectOne(<<<'SQL'
            INSERT INTO public.billium_feed_command_lease (
                lease_key, installation_ref, seller_ref, environment,
                lease_token, lease_epoch, owner_ref, acquired_at, lease_until
            ) VALUES (?, 'inst-1', 'seller-a', 'test', ?, 1, 'worker-b', clock_timestamp(), clock_timestamp() + interval '30 seconds')
            ON CONFLICT (lease_key) DO UPDATE
                SET lease_token = EXCLUDED.lease_token,
                    lease_epoch = public.billium_feed_command_lease.lease_epoch + 1,
                    owner_ref = EXCLUDED.owner_ref,
                    acquired_at = EXCLUDED.acquired_at,
                    lease_until = EXCLUDED.lease_until
                WHERE public.billium_feed_command_lease.lease_token IS NULL
                   OR public.billium_feed_command_lease.lease_until <= clock_timestamp()
            RETURNING lease_epoch
            SQL, [$leaseKey, $tokenB], false);
        self::assertNull($rowB);

        // A releases (row stays with lease_token = NULL); B acquires epoch 2
        $resultsA = new FeedCommandResults($dbA, $this->application()->make(IdempotencyStore::class), $this->application()->make(IdempotencyReceiptQueries::class));
        $resultsA->releaseLease($leaseKey, $tokenA);

        $rowB2 = $dbB->selectOne(<<<'SQL'
            INSERT INTO public.billium_feed_command_lease (
                lease_key, installation_ref, seller_ref, environment,
                lease_token, lease_epoch, owner_ref, acquired_at, lease_until
            ) VALUES (?, 'inst-1', 'seller-a', 'test', ?, 1, 'worker-b', clock_timestamp(), clock_timestamp() + interval '30 seconds')
            ON CONFLICT (lease_key) DO UPDATE
                SET lease_token = EXCLUDED.lease_token,
                    lease_epoch = public.billium_feed_command_lease.lease_epoch + 1,
                    owner_ref = EXCLUDED.owner_ref,
                    acquired_at = EXCLUDED.acquired_at,
                    lease_until = EXCLUDED.lease_until
                WHERE public.billium_feed_command_lease.lease_token IS NULL
                   OR public.billium_feed_command_lease.lease_until <= clock_timestamp()
            RETURNING lease_epoch
            SQL, [$leaseKey, $tokenB], false);
        self::assertNotNull($rowB2);
        self::assertSame(2, Rows::int(Rows::field($rowB2, 'lease_epoch')));

        // Stale A release does NOT clear B's active lease
        $resultsA->releaseLease($leaseKey, $tokenA);
        self::assertSame($tokenB, $this->value('SELECT lease_token FROM public.billium_feed_command_lease WHERE lease_key = ?', [$leaseKey]));
    }

    public function test_zombie_worker_a_receives_idempotency_in_flight_without_corrupting_worker_b(): void
    {
        $selection = $this->application()->make(EventFeed::class)->openFeed(
            new FeedScope(self::INSTALLATION, 'seller-a', 'test', '1'),
            'events-2026-10-v1',
            ['billium.event.v1'],
            ['obligation.created.v1'],
        );

        $codec = new Codec;
        $cmdJson = $codec->canonicalize([
            'schema' => FeedBootstrapRequest::SCHEMA,
            'installationRef' => self::INSTALLATION,
            'sellerRef' => 'seller-a',
            'environment' => 'test',
            'subscriptionRef' => $selection->subscriptionRef,
            'selectionDigest' => $selection->selectionDigest,
            'commandKey' => (string) Str::uuid(),
        ]);
        $request = $codec->decode($cmdJson);
        assert($request instanceof FeedBootstrapRequest);

        $wire = new PinnedCommand(
            $this->application()->make(CallerContext::class)->current(),
            $request,
            $request->installationRef,
            $request->sellerRef,
            $request->environment,
            $request->commandKey,
        );
        $leaseKey = $codec->digest([get_object_vars($wire->scope), $wire->kind(), $wire->key]);
        $tokenA = bin2hex(random_bytes(24));

        // 1. Worker A acquires lease (epoch=1) and its lease expires before final publication
        self::connection('pgsql')->insert(
            "INSERT INTO public.billium_feed_command_lease(lease_key,installation_ref,seller_ref,environment,lease_token,lease_epoch,owner_ref,lease_until) VALUES (?, ?, 'seller-a', 'test', ?, 1, 'worker-a', clock_timestamp() - interval '5 seconds')",
            [$leaseKey, self::INSTALLATION, $tokenA],
        );

        // 2. Worker B takes over via bootstrapSelectedFeed() and completes retain()
        /** @var EventFeedReader $reader */
        $reader = $this->application()->make(EventFeed::class);
        $wireBootstrap = $reader->bootstrapSelectedFeed($request);
        self::assertSame($selection->subscriptionRef, $wireBootstrap->subscriptionRef);
        self::assertGreaterThanOrEqual(2, Rows::int($this->value('SELECT lease_epoch FROM public.billium_feed_command_lease WHERE lease_key = ?', [$leaseKey])));

        // 3. Zombie Worker A wakes up and calls verifyLeaseActive -> deterministic IdempotencyInFlight
        $results = $this->application()->make(FeedCommandResults::class);
        try {
            $results->verifyLeaseActive($leaseKey, $tokenA);
            self::fail('Expected IdempotencyInFlight for stale zombie worker A');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyInFlight, $e->problem);
        }
    }
}

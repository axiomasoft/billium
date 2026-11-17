<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Outbox;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Platform\Contracts\Feed\EventFeed;
use Billium\Platform\Contracts\Feed\SnapshotSource;
use Billium\Platform\Data\Feed\FeedBootstrap;
use Billium\Platform\Data\Feed\FeedCursor;
use Billium\Platform\Data\Feed\FeedPage;
use Billium\Platform\Data\Feed\FeedPosition;
use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Data\Feed\FeedSelection;
use Billium\Platform\Internal\Installation\Rows;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use LogicException;
use Packman\Attributes\Internal;
use Throwable;

#[Internal]
final readonly class EventFeedReader implements EventFeed
{
    public const int MAX_OPEN_REQUIRED = 16;

    /**
     * @param  Closure(): CallerContext  $callers
     * @param  Closure(): iterable<SnapshotSource>  $sources
     */
    public function __construct(private Connection $db, private FeedTokens $tokens, private Closure $callers, private Closure $sources, private int $leaseSeconds = 60)
    {
        if ($leaseSeconds < 1) {
            throw new LogicException('Bootstrap lease must be positive');
        }
    }

    public function openFeed(FeedScope $scope, string $eventContractRevision, array $envelopeSchemas, array $eventTypes, bool $required = false): FeedSelection
    {
        $this->outsideTransaction();
        $this->authorize($scope);
        sort($envelopeSchemas, SORT_STRING);
        sort($eventTypes, SORT_STRING);
        $envelopeSchemas = array_values(array_unique($envelopeSchemas));
        $eventTypes = array_values(array_unique($eventTypes));
        if ($envelopeSchemas === [] || $eventTypes === []) {
            throw new ProblemException(ProblemCode::SchemaUnsupported, 'Feed requires an explicit nonempty selection');
        }
        foreach ($eventTypes as $type) {
            $found = false;
            foreach ($envelopeSchemas as $schema) {
                $found = $found || $this->db->selectOne('SELECT 1 FROM public.billium_event_contract WHERE contract_revision = ? AND envelope_schema = ? AND event_type = ?', [$eventContractRevision, $schema, $type]) !== null;
            }
            if (! $found) {
                throw new ProblemException(ProblemCode::SchemaUnsupported, 'Unregistered event selection');
            }
        }
        foreach ($envelopeSchemas as $schema) {
            if ($this->db->selectOne('SELECT 1 FROM public.billium_event_contract WHERE contract_revision = ? AND envelope_schema = ?', [$eventContractRevision, $schema]) === null) {
                throw new ProblemException(ProblemCode::SchemaUnsupported, 'Unregistered envelope selection');
            }
        }
        $body = ['scope' => $scope->toArray(), 'eventContractRevision' => $eventContractRevision, 'envelopeSchemas' => $envelopeSchemas, 'eventTypes' => $eventTypes];
        $codec = new Codec;
        $digest = $codec->digest($body);
        $ref = (string) Str::ulid();
        $this->db->transaction(function () use ($ref, $scope, $digest, $body, $required, $codec): void {
            self::barrier($this->db);
            // A required subscription pins prune for its scope; bound them so an abandoned or hostile caller cannot exhaust the scope.
            if ($required) {
                $open = $this->db->selectOne('SELECT count(*) AS open FROM public.billium_feed_subscription WHERE source_scope::jsonb = ?::jsonb AND required AND closed_at IS NULL', [$scope->key()]);
                if (Rows::int(Rows::field($open, 'open')) >= self::MAX_OPEN_REQUIRED) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'Too many open required feeds for this scope; close unused feeds');
                }
            }
            $this->db->insert('INSERT INTO public.billium_feed_subscription(subscription_ref,source_scope,selection_digest,selection,required) VALUES (?,?,?,?::jsonb,?)',
                [$ref, $scope->key(), $digest, $codec->canonicalize($body), $required]);
        });

        return new FeedSelection($ref, $scope, $eventContractRevision, $envelopeSchemas, $eventTypes, $digest, $this->tokens->issue($ref, $scope, $digest, new FeedPosition));
    }

    public function read(FeedSelection $selection, ?string $cursor = null, int $limit = 100): FeedPage
    {
        $this->outsideTransaction();
        if ($limit < 1 || $limit > 1000) {
            throw new LogicException('Feed scan limit must be between 1 and 1000');
        }
        $this->validateSelection($selection);
        [$position, $lease] = $this->tokens->verify($cursor ?? $selection->cursor->token, $selection->subscriptionRef, $selection->scope, $selection->selectionDigest);

        return $this->db->transaction(function () use ($selection, $position, $lease, $limit): FeedPage {
            self::barrier($this->db, shared: true);
            $floor = $this->floor();
            $this->retained($position, $floor);
            if ($lease !== null) {
                $this->lease($selection, $lease);
            }
            $scanLimit = $limit + 1;
            $rows = $this->db->select(<<<SQL
                SELECT * FROM public.billium_outbox_event
                WHERE source_scope::jsonb = ?::jsonb AND (tx_id,id) > (?::xid8,?::bigint)
                  AND tx_id < pg_snapshot_xmin(pg_current_snapshot())
                ORDER BY tx_id,id LIMIT {$scanLimit}
                SQL, [$selection->scope->key(), $position->txId, $position->id]);
            $hasMore = count($rows) > $limit;
            $rows = array_slice($rows, 0, $limit);
            $events = [];
            $scanned = $position;
            foreach ($rows as $row) {
                $scanned = self::position($row);
                if (Rows::field($row, 'contract_revision') === $selection->eventContractRevision
                    && in_array(Rows::field($row, 'schema_version'), $selection->envelopeSchemas, true)
                    && in_array(Rows::field($row, 'type'), $selection->eventTypes, true)) {
                    $events[] = EventRows::envelope($row);
                }
            }

            return new FeedPage($selection->subscriptionRef, $events,
                $this->tokens->issue($selection->subscriptionRef, $selection->scope, $selection->selectionDigest, $scanned, $lease),
                $selection->selectionDigest, $hasMore, $floor);
        });
    }

    public function bootstrap(FeedSelection $selection): FeedBootstrap
    {
        $this->outsideTransaction();
        $this->validateSelection($selection);
        $contracts = [];
        foreach ($selection->eventTypes as $type) {
            foreach ($selection->envelopeSchemas as $schema) {
                $row = $this->db->selectOne('SELECT * FROM public.billium_event_contract WHERE contract_revision = ? AND envelope_schema = ? AND event_type = ?', [$selection->eventContractRevision, $schema, $type]);
                if ($row !== null) {
                    $contracts[] = $row;
                }
            }
        }
        $replay = array_any($contracts, static fn (mixed $row): bool => Rows::field($row, 'bootstrap_mode') !== 'snapshot');
        $leaseRef = (string) Str::ulid();
        $snapshotRef = (string) Str::ulid();
        // Reserve before any module snapshot; prune uses the same exclusive barrier.
        $pin = $this->db->transaction(function () use ($selection, $replay, $leaseRef, $snapshotRef): FeedPosition {
            self::barrier($this->db);
            $floor = $this->floor();
            if ($replay) {
                $this->retained(new FeedPosition, $floor);
            }
            $this->db->insert("INSERT INTO public.billium_feed_bootstrap_lease(lease_ref,subscription_ref,source_scope,selection_digest,floor_tx_id,floor_id,snapshot_ref,expires_at) VALUES (?,?,?,?,?::xid8,?::bigint,?,clock_timestamp() + (? * interval '1 second'))",
                [$leaseRef, $selection->subscriptionRef, $selection->scope->key(), $selection->selectionDigest, $floor->txId, $floor->id, $snapshotRef, $this->leaseSeconds]);

            return $floor;
        });
        try {
            return $this->snapshot($selection, $contracts, $replay, $pin, $leaseRef, $snapshotRef);
        } catch (Throwable $error) {
            // A failed snapshot must not keep the tail pinned until the lease runs out.
            $this->db->update("UPDATE public.billium_feed_bootstrap_lease SET state = 'expired' WHERE lease_ref = ? AND state = 'active'", [$leaseRef]);

            throw $error;
        }
    }

    /** @param list<mixed> $contracts */
    private function snapshot(FeedSelection $selection, array $contracts, bool $replay, FeedPosition $pin, string $leaseRef, string $snapshotRef): FeedBootstrap
    {
        $snapshots = [];
        $cursor = $this->db->transaction(function () use ($selection, $contracts, $replay, $pin, $leaseRef, &$snapshots): FeedCursor {
            $this->db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $row = $this->db->selectOne('SELECT tx_id,id FROM public.billium_outbox_event WHERE source_scope::jsonb = ?::jsonb AND tx_id < pg_snapshot_xmin(pg_current_snapshot()) ORDER BY tx_id DESC,id DESC LIMIT 1', [$selection->scope->key()]);
            $position = $replay ? new FeedPosition : ($row === null ? $pin : self::position($row));
            $available = [];
            foreach (($this->sources)() as $source) {
                if (isset($available[$source->key()])) {
                    throw new LogicException('Duplicate SnapshotSource key');
                }
                $available[$source->key()] = $source;
            }
            foreach ($contracts as $contract) {
                $key = Rows::nullableString(Rows::field($contract, 'snapshot_source_key'));
                if ($key === null || isset($snapshots[$key])) {
                    continue;
                }
                $source = $available[$key] ?? throw new ProblemException(ProblemCode::RecoveryRequired, 'Selected historical SnapshotSource is unavailable');
                $snapshots[$key] = $source->snapshot($selection, $this->db->getName() ?? throw new LogicException('Snapshot connection must be named'));
                if ($this->db->transactionLevel() !== 1 || ! $this->db->getPdo()->inTransaction()) {
                    throw new LogicException('SnapshotSource changed the snapshot transaction');
                }
            }
            ksort($snapshots, SORT_STRING);

            return $this->tokens->issue($selection->subscriptionRef, $selection->scope, $selection->selectionDigest, $position, $leaseRef);
        });
        $digest = (new Codec)->digest(['subscriptionRef' => $selection->subscriptionRef, 'selectionDigest' => $selection->selectionDigest,
            'scope' => $selection->scope->toArray(), 'catalog' => $selection->eventContractRevision,
            'snapshotRef' => $snapshotRef, 'cursor' => $cursor->token, 'snapshots' => (object) $snapshots]);
        $expires = $this->db->transaction(function () use ($selection, $leaseRef, $cursor, $digest): string {
            self::barrier($this->db);
            $this->lease($selection, $leaseRef);
            $this->retained($cursor->position, $this->floor());
            $row = $this->db->selectOne("UPDATE public.billium_feed_bootstrap_lease SET snapshot_digest = ?,cursor_token = ? WHERE lease_ref = ? AND state = 'active' AND expires_at > clock_timestamp() RETURNING expires_at", [$digest, $cursor->token, $leaseRef]);
            if ($row === null) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Bootstrap pin expired during snapshot');
            }

            return new DateTimeImmutable(Rows::string(Rows::field($row, 'expires_at')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        });

        return new FeedBootstrap($selection, $leaseRef, $snapshotRef, $digest, $snapshots, $cursor, $expires);
    }

    public function acknowledgeBootstrap(FeedSelection $selection, string $leaseRef, string $snapshotDigest): void
    {
        $this->outsideTransaction();
        $this->validateSelection($selection);
        $this->db->transaction(function () use ($selection, $leaseRef, $snapshotDigest): void {
            self::barrier($this->db);
            $lease = $this->lease($selection, $leaseRef);
            if (Rows::field($lease, 'snapshot_digest') !== $snapshotDigest || ! is_string(Rows::field($lease, 'cursor_token'))) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Snapshot acknowledgement differs from the verified snapshot');
            }
            [$position] = $this->tokens->verify(Rows::string(Rows::field($lease, 'cursor_token')), $selection->subscriptionRef, $selection->scope, $selection->selectionDigest);
            $this->retained($position, $this->floor());
            $this->advanceFloor($selection, $position);
            $this->db->update("UPDATE public.billium_feed_bootstrap_lease SET state = 'acknowledged',acknowledged_at = COALESCE(acknowledged_at,clock_timestamp()) WHERE lease_ref = ?", [$leaseRef]);
        });
    }

    public function acknowledgeCursor(FeedSelection $selection, string $cursor): void
    {
        $this->outsideTransaction();
        $this->validateSelection($selection);
        [$position, $lease] = $this->tokens->verify($cursor, $selection->subscriptionRef, $selection->scope, $selection->selectionDigest);
        $this->db->transaction(function () use ($selection, $position, $lease): void {
            self::barrier($this->db);
            $this->retained($position, $this->floor());
            if ($lease !== null) {
                $this->lease($selection, $lease);
            }
            $this->advanceFloor($selection, $position);
        });
    }

    public function closeFeed(FeedSelection $selection): void
    {
        $this->outsideTransaction();
        $this->validateSelection($selection);
        $this->db->transaction(function () use ($selection): void {
            self::barrier($this->db);
            $this->db->update("UPDATE public.billium_feed_bootstrap_lease SET state = 'expired' WHERE subscription_ref = ? AND state = 'active'", [$selection->subscriptionRef]);
            $this->db->update('UPDATE public.billium_feed_subscription SET closed_at = clock_timestamp() WHERE subscription_ref = ? AND closed_at IS NULL', [$selection->subscriptionRef]);
        });
    }

    public static function barrier(Connection $db, bool $shared = false): void
    {
        $function = $shared ? 'pg_advisory_xact_lock_shared' : 'pg_advisory_xact_lock';
        $db->select("SELECT {$function}(1112099916,1178944836)");
    }

    /**
     * D52 diagnostics only. Null age means the runtime cannot observe the blocker; no freshness claim follows.
     *
     * @return array{safeHorizonTxId:string,horizonAgeSeconds:?int,safePendingRows:int}
     */
    public function diagnostics(FeedSelection $selection, ?string $cursor = null): array
    {
        $this->outsideTransaction();
        $this->validateSelection($selection);
        [$position] = $this->tokens->verify($cursor ?? $selection->cursor->token, $selection->subscriptionRef, $selection->scope, $selection->selectionDigest);
        $row = $this->db->selectOne(<<<'SQL'
            SELECT h.xmin::text AS horizon,
                (SELECT floor(extract(epoch FROM (clock_timestamp() - min(xact_start))))::bigint
                 FROM pg_stat_activity WHERE backend_xmin::text::numeric = (h.xmin::text::numeric % 4294967296)) AS age,
                (SELECT count(*) FROM public.billium_outbox_event WHERE source_scope::jsonb = ?::jsonb
                 AND (tx_id,id) > (?::xid8,?::bigint) AND tx_id < h.xmin) AS pending
            FROM (SELECT pg_snapshot_xmin(pg_current_snapshot()) AS xmin) h
            SQL, [$selection->scope->key(), $position->txId, $position->id]);
        $age = Rows::field($row, 'age');

        return ['safeHorizonTxId' => Rows::string(Rows::field($row, 'horizon')),
            'horizonAgeSeconds' => $age === null ? null : Rows::int($age), 'safePendingRows' => Rows::int(Rows::field($row, 'pending'))];
    }

    private function advanceFloor(FeedSelection $selection, FeedPosition $position): void
    {
        $this->db->update('UPDATE public.billium_feed_subscription SET floor_tx_id = ?::xid8,floor_id = ?::bigint WHERE subscription_ref = ? AND (floor_tx_id,floor_id) < (?::xid8,?::bigint)',
            [$position->txId, $position->id, $selection->subscriptionRef, $position->txId, $position->id]);
    }

    private function authorize(FeedScope $scope): void
    {
        $caller = ($this->callers)()->current();
        if ($caller->installationRef !== $scope->installationRef || $caller->sellerRef !== $scope->sellerRef || $caller->environment !== $scope->environment
            || $scope->channel !== 'events' || $scope->sourceAuthority !== 'billium') {
            throw new ProblemException(ProblemCode::AuthDenied, 'Feed source scope is not authenticated');
        }
        $row = $this->db->selectOne('SELECT incarnation FROM public.billium_installation_state WHERE installation_ref = ?', [$scope->installationRef]);
        if ($row === null || (string) Rows::int(Rows::field($row, 'incarnation')) !== $scope->lineage) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Feed lineage differs from current source');
        }
    }

    private function validateSelection(FeedSelection $selection): void
    {
        $this->authorize($selection->scope);
        $row = $this->db->selectOne('SELECT * FROM public.billium_feed_subscription WHERE subscription_ref = ? AND closed_at IS NULL', [$selection->subscriptionRef]);
        $body = ['scope' => $selection->scope->toArray(), 'eventContractRevision' => $selection->eventContractRevision,
            'envelopeSchemas' => $selection->envelopeSchemas, 'eventTypes' => $selection->eventTypes];
        if ($row === null || Rows::field($row, 'selection_digest') !== $selection->selectionDigest || (new Codec)->digest($body) !== $selection->selectionDigest
            || (new Codec)->canonicalize(json_decode(Rows::string(Rows::field($row, 'selection')), false, 512, JSON_THROW_ON_ERROR)) !== (new Codec)->canonicalize($body)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Subscription scope/selection changed; open a new feed');
        }
    }

    private function floor(): FeedPosition
    {
        $row = $this->db->selectOne("SELECT pruned_tx_id AS tx_id,pruned_id AS id FROM public.billium_outbox_watermark WHERE scope = 'installation'");

        return $row === null ? new FeedPosition : self::position($row);
    }

    private function retained(FeedPosition $cursor, FeedPosition $floor): void
    {
        $row = $this->db->selectOne('SELECT (?::xid8,?::bigint) < (?::xid8,?::bigint) AS missing', [$cursor->txId, $cursor->id, $floor->txId, $floor->id]);
        if (Rows::field($row, 'missing') === true) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Retained tail is missing; rebootstrap required');
        }
    }

    private function lease(FeedSelection $selection, string $leaseRef): object
    {
        $row = $this->db->selectOne("SELECT * FROM public.billium_feed_bootstrap_lease WHERE lease_ref = ? AND subscription_ref = ? AND selection_digest = ? AND source_scope::jsonb = ?::jsonb AND (state = 'acknowledged' OR (state = 'active' AND expires_at > clock_timestamp()))",
            [$leaseRef, $selection->subscriptionRef, $selection->selectionDigest, $selection->scope->key()]);

        return is_object($row) ? $row : throw new ProblemException(ProblemCode::RecoveryRequired, 'Bootstrap lease missing or expired; rebootstrap required');
    }

    private static function position(mixed $row): FeedPosition
    {
        return new FeedPosition(Rows::string(Rows::field($row, 'tx_id')), (string) Rows::int(Rows::field($row, 'id')));
    }

    private function outsideTransaction(): void
    {
        if ($this->db->transactionLevel() !== 0 || $this->db->getPdo()->inTransaction()) {
            throw new LogicException('Feed requires an independent root transaction');
        }
    }
}

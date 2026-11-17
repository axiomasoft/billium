<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Feed;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Platform\Contracts\Inbox\AuthenticatedObservationBootstrap;
use Billium\Platform\Contracts\Inbox\BootstrapItemHandler;
use Billium\Platform\Data\Inbox\BootstrapItem;
use Billium\Platform\Data\Inbox\BootstrapPartition;
use Billium\Platform\Data\Inbox\BootstrapStatus;
use Billium\Platform\Data\Inbox\HandlerOutcomeKind;
use Billium\Platform\Data\Inbox\VerifiedObservationBootstrap;
use Billium\Platform\Internal\Installation\Rows;
use Closure;
use Illuminate\Database\Connection;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

/**
 * Staged partition bootstrap (D60, inbox-feed): the complete snapshot is verified and stored first; every item then gets a
 * durable disposition in its own recovery root; only when none is undisposed does one short root adopt the cursor, retire
 * the old partition and activate the new one together with the held tail. A partition is never active-partial and a crash
 * resumes from the item dispositions.
 */
#[Internal]
final readonly class BootstrapCoordinator
{
    private const int SESSION_SEGMENT = 1;

    /** @param Closure(): OperationRunner $runner */
    public function __construct(private PartitionStore $partitions, private Closure $runner, private ?AuthenticatedObservationBootstrap $origins = null) {}

    public function stage(TransactionContext $context, BootstrapPartition $snapshot, ?VerifiedObservationBootstrap $grant = null): int
    {
        ($this->runner)()->assertCurrent($context, 'platform');
        $caller = $context->caller();
        if ($snapshot->sellerRef !== $caller->sellerRef || $snapshot->environment !== $caller->environment
            || ! $context->spec()->selectsSource($snapshot->sourceAuthority)
            || ! in_array($snapshot->sourceAuthority, $caller->sourceAuthorities, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'The snapshot is outside the authenticated source scope');
        }
        $db = $this->partitions->connection($context);
        $root = null;
        if ($grant !== null) {
            if ($this->origins === null) {
                throw new ProblemException(ProblemCode::AuthDenied, 'No authenticated bootstrap context');
            }
            $this->origins->assertCurrent($caller, $snapshot, $grant);
            if ($context->spec()->financialScope === null || get_object_vars($grant->scope) !== get_object_vars($context->spec()->financialScope) || ! $context->spec()->mode->financial()) {
                throw new ProblemException(ProblemCode::AuthDenied, 'Bootstrap differs from the admitted scope');
            }
            $root = ObservationScopes::claim($db, $context, $snapshot->channel, $snapshot->sourceAuthority, $grant->providerAccountRef,
                $grant->rootOperationRef, $snapshot->sourceLineage, $snapshot->partitionRef, $grant->proofDigest);
        } elseif ($context->spec()->financialScope?->intentOwnerNamespace !== null) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Qualified bootstrap requires authenticated manifest');
        }
        $this->partitions->lockNamespace($db, $snapshot->channel, $snapshot->sourceAuthority, $snapshot->sellerRef, $snapshot->environment, $snapshot->partitionRef);
        $existing = $db->selectOne('SELECT * FROM public.billium_feed_bootstrap_session WHERE bootstrap_ref = ?', [$snapshot->bootstrapRef]);
        if ($existing !== null) {
            if (Rows::field($existing, 'snapshot_digest') !== $snapshot->snapshotDigest || Rows::field($existing, 'seller_ref') !== $caller->sellerRef
                || Rows::field($existing, 'environment') !== $caller->environment || Rows::field($existing, 'source_authority') !== $snapshot->sourceAuthority
                || Rows::field($existing, 'channel') !== $snapshot->channel || Rows::field($existing, 'source_lineage') !== $snapshot->sourceLineage
                || Rows::field($existing, 'partition_ref') !== $snapshot->partitionRef || Rows::field($existing, 'cursor_sequence') !== $snapshot->cursorSequence
                || Rows::field($existing, 'item_count') !== $snapshot->expectedItemCount
                || Rows::field($existing, 'source_retention_lease_ref') !== $snapshot->sourceRetentionLeaseRef) {
                throw new ProblemException(ProblemCode::ObservationConflict, 'The bootstrap reference names another snapshot');
            }
        }
        if (! $snapshot->verifies() || array_any($snapshot->items, static fn (BootstrapItem $item): bool => ($item->payload->installationRef ?? null) !== $caller->installationRef)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'The bootstrap snapshot is incomplete or altered');
        }
        if ($existing !== null) {
            if (ObservationScopes::binding($db, 'bootstrap_session_id', Rows::int(Rows::field($existing, 'id')))?->id !== $root?->id) {
                throw new ProblemException(ProblemCode::ObservationConflict, 'Bootstrap reference already has different provenance');
            }

            return Rows::int(Rows::field($existing, 'id')); // resume only an identical, complete manifest
        }
        $old = $this->partitions->live($db, $snapshot->channel, $snapshot->sourceAuthority, $snapshot->sellerRef, $snapshot->environment, $snapshot->partitionRef);
        if ($old !== null && ObservationScopes::binding($db, 'partition_id', $old->id)?->id !== $root?->id) {
            if ($root === null) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Unproven or foreign partition cannot be adopted');
            }
            $old = $this->partitions->lock($context, $old);
            ProvenLegacyGeneration::link($db, $old, $snapshot, $root, $grant->proofDigest);
        }
        if ($root !== null) {
            if (ObservationScopes::openBootstrap($db, Rows::int($root->id)) !== null) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Another manifest is already staged for this original root');
            }
            $old = ObservationScopes::live($db, Rows::int($root->id));
        }
        if ($old === null) {
            // A pre-upgrade retirement must still be recoverable through bootstrap, never through first-delivery creation.
            $retired = $db->selectOne(<<<'SQL'
                SELECT * FROM public.billium_feed_partition
                WHERE channel=? AND source_authority=? AND seller_ref=? AND environment=? AND partition_ref=? AND state='retired'
                ORDER BY generation DESC, id DESC LIMIT 1
                SQL, [$snapshot->channel, $snapshot->sourceAuthority, $snapshot->sellerRef, $snapshot->environment, $snapshot->partitionRef]);
            if ($retired !== null) {
                $old = PartitionRow::from($retired);
                ObservationScopes::check($context, $snapshot->sourceAuthority, ObservationScopes::binding($db, 'partition_id', $old->id));
                $context->lock(new LockRequest(LockRank::FeedPartition, 'billium_feed_partition', [$old->id]));
                if ($this->partitions->live($db, $snapshot->channel, $snapshot->sourceAuthority, $snapshot->sellerRef, $snapshot->environment, $snapshot->partitionRef) !== null) {
                    throw new LockPlanChanged('A partition appeared while staging recovery');
                }
            }
        }
        if ($old !== null) {
            if ($old->state !== 'retired') {
                $old = $this->partitions->lock($context, $old);
                $this->partitions->requireRecovery($db, $old, 'bootstrap_staged'); // hold the tail while staging
            }
        }
        $session = $db->selectOne(<<<'SQL'
            INSERT INTO public.billium_feed_bootstrap_session
                (bootstrap_ref, channel, source_authority, source_lineage, seller_ref, environment, partition_ref, cursor_sequence,
                 snapshot_digest, item_count, source_retention_lease_ref, status, old_partition_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'loading', ?)
            RETURNING id
            SQL, [
            $snapshot->bootstrapRef, $snapshot->channel, $snapshot->sourceAuthority, $snapshot->sourceLineage, $snapshot->sellerRef, $snapshot->environment,
            $snapshot->partitionRef, $snapshot->cursorSequence, $snapshot->snapshotDigest, count($snapshot->items), $snapshot->sourceRetentionLeaseRef, $old?->id,
        ]);
        $id = Rows::int(Rows::field($session, 'id'));
        ObservationScopes::link($db, 'bootstrap_session_id', $id, $root, $grant?->proofDigest);
        foreach ($snapshot->items as $item) {
            $db->insert(
                'INSERT INTO public.billium_feed_bootstrap_item (session_id, operation_ref, revision, material_digest, payload) VALUES (?, ?, ?, ?, ?::jsonb)',
                [$id, $item->operationRef, $item->revision, $item->materialDigest, json_encode($item->payload, JSON_THROW_ON_ERROR)],
            );
        }
        $db->update("UPDATE public.billium_feed_bootstrap_session SET status = 'verified', updated_at = now() WHERE id = ?", [$id]);

        return $id;
    }

    /** @return int items still undisposed */
    public function applyNext(TransactionContext $context, int $sessionId, BootstrapItemHandler $handler): int
    {
        ($this->runner)()->assertCurrent($context, 'platform');
        if (! $context->spec()->mode->financial()) {
            throw new LogicException('A bootstrap item is applied in a recovery session root');
        }
        $db = $this->partitions->connection($context);
        $session = $this->lockSession($context, $db, $sessionId);
        $status = Rows::string(Rows::field($session, 'status'));
        if (! in_array($status, ['verified', 'applying'], true)) {
            if ($status === 'ready') {
                return 0;
            }
            throw new ProblemException(ProblemCode::RecoveryRequired, "Bootstrap is {$status}");
        }
        $record = $db->selectOne("SELECT * FROM public.billium_feed_bootstrap_item WHERE session_id = ? AND state = 'staged' ORDER BY id LIMIT 1", [$sessionId]);
        if ($record !== null) {
            $payload = json_decode(Rows::string(Rows::field($record, 'payload')), false, 64, JSON_THROW_ON_ERROR);
            $item = new BootstrapItem(
                Rows::string(Rows::field($record, 'operation_ref')), Rows::int(Rows::field($record, 'revision')), Rows::string(Rows::field($record, 'material_digest')),
                $payload instanceof stdClass ? $payload : new stdClass, Rows::int(Rows::field($record, 'id')), $sessionId,
            );
            $namespace = [];
            foreach (['sourceAuthority' => 'source_authority', 'sourceLineage' => 'source_lineage', 'sellerRef' => 'seller_ref',
                'environment' => 'environment', 'partitionRef' => 'partition_ref'] as $field => $column) {
                $namespace[$field] = Rows::string(Rows::field($session, $column));
            }
            if (! BootstrapPartition::contains($item, $namespace) || ($item->payload->installationRef ?? null) !== $context->caller()->installationRef) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'The bootstrap item is outside its manifest namespace');
            }
            $root = ObservationScopes::binding($db, 'bootstrap_session_id', $sessionId);
            if ($root !== null && (($item->payload->originalPaymentRef ?? $item->operationRef) !== $root->root_operation_ref
                || ($item->payload->providerAccountRef ?? null) !== $root->provider_account_ref)) {
                throw new ProblemException(ProblemCode::ObservationConflict, 'Staged item differs from authenticated root');
            }
            $outcome = $handler->apply($context, $item);
            if ($outcome->kind === HandlerOutcomeKind::Conflict && $outcome->reference === ProblemCode::AuthDenied->value) {
                throw new ProblemException(ProblemCode::AuthDenied, 'The bootstrap item is outside the authenticated source scope');
            }
            $db->update("UPDATE public.billium_feed_bootstrap_session SET status = 'applying', updated_at = now() WHERE id = ? AND status = 'verified'", [$sessionId]);
            [$state, $ref] = match ($outcome->kind) {
                HandlerOutcomeKind::Applied => ['applied', $outcome->reference],
                HandlerOutcomeKind::ParkedDependency => ['parked', $outcome->dependencyKind.':'.$outcome->reference],
                HandlerOutcomeKind::Conflict => ['discrepancy', $outcome->reference],
            };
            $db->update('UPDATE public.billium_feed_bootstrap_item SET state = ?, result_ref = ?, disposed_at = now() WHERE id = ?', [$state, substr($ref, 0, 200), Rows::field($record, 'id')]);
        }
        $remaining = Rows::int(Rows::field($db->selectOne("SELECT count(*) AS n FROM public.billium_feed_bootstrap_item WHERE session_id = ? AND state = 'staged'", [$sessionId]), 'n'));
        if ($remaining === 0) {
            $db->update("UPDATE public.billium_feed_bootstrap_session SET status = 'ready', updated_at = now() WHERE id = ?", [$sessionId]);
        }

        return $remaining;
    }

    public function adopt(TransactionContext $context, int $sessionId): int
    {
        ($this->runner)()->assertCurrent($context, 'platform');
        $db = $this->partitions->connection($context);
        $planned = $this->session($db, $sessionId, $context);
        $root = ObservationScopes::binding($db, 'bootstrap_session_id', $sessionId);
        if ($root !== null) {
            $db->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$root->root_key]);
        }
        $this->partitions->lockNamespace($db, Rows::string(Rows::field($planned, 'channel')), Rows::string(Rows::field($planned, 'source_authority')),
            Rows::string(Rows::field($planned, 'seller_ref')), Rows::string(Rows::field($planned, 'environment')), Rows::string(Rows::field($planned, 'partition_ref')));
        $session = $this->lockSession($context, $db, $sessionId);
        $status = Rows::string(Rows::field($session, 'status'));
        if ($status === 'adopted') {
            return Rows::int(Rows::field($session, 'new_partition_id'));
        }
        if ($status !== 'ready') {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Bootstrap items are not all disposed');
        }
        $channel = Rows::string(Rows::field($session, 'channel'));
        $authority = Rows::string(Rows::field($session, 'source_authority'));
        $lineage = Rows::string(Rows::field($session, 'source_lineage'));
        $seller = Rows::string(Rows::field($session, 'seller_ref'));
        $environment = Rows::string(Rows::field($session, 'environment'));
        $partitionRef = Rows::string(Rows::field($session, 'partition_ref'));
        $cursor = Rows::int(Rows::field($session, 'cursor_sequence'));
        $oldId = Rows::field($session, 'old_partition_id');
        $generation = 1;
        if ($oldId !== null) {
            $old = $this->partitions->find($db, Rows::int($oldId), $seller, $environment) ?? throw new LockPlanChanged('Old partition is gone');
            $generation = $old->generation + 1;
            $db->update("UPDATE public.billium_feed_partition SET state = 'retired', updated_at = now() WHERE id = ?", [$old->id]);
        }
        $new = $this->partitions->create($db, $channel, $authority, $lineage, $seller, $environment, $partitionRef, $generation, $cursor)
            ?? throw new LogicException('A live partition appeared during a staged bootstrap');
        ObservationScopes::link($db, 'partition_id', $new->id, $root, $root === null ? null : Rows::string($root->proof_digest));
        $this->attachTail($db, $new, $oldId === null ? null : Rows::int($oldId), $cursor);
        $this->partitions->extendCustody($db, $new);
        $db->update("UPDATE public.billium_feed_bootstrap_session SET status = 'adopted', new_partition_id = ?, updated_at = now() WHERE id = ?", [$new->id, $sessionId]);

        return $new->id;
    }

    public function status(TransactionContext $context, int $sessionId): BootstrapStatus
    {
        ($this->runner)()->assertCurrent($context, 'platform');
        $db = $this->partitions->connection($context);
        $session = $this->session($db, $sessionId, $context);
        $counts = [];
        foreach ($db->select('SELECT state, count(*) AS n FROM public.billium_feed_bootstrap_item WHERE session_id = ? GROUP BY state', [$sessionId]) as $row) {
            $counts[Rows::string(Rows::field($row, 'state'))] = Rows::int(Rows::field($row, 'n'));
        }
        $old = Rows::field($session, 'old_partition_id');
        $new = Rows::field($session, 'new_partition_id');

        return new BootstrapStatus(
            $sessionId, Rows::string(Rows::field($session, 'status')), Rows::int(Rows::field($session, 'cursor_sequence')),
            Rows::int(Rows::field($session, 'item_count')), $counts['staged'] ?? 0, $counts['applied'] ?? 0, $counts['parked'] ?? 0, $counts['discrepancy'] ?? 0,
            $old === null ? null : Rows::int($old), $new === null ? null : Rows::int($new),
        );
    }

    /** Held tail of the adopted lineage joins the new partition; what the snapshot covers is superseded, never applied twice. */
    private function attachTail(Connection $db, PartitionRow $new, ?int $oldId, int $cursor): void
    {
        $scope = [$new->channel, $new->sourceAuthority, $new->sellerRef, $new->environment, $new->partitionRef, $new->sourceLineage];
        $binding = ObservationScopes::binding($db, 'partition_id', $new->id);
        $scope[] = $binding?->id;
        $partitionMatch = '(m.partition_id IS NULL'.($oldId === null ? '' : ' OR m.partition_id = '.$oldId).')';
        $ns = 'm.channel = ? AND m.source_authority = ? AND m.seller_ref = ? AND m.environment = ? AND m.partition_ref = ? AND m.source_lineage = ?
            AND (SELECT scope_id FROM public.billium_feed_observation_scope_link WHERE inbox_id=m.id) IS NOT DISTINCT FROM ?::bigint';
        $db->update(<<<SQL
            UPDATE public.billium_inbox_message m SET status = 'quarantined', last_error_class = 'superseded_by_bootstrap', park_kind = NULL, park_ref = NULL
            WHERE {$ns} AND {$partitionMatch} AND m.source_sequence <= ? AND m.status IN ('ready', 'parked_gap', 'parked_dependency', 'held_recovery')
            SQL, [...$scope, $cursor]);
        $db->update(<<<SQL
            UPDATE public.billium_inbox_message m SET partition_id = ?, status = CASE WHEN m.source_sequence = ? THEN 'ready' ELSE 'parked_gap' END
            WHERE {$ns} AND {$partitionMatch} AND m.source_sequence > ? AND m.status IN ('ready', 'parked_gap')
            SQL, [$new->id, $cursor + 1, ...$scope, $cursor]);
        // Held deliveries (kept while the partition was in recovery) take a free slot; a second claimant of a slot stays held.
        $db->update(<<<SQL
            UPDATE public.billium_inbox_message m SET partition_id = ?, status = CASE WHEN m.source_sequence = ? THEN 'ready' ELSE 'parked_gap' END
            WHERE m.id IN (
                SELECT min(m.id) FROM public.billium_inbox_message m
                WHERE {$ns} AND {$partitionMatch} AND m.source_sequence > ? AND m.status = 'held_recovery'
                  AND NOT EXISTS (
                      SELECT 1 FROM public.billium_inbox_message t
                      WHERE t.channel = m.channel AND t.source_authority = m.source_authority AND t.source_lineage = m.source_lineage
                        AND t.seller_ref = m.seller_ref AND t.environment = m.environment AND t.partition_ref = m.partition_ref
                        AND t.source_sequence = m.source_sequence AND t.status NOT IN ('held_recovery', 'quarantined'))
                GROUP BY m.source_sequence)
            SQL, [$new->id, $cursor + 1, ...$scope, $cursor]);
    }

    private function session(Connection $db, int $sessionId, TransactionContext $context): mixed
    {
        $caller = $context->caller();
        $session = $db->selectOne('SELECT * FROM public.billium_feed_bootstrap_session WHERE id = ? AND seller_ref = ? AND environment = ?', [$sessionId, $caller->sellerRef, $caller->environment])
            ?? throw new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown bootstrap session');
        $source = Rows::string(Rows::field($session, 'source_authority'));
        if (! $context->spec()->selectsSource($source) || ! in_array($source, $caller->sourceAuthorities, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'The bootstrap session is outside the authenticated source scope');
        }

        $binding = ObservationScopes::binding($db, 'bootstrap_session_id', $sessionId);
        if ($binding !== null && ! $context->spec()->mode->financial()) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Qualified bootstrap requires a current financial session');
        }
        ObservationScopes::check($context, $source, $binding);

        return $session;
    }

    /** Plans partition (rank 30, segment 0) then session (segment 1), locks both and re-reads the session. */
    private function lockSession(TransactionContext $context, Connection $db, int $sessionId): mixed
    {
        $planned = $this->session($db, $sessionId, $context);
        $oldId = Rows::field($planned, 'old_partition_id');
        $requests = [new LockRequest(LockRank::FeedPartition, 'billium_feed_bootstrap_session', [$sessionId], segment: self::SESSION_SEGMENT)];
        if ($oldId !== null) {
            $requests[] = new LockRequest(LockRank::FeedPartition, 'billium_feed_partition', [Rows::int($oldId)]);
        }
        if (count($context->lock(...$requests)) !== count($requests)) {
            throw new LockPlanChanged('Bootstrap rows are gone');
        }
        $current = $this->session($db, $sessionId, $context);
        if (Rows::field($current, 'old_partition_id') !== $oldId) {
            throw new LockPlanChanged('Bootstrap plan changed');
        }

        return $current;
    }
}

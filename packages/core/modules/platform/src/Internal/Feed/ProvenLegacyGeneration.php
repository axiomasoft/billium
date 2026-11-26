<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Feed;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Platform\Data\Inbox\BootstrapItem;
use Billium\Platform\Data\Inbox\BootstrapPartition;
use Billium\Platform\Internal\Installation\Rows;
use Illuminate\Database\Connection;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final class ProvenLegacyGeneration
{
    /** Called only for the current authenticated manifest, with the logical root and physical generation locked. */
    public static function link(Connection $db, PartitionRow $old, BootstrapPartition $snapshot, stdClass $root, string $proof): void
    {
        if ($old->sourceLineage !== $snapshot->sourceLineage || $old->partitionRef !== $snapshot->partitionRef
            || $snapshot->cursorSequence < $old->custodySequence || ObservationScopes::binding($db, 'partition_id', $old->id) !== null) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Original generation needs independent proof');
        }
        $items = json_encode(array_map(static fn (BootstrapItem $item): stdClass => $item->payload, $snapshot->items), JSON_THROW_ON_ERROR);
        $unproven = $db->selectOne(<<<'SQL'
            WITH manifest AS (SELECT payload FROM jsonb_array_elements(?::jsonb) AS items(payload))
            SELECT 1 FROM public.billium_inbox_message m WHERE m.partition_id=? AND NOT EXISTS
                (SELECT FROM manifest WHERE manifest.payload=m.payload) AND NOT EXISTS
                (SELECT FROM public.billium_feed_observation_scope_link l WHERE l.inbox_id=m.id AND l.scope_id=?)
            UNION ALL SELECT 1 FROM public.billium_feed_bootstrap_session s JOIN public.billium_feed_bootstrap_item i ON i.session_id=s.id
                WHERE s.new_partition_id=? AND NOT EXISTS (SELECT FROM manifest WHERE manifest.payload=i.payload)
            UNION ALL SELECT 1 FROM public.billium_feed_bootstrap_session s WHERE s.new_partition_id=? AND s.old_partition_id IS NOT NULL
                AND NOT EXISTS (SELECT FROM public.billium_feed_observation_scope_link l WHERE l.partition_id=s.old_partition_id AND l.scope_id=?)
            LIMIT 1
            SQL, [$items, $old->id, $root->id, $old->id, $old->id, $root->id]);
        if ($unproven !== null) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'The manifest does not independently prove every retained historical delivery and generation');
        }
        foreach ($db->table('billium_inbox_message')->where('partition_id', $old->id)->pluck('id') as $id) {
            ObservationScopes::link($db, 'inbox_id', Rows::int($id), $root, $proof);
        }
        ObservationScopes::link($db, 'partition_id', $old->id, $root, $proof);
    }
}

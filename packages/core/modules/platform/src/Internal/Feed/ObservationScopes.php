<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Feed;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Platform\Internal\Installation\Rows;
use Illuminate\Database\Connection;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

/** Serialized immutable observation origin. Only a fresh financial root can use a persisted origin for application. */
#[Internal]
final class ObservationScopes
{
    public static function rootKey(Caller $caller, string $channel, string $source, string $account, string $root): string
    {
        return (new Codec)->digest(['channel' => $channel, 'installation' => $caller->installationRef, 'seller' => $caller->sellerRef,
            'environment' => $caller->environment, 'source' => $source, 'account' => $account, 'root' => $root]);
    }

    public static function claim(Connection $db, TransactionContext $ctx, string $channel, string $source, string $account, string $root,
        string $lineage, string $partition, ?string $proof): ?stdClass
    {
        $caller = $ctx->caller();
        $owner = $ctx->spec()->financialScope?->intentOwnerNamespace;
        if (($owner === null) !== ($proof === null) || ($proof !== null && preg_match('/^sha256:[a-f0-9]{64}$/D', $proof) !== 1)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'An authenticated original observation owner is required');
        }
        $key = self::rootKey($caller, $channel, $source, $account, $root);
        $db->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$key]);
        if ($owner !== null) {
            if (! in_array($owner, $caller->intentOwnerNamespaces, true) || ! in_array($source, $caller->sourceAuthorities, true)
                || $ctx->spec()->financialScope->sourceAuthority !== $source || $ctx->spec()->financialScope->installationRef !== $caller->installationRef
                || $ctx->spec()->financialScope->sellerRef !== $caller->sellerRef || $ctx->spec()->financialScope->environment !== $caller->environment) {
                throw new ProblemException(ProblemCode::AuthDenied, 'Observation provenance differs from the admitted tuple');
            }
            $db->insert(<<<'SQL'
                INSERT INTO public.billium_feed_observation_root_scope(root_key, channel, installation_ref, seller_ref, environment, source_authority,
                    provider_account_ref, root_operation_ref, intent_owner_namespace, first_source_lineage, first_partition_ref, first_proof_digest)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT (root_key) DO NOTHING
                SQL, [$key, $channel, $caller->installationRef, $caller->sellerRef, $caller->environment, $source, $account, $root, $owner, $lineage, $partition, $proof]);
        }
        $row = $db->table('billium_feed_observation_root_scope')->where('root_key', $key)->first();
        if ($row !== null && ([$row->channel, $row->installation_ref, $row->seller_ref, $row->environment, $row->source_authority,
            $row->provider_account_ref, $row->root_operation_ref, $row->intent_owner_namespace] !==
            [$channel, $caller->installationRef, $caller->sellerRef, $caller->environment, $source, $account, $root, $owner])) {
            throw new ProblemException(ProblemCode::ObservationConflict, 'Original root has different authenticated provenance');
        }

        return $row;
    }

    public static function binding(Connection $db, string $column, int $id): ?stdClass
    {
        return $db->table('billium_feed_observation_scope_link as l')->join('billium_feed_observation_root_scope as r', 'r.id', '=', 'l.scope_id')
            ->where('l.'.$column, $id)->first(['r.*', 'l.proof_digest']);
    }

    public static function link(Connection $db, string $column, int $id, ?stdClass $root, ?string $proof): void
    {
        if (! in_array($column, ['partition_id', 'inbox_id', 'bootstrap_session_id'], true)) {
            throw new LogicException('Unknown observation scope target');
        }
        if ($root !== null) {
            $db->insert("INSERT INTO public.billium_feed_observation_scope_link(scope_id,{$column},proof_digest) VALUES (?,?,?) ON CONFLICT ({$column}) DO NOTHING",
                [$root->id, $id, $proof]);
        }
        if (self::binding($db, $column, $id)?->id !== $root?->id) {
            throw new ProblemException(ProblemCode::ObservationConflict, 'Observation target already has different provenance');
        }
    }

    public static function selection(Caller $caller, string $source, ?stdClass $binding): FinancialScope
    {
        $owner = $binding === null ? null : Rows::string($binding->intent_owner_namespace);
        if (! in_array($source, $caller->sourceAuthorities, true) || ($binding !== null && ($binding->installation_ref !== $caller->installationRef
            || $binding->seller_ref !== $caller->sellerRef || $binding->environment !== $caller->environment
            || $binding->source_authority !== $source || ! in_array($owner, $caller->intentOwnerNamespaces, true)))) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Stored provenance is outside authenticated grants');
        }

        return new FinancialScope($caller->installationRef, $caller->sellerRef, $caller->environment, $owner, $source);
    }

    public static function check(TransactionContext $ctx, string $source, ?stdClass $binding): void
    {
        $expected = self::selection($ctx->caller(), $source, $binding);
        $selected = $ctx->spec()->financialScope;
        if ($selected === null ? $binding !== null : get_object_vars($selected) !== get_object_vars($expected)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Locked observation provenance differs from selected financial scope');
        }
    }

    public static function live(Connection $db, int $rootId): ?PartitionRow
    {
        $row = $db->table('billium_feed_observation_scope_link as l')->join('billium_feed_partition as p', 'p.id', '=', 'l.partition_id')
            ->where('l.scope_id', $rootId)->where('p.state', '<>', 'retired')->first(['p.*']);

        return $row === null ? null : PartitionRow::from($row);
    }

    public static function openBootstrap(Connection $db, int $rootId): ?int
    {
        $id = $db->table('billium_feed_observation_scope_link as l')->join('billium_feed_bootstrap_session as s', 's.id', '=', 'l.bootstrap_session_id')
            ->where('l.scope_id', $rootId)->where('s.status', '<>', 'adopted')->value('s.id');

        return $id === null ? null : Rows::int($id);
    }
}

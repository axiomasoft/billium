<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Feed;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Data\Runtime\FinancialScope;
use Illuminate\Database\Connection;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final class ObservationCommandHistory
{
    /** @param array{commandRef:string,bindingDigest:string} $command */
    public static function conflicts(Connection $db, FinancialScope $scope, array $command, stdClass $body, string $proof): bool
    {
        $key = (new Codec)->canonicalize(['billium.qualified-command', $scope->installationRef, $command['commandRef']]);
        $db->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$key]);
        $original = $db->table('billium_feed_observation_command')->where('installation_ref', $scope->installationRef)
            ->where('command_ref', $command['commandRef'])->where('disposition', 'received')->first();
        if ($original === null || ($original->binding_digest === $command['bindingDigest'] && $original->intent_owner_namespace === $scope->intentOwnerNamespace
            && is_string($original->payload) && (new Codec)->canonicalize($original->payload) === (new Codec)->canonicalize($body))) {
            return false;
        }
        self::store($db, $scope, $command, $body, $proof, null);

        return true;
    }

    /** @param array{commandRef:string,bindingDigest:string} $command */
    public static function store(Connection $db, FinancialScope $scope, array $command, stdClass $body, string $proof, ?int $root): void
    {
        $db->insert(<<<'SQL'
            INSERT INTO public.billium_feed_observation_command(installation_ref,command_ref,binding_digest,proof_digest,intent_owner_namespace,scope_id,disposition,payload)
            VALUES (?,?,?,?,?,?,?,?::jsonb) ON CONFLICT (installation_ref,command_ref,binding_digest) DO NOTHING
            SQL, [$scope->installationRef, $command['commandRef'], $command['bindingDigest'], $proof, $scope->intentOwnerNamespace, $root,
            $root === null ? 'conflict' : 'received', json_encode($body, JSON_THROW_ON_ERROR)]);
    }
}

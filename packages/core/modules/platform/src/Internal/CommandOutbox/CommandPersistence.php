<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\CommandOutbox;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Platform\Data\EventContracts\PreparedInternalCommand;
use Billium\Platform\Internal\Installation\Rows;
use Billium\Platform\Internal\Outbox\EventRows;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

/** Storage only. Each caller must first enforce its own distinct admission policy. */
#[Internal]
final readonly class CommandPersistence
{
    public function __construct(private DatabaseManager $db) {}

    public function record(TransactionContext $context, PreparedInternalCommand $command, ?string $qualifiedScope = null): int
    {
        $codec = new Codec;
        $payload = $codec->canonicalize($command->payload === [] ? new stdClass : $command->payload);
        if (strlen($payload) > 65536) {
            throw new LogicException('Command payload exceeds bound');
        }
        $db = $this->db->connection($context->connectionName());
        $scope = $qualifiedScope ?? EventRows::scope($db, $context)->key();
        $row = $db->selectOne(<<<'SQL'
            INSERT INTO public.billium_outbox_command(command_ref,business_ref,owner_module_id,command_schema,command_type,source_scope,payload_digest,payload)
            VALUES (?,?,?,?,?,?,?,?::jsonb) ON CONFLICT (command_ref) DO NOTHING RETURNING id
            SQL, [$command->commandRef, $command->businessRef, $command->ownerModuleId, $command->commandSchema, $command->commandType,
            $scope, 'sha256:'.hash('sha256', $payload), $payload]);
        if ($row === null) {
            $row = $db->selectOne('SELECT * FROM public.billium_outbox_command WHERE command_ref = ?', [$command->commandRef]);
            $same = $row !== null && Rows::field($row, 'business_ref') === $command->businessRef
                && Rows::field($row, 'owner_module_id') === $command->ownerModuleId && Rows::field($row, 'command_schema') === $command->commandSchema
                && Rows::field($row, 'command_type') === $command->commandType && $codec->canonicalize(json_decode(Rows::string(Rows::field($row, 'source_scope')), false, 64, JSON_THROW_ON_ERROR)) === $scope
                && Rows::field($row, 'payload_digest') === 'sha256:'.hash('sha256', $payload);
            if (! $same) {
                throw new ProblemException(ProblemCode::IdempotencyConflict, 'Command ref already has different immutable content');
            }
            $stored = array_map(static fn (mixed $delivery): string => Rows::string(Rows::field($delivery, 'destination_ref')),
                $db->select('SELECT destination_ref FROM public.billium_outbox_command_delivery WHERE event_id = ? ORDER BY destination_ref', [$command->commandRef]));
            $wanted = array_values(array_unique($command->destinations));
            sort($wanted, SORT_STRING);
            if ($stored !== $wanted) {
                throw new ProblemException(ProblemCode::IdempotencyConflict, 'Command destinations are frozen');
            }

            return Rows::int(Rows::field($row, 'id'));
        }
        EventRows::deliveries($db, 'billium_outbox_command_delivery', $command->commandRef, $command->destinations);

        return Rows::int(Rows::field($row, 'id'));
    }
}

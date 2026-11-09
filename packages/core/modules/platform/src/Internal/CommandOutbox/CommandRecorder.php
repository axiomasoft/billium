<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\CommandOutbox;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Platform\Contracts\CommandOutbox;
use Billium\Platform\Data\EventContracts\PreparedInternalCommand;
use Billium\Platform\Internal\Installation\Rows;
use Billium\Platform\Internal\Outbox\EventRows;
use Closure;
use Illuminate\Database\DatabaseManager;
use LogicException;

final readonly class CommandRecorder implements CommandOutbox
{
    /** @param Closure(): OperationRunner $runner */
    public function __construct(private DatabaseManager $db, private ModuleRegistry $modules, private Closure $runner) {}

    public function record(TransactionContext $context, PreparedInternalCommand $command): int
    {
        ($this->runner)()->assertCurrent($context, 'platform');
        ($this->runner)()->assertCurrent($context, $command->ownerModuleId);
        // @phpstan-ignore identical.alwaysFalse (Keep the explicit admission invariant although sessionEpoch returns int or throws.)
        if (! $context->spec()->mode->financial() || $context->sessionEpoch() === null
            || in_array($context->spec()->operationClass, [ModuleOperationClass::ReceiptReplay, ModuleOperationClass::HistoricalRead, ModuleOperationClass::CallbackCustody], true)) {
            throw new LogicException('Outbox recording requires an admitted financial write operation class and session (D54, D55, D60)');
        }
        $descriptor = $this->modules->descriptor($command->ownerModuleId);
        if ($descriptor === null || ! array_any($descriptor->internalCommands, static fn ($declaration): bool => $declaration->commandSchema === $command->commandSchema && $declaration->commandType === $command->commandType)) {
            throw new LogicException('Internal command is not declared by its owner');
        }
        $codec = new Codec;
        $payload = $codec->canonicalize($command->payload === [] ? new \stdClass : $command->payload);
        if (strlen($payload) > 65536) {
            throw new LogicException('Command payload exceeds bound');
        }
        $db = $this->db->connection($context->connectionName());
        $row = $db->selectOne(<<<'SQL'
            INSERT INTO public.billium_outbox_command(command_ref,business_ref,owner_module_id,command_schema,command_type,source_scope,payload_digest,payload)
            VALUES (?,?,?,?,?,?,?,?::jsonb) ON CONFLICT (command_ref) DO NOTHING RETURNING id
            SQL, [$command->commandRef, $command->businessRef, $command->ownerModuleId, $command->commandSchema, $command->commandType,
            EventRows::scope($db, $context)->key(), 'sha256:'.hash('sha256', $payload), $payload]);
        if ($row === null) {
            $row = $db->selectOne('SELECT * FROM public.billium_outbox_command WHERE command_ref = ?', [$command->commandRef]);
            $same = $row !== null && Rows::field($row, 'business_ref') === $command->businessRef
                && Rows::field($row, 'owner_module_id') === $command->ownerModuleId && Rows::field($row, 'command_schema') === $command->commandSchema
                && Rows::field($row, 'command_type') === $command->commandType && EventRows::sameScope(Rows::field($row, 'source_scope'), EventRows::scope($db, $context))
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

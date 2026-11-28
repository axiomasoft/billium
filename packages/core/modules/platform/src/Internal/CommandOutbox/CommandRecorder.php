<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\CommandOutbox;

use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Modules\InternalCommandDeclaration;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Platform\Contracts\Outbox\CommandOutbox;
use Billium\Platform\Data\EventContracts\PreparedInternalCommand;
use Closure;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class CommandRecorder implements CommandOutbox
{
    /** @param Closure(): OperationRunner $runner */
    public function __construct(private DatabaseManager $db, private ModuleRegistry $modules, private Closure $runner) {}

    public function record(TransactionContext $context, PreparedInternalCommand $command): int
    {
        ($this->runner)()->assertCurrent($context, 'platform');
        ($this->runner)()->assertCurrent($context, $command->ownerModuleId);
        if ($context->spec()->mode !== FinancialMode::Active
            || in_array($context->spec()->operationClass, [ModuleOperationClass::ReceiptReplay, ModuleOperationClass::HistoricalRead, ModuleOperationClass::CallbackCustody], true)) {
            throw new LogicException('Outbox recording requires an admitted financial write operation class and session');
        }
        $context->sessionEpoch(); // Refuses a financial context without an admitted session.
        $descriptor = $this->modules->descriptor($command->ownerModuleId);
        if ($descriptor === null || ! array_any($descriptor->internalCommands, static fn (InternalCommandDeclaration $declaration): bool => $declaration->commandSchema === $command->commandSchema && $declaration->commandType === $command->commandType)) {
            throw new LogicException('Internal command is not declared by its owner');
        }

        return new CommandPersistence($this->db)->record($context, $command);
    }
}

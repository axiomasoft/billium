<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Installation;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\FinancialSessionAdmission;
use Billium\Core\Contracts\Modules\ModuleAdmissionGate;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\LockRequest;
use Billium\Core\Data\Modules\ModuleState;
use Billium\Core\Enums\FinancialMode;
use Billium\Core\Enums\LockRank;
use Billium\Core\Enums\LockScope;
use Billium\Core\Enums\Modules\ModuleLifecycleState;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Exceptions\LockPlanChanged;
use Billium\Core\Exceptions\ModuleAdmissionRefused;
use Billium\Platform\Contracts\RootAdmission;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * Session (rank 15) and module (rank 25) barriers of the root runner, plus the diagnostic commit log (D54, D55, D62).
 * Every lock goes through the context's root LockSet and is held to commit.
 */
final readonly class PlatformRootAdmission implements RootAdmission
{
    /** @param Closure(): FinancialSessionAdmission $tokens resolved per root, so a rebinding host is honoured */
    public function __construct(
        private DatabaseManager $databases,
        private Closure $tokens,
        private ModuleRegistry $registry,
        private ModuleAdmissionGate $gate,
    ) {}

    public function admitSession(TransactionContext $context): ?int
    {
        $context->assertActive();
        $mode = $context->spec()->mode;
        if (! $mode->sharesSessionBarrier()) {
            throw new LogicException("{$mode->value} takes no shared session barrier");
        }
        $db = $this->db($context);
        $installation = $context->caller()->installationRef;
        $planned = Sessions::currentId($db, $installation);
        if ($planned === null) {
            if ($mode === FinancialMode::Administrative) {
                return null;
            }

            throw new ProblemException(ProblemCode::RecoveryRequired, 'no adopted financial session');
        }
        $context->lock(new LockRequest(LockRank::FinancialSession, Sessions::TABLE, [$planned], shared: true, scope: LockScope::Installation));
        if (Sessions::currentId($db, $installation) !== $planned) {
            throw new LockPlanChanged('a newer financial session epoch appeared while waiting');
        }
        $session = Sessions::find($db, $planned) ?? throw new LockPlanChanged('financial session vanished');
        if ($mode === FinancialMode::Administrative) {
            return $session->epoch;
        }

        // D62: the expected owner session, generations, epoch and mode come only from the host token.
        $token = ($this->tokens)()->currentToken($installation);
        if ($token === null) {
            throw new ProblemException(ProblemCode::InstallationQuarantined, 'this process holds no host admission token');
        }
        if ($token->installationRef !== $installation || $token->expired(new DateTimeImmutable)) {
            throw new ProblemException(ProblemCode::InstallationQuarantined, 'host admission token is expired or foreign');
        }
        if (! $token->permits($mode)) {
            throw new ProblemException(ProblemCode::InstallationQuarantined, "host admission token does not permit {$mode->value}");
        }
        if ($token->ownerSessionRef !== $session->ownerSessionRef
            || $token->deploymentGeneration !== $session->deploymentGeneration
            || $token->attachmentGeneration !== $session->attachmentGeneration
            || $token->authorityEpoch !== $session->authorityEpoch) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'adopted session differs from the host owner session');
        }
        $required = $mode === FinancialMode::Active ? 'active' : 'recovery';
        if ($session->state !== $required) {
            throw new ProblemException(ProblemCode::RecoveryRequired, "financial session is {$session->state}, {$mode->value} needs {$required}");
        }

        return $session->epoch;
    }

    public function admitModules(TransactionContext $context): array
    {
        $context->assertActive();
        $spec = $context->spec();
        $modules = $spec->modules;
        foreach ($modules as $module) {
            $descriptor = $this->registry->descriptor($module) ?? throw new LogicException("{$spec->commandKind}: unknown module {$module} in the closure");
            $missing = array_diff($descriptor->requiredModuleIds(), $modules);
            if ($missing !== []) {
                throw new LogicException("{$spec->commandKind}: incomplete module closure, {$module} requires ".implode(', ', $missing).' (D55)');
            }
        }
        $locked = $context->lock(new LockRequest(LockRank::ModuleAdmission, Modules::TABLE, $modules, shared: true, scope: LockScope::Installation, keyColumn: 'module_id'));
        $states = Modules::read($this->db($context), $context->caller()->installationRef, $modules);
        $generations = [];
        foreach ($modules as $module) {
            $state = $states[$module] ?? null;
            if ($state === null || ! in_array($module, $locked, true)) {
                throw new ModuleAdmissionRefused($module, 'module_not_installed');
            }
            $this->admitOne($state, $spec->operationClass);
            $generations[$module] = $state->generation;
        }

        return $generations;
    }

    public function appendCommit(TransactionContext $context, string $kind): int
    {
        $context->assertActive();
        $row = $this->db($context)->selectOne(
            'INSERT INTO public.billium_money_commit_log (kind, session_epoch) VALUES (?, ?) RETURNING commit_no',
            [$kind, $context->sessionEpoch()],
        );

        return Rows::int(Rows::field($row, 'commit_no'));
    }

    private function admitOne(ModuleState $state, ModuleOperationClass $class): void
    {
        $verdict = $this->gate->admit($state, $class);
        if (! $verdict->allowed) {
            throw new ModuleAdmissionRefused($state->moduleId, $verdict->reason);
        }
        $descriptor = $this->registry->descriptor($state->moduleId) ?? throw new ModuleAdmissionRefused($state->moduleId, 'module_unknown');
        $readOnly = in_array($class, [ModuleOperationClass::ReceiptReplay, ModuleOperationClass::HistoricalRead], true);
        $range = $readOnly ? $descriptor->supportedSchemaReadRange : $descriptor->supportedSchemaWriteRange;
        if (! $range->contains($state->schemaVersion)) {
            throw new ModuleAdmissionRefused($state->moduleId, $readOnly ? 'schema_read_unsupported' : 'schema_write_unsupported');
        }
        if ($state->state === ModuleLifecycleState::Retired && ! $readOnly) {
            throw new ModuleAdmissionRefused($state->moduleId, 'retired');
        }
    }

    private function db(TransactionContext $context): Connection
    {
        return $this->databases->connection($context->connectionName());
    }
}

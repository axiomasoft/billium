<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Installation;

use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Modules\ModuleStateReader;
use Billium\Core\Contracts\Modules\ModuleTransitionEvidence;
use Billium\Core\Contracts\Modules\ModuleTransitionPlanner;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Data\Modules\ModuleBlocker;
use Billium\Core\Data\Modules\ModuleState;
use Billium\Core\Data\Modules\ModuleTransitionFacts;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\OwnerSessionIdentity;
use Billium\Core\Data\Runtime\VerifiedSessionAuthorization;
use Billium\Core\Database\RlsSessionContext;
use Billium\Core\Enums\Modules\ModuleLifecycleState;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runner\LockScope;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Platform\Data\Installation\FinancialSessionView;
use Billium\Platform\Data\Installation\ModuleTransitionRequest;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Platform\Exceptions\TransitionRefused;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use LogicException;
use Packman\Attributes\Internal;

/**
 * Platform-owned writes of installation_state, financial_session(_event) and module_state/transitions. Transitions run
 * through the root runner, so the claim of their ref, the barrier locks and the history append share one root Tx.
 */
#[Internal]
final readonly class DatabaseInstallation implements Installation, ModuleStateReader
{
    private const array ORDER = ['accepting' => 0, 'draining' => 1, 'recovery_only' => 2, 'retired' => 3];

    /** @param list<string> $enabledModules */
    public function __construct(
        private DatabaseManager $databases,
        private string $connectionName,
        private OperationRunner $runner,
        /** @var Closure(): CallerContext */
        private Closure $callers,
        private ModuleRegistry $registry,
        private ModuleTransitionPlanner $planner,
        private array $enabledModules,
        private int $sealLockTimeoutMs,
        /** @var Closure(): ?ModuleTransitionEvidence */
        private Closure $evidence,
        /** @var Closure(): FinancialAuthorityEvidence */
        private Closure $authority,
    ) {}

    public function init(string $installationRef): void
    {
        if ($installationRef === '' || strlen($installationRef) > 200) {
            throw new LogicException('An installation ref is a nonempty bounded string');
        }
        foreach ($this->enabledModules as $module) {
            $descriptor = $this->registry->descriptor($module) ?? throw new LogicException("Enabled module {$module} has no descriptor");
            $missing = array_diff($descriptor->requiredModuleIds(), $this->enabledModules);
            if ($missing !== []) {
                throw new LogicException("Enabled module {$module} requires ".implode(', ', $missing).' (D55)');
            }
        }
        $db = $this->db();
        if ($db->transactionLevel() !== 0) {
            throw new LogicException('Installation init is an explicit root operation');
        }
        $db->transaction(function (Connection $db) use ($installationRef): void {
            RlsSessionContext::applyForValues($db, $installationRef, '', '');
            $db->insert('INSERT INTO public.billium_installation_state (installation_ref) VALUES (?) ON CONFLICT (installation_ref) DO NOTHING', [$installationRef]);
            $db->insert("INSERT INTO public.billium_outbox_watermark (id, scope) VALUES (1, 'installation') ON CONFLICT DO NOTHING");
            $modules = $this->enabledModules;
            sort($modules, SORT_STRING);
            foreach ($modules as $module) {
                $ref = "install:{$installationRef}:{$module}";
                $schema = $this->registry->descriptor($module)->currentSchemaVersion ?? 0;
                $inserted = $db->selectOne(<<<'SQL'
                    INSERT INTO public.billium_module_state (installation_ref, module_id, state, config_revision, schema_version, generation, last_transition_ref)
                    VALUES (?, ?, 'accepting', 1, ?, 1, ?)
                    ON CONFLICT (installation_ref, module_id) DO NOTHING RETURNING id
                    SQL, [$installationRef, $module, $schema, $ref]);
                if (is_object($inserted)) {
                    $db->insert(<<<'SQL'
                        INSERT INTO public.billium_module_transitions
                            (transition_ref, installation_ref, module_id, from_state, to_state, from_generation, to_generation, actor_ref, reason)
                        VALUES (?, ?, ?, NULL, 'accepting', NULL, 1, 'installation-init', 'explicit installation init')
                        SQL, [$ref, $installationRef, $module]);
                }
            }
        });
    }

    public function read(string $installationRef, string $moduleId): ?ModuleState
    {
        return Modules::read($this->db(), $installationRef, [$moduleId])[$moduleId] ?? null;
    }

    public function currentSession(string $installationRef): ?FinancialSessionView
    {
        return Sessions::current($this->db(), $installationRef);
    }

    public function openSession(string $transitionRef, string $ownerSessionRef, int $deploymentGeneration, int $attachmentGeneration, int $authorityEpoch, string $evidenceRef): FinancialSessionView
    {
        $args = [$ownerSessionRef, $deploymentGeneration, $attachmentGeneration, $authorityEpoch, $evidenceRef];

        return $this->sessionOperation('open', $transitionRef, $args, function (TransactionContext $context, Connection $db) use ($transitionRef, $ownerSessionRef, $deploymentGeneration, $attachmentGeneration, $authorityEpoch, $evidenceRef): FinancialSessionView {
            $installation = $context->caller()->installationRef;
            $current = $this->lockCurrentSession($context, $db);
            $authorization = $this->authorize($context, 'open', $transitionRef, [$ownerSessionRef, $deploymentGeneration, $attachmentGeneration, $authorityEpoch, $evidenceRef], $current);
            if (! $this->sameOwner($authorization->owner, new OwnerSessionIdentity($installation, $ownerSessionRef, $deploymentGeneration, $attachmentGeneration, $authorityEpoch))
                || $authorization->evidenceRef !== $evidenceRef
                || ($current !== null && ! $this->sameOwner($authorization->retirementProof?->oldOwner, $this->ownerIdentity($current)))) {
                throw new TransitionRefused('authority_owner_mismatch');
            }
            $db->selectOne('SELECT public.billium_open_financial_session(?,?,?,?,?,?,?,?)', [
                $installation, $ownerSessionRef, $deploymentGeneration, $attachmentGeneration, $authorityEpoch,
                $transitionRef, $evidenceRef, $context->caller()->actorRef,
            ]);

            return Sessions::current($db, $installation) ?? throw new LogicException('opened session vanished');
        });
    }

    public function activateSession(string $transitionRef, int $epoch, string $activationCertificateRef, string $externalAdmissionRef, string $evidenceRef): FinancialSessionView
    {
        $args = [$epoch, $activationCertificateRef, $externalAdmissionRef, $evidenceRef];

        return $this->sessionOperation('activate', $transitionRef, $args, function (TransactionContext $context, Connection $db) use ($transitionRef, $epoch, $activationCertificateRef, $externalAdmissionRef, $evidenceRef): FinancialSessionView {
            $current = $this->expectSession($this->lockCurrentSession($context, $db), $epoch, ['recovery']);
            $authorization = $this->authorize($context, 'activate', $transitionRef, [$epoch, $activationCertificateRef, $externalAdmissionRef, $evidenceRef], $current);
            $this->assertActivation($authorization, $current, $context->caller(), $activationCertificateRef, $externalAdmissionRef, $evidenceRef);
            $this->transitionSession($db, $context, $epoch, 'active', $transitionRef, $activationCertificateRef, $externalAdmissionRef, $evidenceRef);

            return Sessions::current($db, $current->installationRef) ?? throw new LogicException('activated session vanished');
        });
    }

    public function beginSealing(string $transitionRef, int $epoch): FinancialSessionView
    {
        return $this->sessionOperation('sealing', $transitionRef, [$epoch], function (TransactionContext $context, Connection $db) use ($transitionRef, $epoch): FinancialSessionView {
            $current = $this->expectSession($this->lockCurrentSession($context, $db), $epoch, ['active']);
            $this->authorize($context, 'sealing', $transitionRef, [$epoch], $current);
            $this->transitionSession($db, $context, $epoch, 'sealing', $transitionRef);

            return Sessions::current($db, $current->installationRef) ?? throw new LogicException('sealing session vanished');
        });
    }

    public function seal(string $transitionRef, int $epoch): FinancialSessionView
    {
        try {
            return $this->sessionOperation('seal', $transitionRef, [$epoch], function (TransactionContext $context, Connection $db) use ($transitionRef, $epoch): FinancialSessionView {
                // Waits for every admitted root holding the row FOR SHARE, but never longer than the configured bound.
                $db->statement("SET LOCAL lock_timeout = '{$this->sealLockTimeoutMs}ms'");
                $current = $this->lockCurrentSession($context, $db);
                $db->statement('SET LOCAL lock_timeout = 0');
                $current = $this->expectSession($current, $epoch, ['active', 'sealing']);
                $this->authorize($context, 'seal', $transitionRef, [$epoch], $current);
                $this->transitionSession($db, $context, $epoch, 'sealed', $transitionRef);

                return Sessions::current($db, $current->installationRef) ?? throw new LogicException('sealed session vanished');
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '55P03') {
                throw new TransitionRefused('seal_lock_timeout');
            }

            throw $exception;
        }
    }

    public function transitionModule(ModuleTransitionRequest $request): StoredOutcome
    {
        $caller = ($this->callers)()->current();
        // Administrative: the shared session barrier without a token; module rows are taken FOR UPDATE by the body.
        $spec = new OperationSpec(
            'platform.module.transition', $caller->sellerRef, $caller->environment, FinancialMode::Administrative,
            ModuleOperationClass::HistoricalRead, [], $request->transitionRef, $request->digest(),
        );

        return $this->runner->run($spec, fn (TransactionContext $context): PlatformReceipt => new PlatformReceipt(
            'platform.module_transition',
            ['transitions' => $this->applyTransition($context, $request)],
        ))->orThrow();
    }

    /** @return list<array<string, int|string|null>> */
    private function applyTransition(TransactionContext $context, ModuleTransitionRequest $request): array
    {
        $db = $this->databases->connection($context->connectionName());
        $installation = $context->caller()->installationRef;
        $dependents = $this->dependents($request->moduleId);
        $required = $this->required($request->moduleId);
        $affected = array_values(array_unique([$request->moduleId, ...$dependents, ...$required]));
        $planned = array_keys(Modules::read($db, $installation, $affected));
        if (! in_array($request->moduleId, $planned, true)) {
            throw new TransitionRefused('module_not_installed');
        }
        // Every affected row FOR UPDATE in module_id order at rank 25; never discovered in reverse order.
        $context->lock(new LockRequest(LockRank::ModuleAdmission, Modules::TABLE, $planned, scope: LockScope::Installation, keyColumn: 'module_id'));
        $states = Modules::read($db, $installation, $affected);
        if (array_keys($states) !== $planned) {
            throw new LockPlanChanged('installed module set changed while waiting for module locks');
        }
        $current = $states[$request->moduleId];
        $target = $request->target;
        if ($target === ModuleLifecycleState::Accepting) {
            foreach ($required as $dependency) {
                if (($states[$dependency]->state ?? null) !== ModuleLifecycleState::Accepting) {
                    throw new TransitionRefused('dependency_not_accepting', [$dependency]);
                }
            }
        }
        $active = [];
        if ($target !== ModuleLifecycleState::Accepting) {
            foreach ($dependents as $dependent) {
                if (isset($states[$dependent]) && self::ORDER[$states[$dependent]->state->value] < self::ORDER[$target->value]) {
                    $active[] = $dependent;
                }
            }
        }
        $facts = $this->transitionFacts($context, $current, $request, $active);
        $preview = $this->planner->previewTransition($current, $target, $facts);
        if (! $preview->allowed()) {
            throw new TransitionRefused('blocked', array_map(static fn (ModuleBlocker $blocker): string => $blocker->code->value, $preview->blockers));
        }
        $moves = [$request->moduleId => $request->transitionRef];
        foreach ($preview->cascade as $dependent) {
            if (! $states[$dependent]->state->canTransitionTo($target)) {
                throw new TransitionRefused('cascade_invalid', [$dependent]);
            }
            if (in_array($target, [ModuleLifecycleState::RecoveryOnly, ModuleLifecycleState::Retired], true)) {
                // The original cascade includes every active transitive dependent. Each owner must still prove
                // its own work drained under these locks; the parent's evidence cannot stand in for the child.
                $dependentFacts = $this->transitionFacts($context, $states[$dependent], $request, []);
                $dependentPreview = $this->planner->previewTransition($states[$dependent], $target, $dependentFacts);
                if (! $dependentPreview->allowed()) {
                    throw new TransitionRefused('blocked', array_map(static fn (ModuleBlocker $blocker): string => $blocker->code->value, $dependentPreview->blockers));
                }
            }
            $moves[$dependent] = "{$request->transitionRef}#{$dependent}";
        }
        ksort($moves, SORT_STRING);

        $applied = [];
        foreach ($moves as $module => $ref) {
            $state = $states[$module];
            $db->insert(<<<'SQL'
                INSERT INTO public.billium_module_transitions
                    (transition_ref, installation_ref, module_id, from_state, to_state, from_generation, to_generation, actor_ref, evidence_ref, reason)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                SQL, [$ref, $installation, $module, $state->state->value, $target->value, $state->generation, $state->generation + 1,
                $context->caller()->actorRef, $request->evidenceRef, $request->reason]);
            $db->update(<<<'SQL'
                UPDATE public.billium_module_state SET state = ?, generation = generation + 1, last_transition_ref = ?, updated_at = now()
                WHERE installation_ref = ? AND module_id = ?
                SQL, [$target->value, $ref, $installation, $module]);
            $applied[] = ['moduleId' => $module, 'transitionRef' => $ref, 'from' => $state->state->value, 'to' => $target->value, 'generation' => $state->generation + 1];
        }

        return $applied;
    }

    /** @param list<string> $active */
    private function transitionFacts(TransactionContext $context, ModuleState $current, ModuleTransitionRequest $request, array $active): ModuleTransitionFacts
    {
        $facts = $request->facts;
        if (in_array($request->target, [ModuleLifecycleState::RecoveryOnly, ModuleLifecycleState::Retired], true)) {
            if ($request->evidenceRef === null) {
                throw new TransitionRefused('evidence_required');
            }
            $facts = ($this->evidence)()?->read($context, $current, $request->evidenceRef)
                ?? throw new TransitionRefused('evidence_unavailable');
        }

        return new ModuleTransitionFacts(
            $facts->pendingOperations, $facts->unknownEffects, $facts->feedGaps, $facts->unexportedFacts, $facts->outboxInboxGaps,
            $facts->activeBindings, $facts->retentionBlockers, $facts->secretRecoveryDependencies, $facts->missingHistoricalReaders,
            $active, $facts->hostAcceptsObservations, $facts->hasHistory, $facts->readBundleInstalled,
        );
    }

    /**
     * @param  list<int|string>  $args
     * @param  Closure(TransactionContext, Connection): FinancialSessionView  $body
     */
    private function sessionOperation(string $kind, string $transitionRef, array $args, Closure $body): FinancialSessionView
    {
        $caller = ($this->callers)()->current();
        $digest = $this->requestDigest($kind, $caller, $args);
        $spec = new OperationSpec(
            "platform.session.{$kind}", $caller->sellerRef, $caller->environment, FinancialMode::SessionControl,
            ModuleOperationClass::HistoricalRead, [], $transitionRef, $digest,
        );
        $outcome = $this->runner->run($spec, fn (TransactionContext $context): PlatformReceipt => new PlatformReceipt(
            'platform.financial_session',
            $body($context, $this->databases->connection($context->connectionName()))->toArray(),
        ))->orThrow();
        $b = $outcome->body;

        return new FinancialSessionView(
            Rows::string($b['installationRef'] ?? null), Rows::int($b['epoch'] ?? null), Rows::string($b['ownerSessionRef'] ?? null),
            Rows::int($b['deploymentGeneration'] ?? null), Rows::int($b['attachmentGeneration'] ?? null), Rows::int($b['authorityEpoch'] ?? null),
            Rows::string($b['state'] ?? null), Rows::nullableString($b['activationCertificateRef'] ?? null), Rows::nullableString($b['sealedAt'] ?? null),
        );
    }

    /** @param list<int|string> $args */
    private function requestDigest(string $kind, Caller $caller, array $args): string
    {
        return 'sha256:'.hash('sha256', json_encode([$kind, $caller->installationRef, $caller->actorRef,
            $caller->sellerRef, $caller->environment, $caller->sourceAuthorities, ...$args,
            ...($caller->intentOwnerNamespaces === [] ? [] : [['intentOwnerNamespaces.v1' => $caller->intentOwnerNamespaces]]),
        ], JSON_THROW_ON_ERROR));
    }

    /** @param list<int|string> $args */
    private function authorize(TransactionContext $context, string $kind, string $transitionRef, array $args, ?FinancialSessionView $current): VerifiedSessionAuthorization
    {
        $caller = $context->caller();
        // The host has already verified/adopted this request before entering the root. Under the barrier: cache only.
        $authorization = ($this->authority)()->transition($caller->installationRef, $transitionRef);
        if ($authorization === null || $authorization->transitionRef !== $transitionRef
            || $authorization->action !== $kind || $authorization->owner->installationRef !== $caller->installationRef
            || $authorization->requestDigest !== $this->requestDigest($kind, $caller, $args)
            || $authorization->audience !== 'billium.financial_session_control.v1'
            || $authorization->expiresAt <= new DateTimeImmutable
            || $authorization->expectedLocalEpoch !== $current?->epoch
            || ($kind !== 'open' && ! $this->sameOwner($authorization->owner, $current === null ? null : $this->ownerIdentity($current)))) {
            throw new TransitionRefused('verified_authority_required');
        }

        return $authorization;
    }

    private function sameOwner(?OwnerSessionIdentity $left, ?OwnerSessionIdentity $right): bool
    {
        return $left !== null && $right !== null && get_object_vars($left) === get_object_vars($right);
    }

    private function ownerIdentity(FinancialSessionView $session): OwnerSessionIdentity
    {
        return new OwnerSessionIdentity($session->installationRef, $session->ownerSessionRef, $session->deploymentGeneration,
            $session->attachmentGeneration, $session->authorityEpoch);
    }

    private function assertActivation(VerifiedSessionAuthorization $authorization, FinancialSessionView $current, Caller $caller, string $certificateRef, string $admissionRef, string $evidenceRef): void
    {
        $certificate = $authorization->activationCertificate;
        $retirement = $authorization->retirementProof;
        $now = new DateTimeImmutable;
        if ($certificate === null || $retirement === null || $certificate->certificateRef !== $certificateRef
            || $authorization->externalAdmissionRef !== $admissionRef || $authorization->evidenceRef !== $evidenceRef
            || ! $this->sameOwner($certificate->newOwner, $authorization->owner) || $certificate->localEpoch !== $current->epoch
            || $certificate->retirementProofDigest !== $retirement->signatureDigest
            || $retirement->oldOwner->installationRef !== $caller->installationRef || $retirement->issuedAt > $now
            || $certificate->actorRef !== $caller->actorRef || $certificate->verdict !== 'activate'
            || $certificate->expiresAt <= $now || $certificate->nonce !== $authorization->nonce) {
            throw new TransitionRefused('activation_certificate_invalid');
        }
        $opened = $this->db()->selectOne("SELECT e.transition_ref FROM public.billium_financial_session_event e JOIN public.billium_financial_session s ON s.id = e.session_id WHERE s.installation_ref = ? AND s.epoch = ? AND e.transition = 'opened'", [$caller->installationRef, $current->epoch]);
        $openRef = is_object($opened) ? Rows::string(Rows::field($opened, 'transition_ref')) : '';
        $opening = ($this->authority)()->transition($caller->installationRef, $openRef);
        if ($opening === null || $opening->action !== 'open' || ! $this->sameOwner($opening->owner, $authorization->owner)
            || $opening->retirementProof?->signatureDigest !== $retirement->signatureDigest) {
            throw new TransitionRefused('activation_retirement_mismatch');
        }
        $coverage = $certificate->coverage;
        if ($coverage->namespaceStates === [] || $coverage->projectionValidationReceipts === []
            || $coverage->downstreamValidationReceipts === [] || $coverage->recoveryHorizon > $now) {
            throw new TransitionRefused('activation_coverage_incomplete');
        }
        $active = false;
        foreach ($coverage->namespaceStates as $namespace) {
            if ($namespace->scope->installationRef !== $caller->installationRef
                || ! in_array($namespace->state, ['active', 'sealed', 'recovery'], true)) {
                throw new TransitionRefused('activation_scope_invalid');
            }
            $active = $active || $namespace->state === 'active';
        }
        foreach ($coverage->namespaceStates as $namespace) {
            if ($namespace->state !== 'active') {
                continue;
            }
            foreach ($coverage->namespaceStates as $closed) {
                if ($closed->state === 'active') {
                    continue;
                }
                $overlap = true;
                foreach (['sellerRef', 'environment', 'intentOwnerNamespace', 'sourceAuthority'] as $field) {
                    $left = $namespace->scope->{$field};
                    $right = $closed->scope->{$field};
                    $overlap = $overlap && ($left === null || $right === null || $left === $right);
                }
                if ($overlap) {
                    throw new TransitionRefused('activation_scope_overlap');
                }
            }
        }
        if (! $active) {
            throw new TransitionRefused('activation_scope_empty');
        }
        foreach ($coverage->perAccountWatermarks as $account) {
            if ($account->from > $coverage->recoveryHorizon || $account->through < $coverage->recoveryHorizon) {
                throw new TransitionRefused('activation_coverage_incomplete');
            }
        }
        foreach ($coverage->unresolved as $operation) {
            if (! in_array($operation->permittedDisposition, ['terminal', 'safely_pending', 'sealed'], true)
                || ($operation->permittedDisposition === 'safely_pending' && $operation->heldReservationRef === null)) {
                throw new TransitionRefused('activation_unresolved_disposition');
            }
            // Unresolved accounts cannot disappear from the signed coverage. The host verifies account-to-owner
            // namespace correspondence before caching; providerNamespace is not an intent-owner namespace.
            $covered = false;
            foreach ($coverage->perAccountWatermarks as $account) {
                if ($account->merchantAccountRef === $operation->merchantAccountRef) {
                    $covered = true;
                    if ($operation->permittedDisposition === 'sealed') {
                        foreach ($coverage->namespaceStates as $namespace) {
                            if ($namespace->state === 'active' && $namespace->scope->intentOwnerNamespace === null) {
                                throw new TransitionRefused('activation_unresolved_scope');
                            }
                        }
                    }
                }
            }
            if (! $covered) {
                throw new TransitionRefused('activation_coverage_incomplete');
            }
        }
    }

    private function lockCurrentSession(TransactionContext $context, Connection $db): ?FinancialSessionView
    {
        $installation = $context->caller()->installationRef;
        $id = Sessions::currentId($db, $installation);
        if ($id === null) {
            return null;
        }
        $context->lock(new LockRequest(LockRank::FinancialSession, Sessions::TABLE, [$id], scope: LockScope::Installation));

        return Sessions::findIfCurrent($db, $id, $installation)
            ?? throw new LockPlanChanged('a newer financial session epoch appeared while waiting');
    }

    /** @param list<string> $states */
    private function expectSession(?FinancialSessionView $current, int $epoch, array $states): FinancialSessionView
    {
        if ($current === null || $current->epoch !== $epoch) {
            throw new TransitionRefused('not_the_current_epoch');
        }
        if (! in_array($current->state, $states, true)) {
            throw new TransitionRefused("session_is_{$current->state}");
        }

        return $current;
    }

    private function transitionSession(Connection $db, TransactionContext $context, int $epoch, string $state, string $transitionRef, ?string $certificateRef = null, ?string $admissionRef = null, ?string $evidenceRef = null): void
    {
        $db->selectOne('SELECT public.billium_transition_financial_session(?,?,?,?,?,?,?,?)', [
            $context->caller()->installationRef, $epoch, $state, $certificateRef, $transitionRef, $admissionRef, $evidenceRef, $context->caller()->actorRef,
        ]);
    }

    /** @return list<string> modules that depend on `$moduleId`, transitively */
    private function dependents(string $moduleId): array
    {
        $found = [];
        $queue = [$moduleId];
        while ($queue !== []) {
            $next = array_shift($queue);
            foreach ($this->registry->manifest()->modules as $module) {
                if (in_array($next, $module->requiredModuleIds(), true) && ! in_array($module->moduleId, $found, true)) {
                    $found[] = $module->moduleId;
                    $queue[] = $module->moduleId;
                }
            }
        }

        return $found;
    }

    /** @return list<string> modules `$moduleId` requires, transitively */
    private function required(string $moduleId): array
    {
        $found = [];
        $queue = [$moduleId];
        while ($queue !== []) {
            $next = array_shift($queue);
            foreach ($this->registry->descriptor($next)?->requiredModuleIds() ?? [] as $dependency) {
                if (! in_array($dependency, $found, true)) {
                    $found[] = $dependency;
                    $queue[] = $dependency;
                }
            }
        }

        return $found;
    }

    private function db(): Connection
    {
        return $this->databases->connection($this->connectionName);
    }
}

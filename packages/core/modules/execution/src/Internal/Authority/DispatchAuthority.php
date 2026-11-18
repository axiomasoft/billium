<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Authority;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialAdmissionToken;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\DispatchDecision;
use Billium\Execution\Data\DispatchRequest;
use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\DriverRequest;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Data\ReplayAuthorization;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Internal\Storage\ExecutionRows;
use Billium\Platform\Contracts\Installation\Installation;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;

/** External verification occurs at Tx0; validate only uses already verified local evidence beneath locks. */
#[Internal]
final class DispatchAuthority
{
    /** @var array<string,array{digest:string,expiresAt:DateTimeImmutable}> */
    private array $verified = [];

    /**
     * @param  Closure(): OperationRunner  $runner
     * @param  Closure(): FinancialSessionAdmission  $tokens
     * @param  Closure(): WriterAdmission  $writer
     * @param  Closure(): DispatchEgress  $egress
     * @param  Closure(): FinancialAuthorityEvidence  $evidence
     */
    public function __construct(
        private readonly DatabaseManager $databases,
        private readonly Closure $runner,
        private readonly Closure $tokens,
        private readonly Closure $evidence,
        private readonly CallerContext $callers,
        private readonly Installation $installation,
        private readonly ExecutionStore $store,
        private readonly Closure $writer,
        private readonly Closure $egress,
        private readonly string $connectionName,
    ) {}

    public function prepare(DispatchRequest $request): DispatchTicket
    {
        $this->assertTx0();
        $now = new DateTimeImmutable;
        foreach ($this->verified as $ref => $entry) {
            if ($entry['expiresAt'] <= $now) {
                unset($this->verified[$ref]);
            }
        }
        $caller = $this->callers->current();
        if ($caller->installationRef !== $request->scope->installationRef || $caller->sellerRef !== $request->scope->sellerRef
            || $caller->environment !== $request->scope->environment
            || ! in_array($request->scope->intentOwnerNamespace, $caller->intentOwnerNamespaces, true)) {
            $this->refuse('Caller has no authenticated business namespace for dispatch');
        }
        $operation = $this->store->operation($request->scope, $request->operationRef);
        if ($operation === null) {
            $this->refuse('No committed operation owns the dispatch request');
        }
        $this->assertProcess($request, $operation->binding->executionProfile);
        $this->assertActiveSession($request);
        $writer = ($this->writer)();
        // Resolve the required egress port before the irreversible dispatch-start decision.
        ($this->egress)();
        $ticket = $writer->ticket($request);
        $writer->verify($ticket, $request);
        if (AuthorityClaims::requestDigest($ticket->claims) !== AuthorityClaims::requestDigest($request)) {
            $this->refuse('Host ticket differs from requested immutable dispatch');
        }
        $this->assertTicketTime($ticket);
        $this->verified[$ticket->ticketRef] = ['digest' => AuthorityClaims::ticketDigest($ticket), 'expiresAt' => $ticket->expiresAt];

        return $ticket;
    }

    /** Caller has acquired the complete collection/root/child/capacity/attempt plan through ExecutionStore::lock. */
    public function validate(TransactionContext $context, DispatchTicket $ticket, OperationRecord $operation, AttemptRecord $attempt): DispatchDecision
    {
        return $this->validateAction($context, $ticket, $operation, $attempt, false);
    }

    public function validateReplay(TransactionContext $context, DispatchTicket $ticket, OperationRecord $operation, AttemptRecord $attempt): DispatchDecision
    {
        return $this->validateAction($context, $ticket, $operation, $attempt, true);
    }

    private function validateAction(TransactionContext $context, DispatchTicket $ticket, OperationRecord $operation, AttemptRecord $attempt, bool $replay): DispatchDecision
    {
        ($this->runner)()->assertCurrent($context, 'platform');
        ($this->runner)()->assertCurrent($context, 'execution');
        $context->assertActive($this->connectionName);
        if ($replay && ($context->spec()->commandKind !== 'execution.original_replay' || ! in_array('execution.replay', $context->caller()->permissions, true))) {
            $this->refuse('Current caller lacks original replay permission');
        }
        if ($context->spec()->mode !== FinancialMode::Active || ! in_array($context->spec()->operationClass,
            [ModuleOperationClass::NewIntent, ModuleOperationClass::FrozenIntentCompletion, ModuleOperationClass::OriginalOwnerRefund], true)) {
            $this->refuse('Only an active outgoing-effect operation may dispatch');
        }
        $this->assertCached($ticket);
        $r = $ticket->claims;
        $currentOperation = $this->store->operation($r->scope, $operation->operationRef, $context);
        $currentAttempt = $this->store->attempt($r->scope, $attempt->attemptRef, $context);
        if ($currentOperation === null || $currentAttempt === null) {
            $this->refuse('Locked dispatch identity disappeared');
        }
        $operation = $currentOperation;
        $attempt = $currentAttempt;
        $profile = $operation->binding->executionProfile;
        $this->assertProcess($r, $profile, $context->caller());
        $selected = $context->spec()->financialScope;
        if ($selected === null || $selected->installationRef !== $r->scope->installationRef
            || $selected->sellerRef !== $r->scope->sellerRef || $selected->environment !== $r->scope->environment
            || $selected->intentOwnerNamespace !== $r->scope->intentOwnerNamespace || $selected->sourceAuthority !== $profile) {
            $this->refuse('Dispatch differs from the exact admitted financial scope');
        }
        $allowedClasses = match ($operation->kind) {
            EffectKind::Charge, EffectKind::Authorize => [ModuleOperationClass::NewIntent, ModuleOperationClass::FrozenIntentCompletion],
            EffectKind::Refund => [ModuleOperationClass::OriginalOwnerRefund],
            EffectKind::Capture, EffectKind::VoidAuthorization => [ModuleOperationClass::FrozenIntentCompletion],
        };
        if (! in_array($context->spec()->operationClass, $allowedClasses, true)) {
            $this->refuse('Dispatch operation class does not authorize this effect kind');
        }
        $digest = AuthorityClaims::immutableDriverRequestDigest($operation->binding, $operation->operationRef, $attempt->attemptRef, $attempt->providerReference, $operation->kind, $operation->requested);
        if ($attempt->immutableRequestDigest !== $digest || $r->localSessionEpoch !== $context->sessionEpoch()
            || $r->operationRef !== $operation->operationRef || $r->attemptRef !== $attempt->attemptRef
            || $attempt->operationRef !== $operation->operationRef || $attempt->providerReference !== $r->providerReference
            || $r->kind !== $operation->kind || $r->amount->toWire() !== $operation->requested->toWire()
            || AuthorityClaims::bindingDigest($operation->binding) !== $r->bindingDigest
            || get_object_vars($operation->binding->scope) !== get_object_vars($r->scope)
            || $r->capabilityDigest !== $operation->binding->capabilityDigest
            || $attempt->leaseFence !== $r->leaseFence
            || $attempt->leaseOwner === null || $attempt->leaseExpiresAt === null || $attempt->leaseExpiresAt <= new DateTimeImmutable
            || ($replay ? ! $this->uncertainOriginal($attempt, $operation) : ($attempt->state !== AttemptState::Admitted || $operation->state !== 'admitted'))) {
            $this->refuse('Locked operation/attempt differs from verified dispatch claims');
        }

        return new DispatchDecision($r->operationRef, $r->attemptRef, $r->leaseFence, $r->localSessionEpoch,
            $ticket->ticketRef, $ticket->egressFenceRef, null, $digest, min($attempt->leaseExpiresAt, $ticket->expiresAt));
    }

    /** @param Closure(): ProviderLookupResult $send */
    public function send(DispatchTicket $ticket, DriverRequest $request, Closure $send): ProviderLookupResult
    {
        $this->verifyEgress($ticket, $request, null);

        return ($this->egress)()->invoke($ticket, $request, $send);
    }

    public function verifyReplayEgress(DispatchTicket $ticket, DriverRequest $request, ReplayAuthorization $approval): void
    {
        $this->verifyEgress($ticket, $request, $approval);
    }

    private function verifyEgress(DispatchTicket $ticket, DriverRequest $request, ?ReplayAuthorization $approval): void
    {
        $this->assertTx0();
        $this->assertCached($ticket);
        $r = $ticket->claims;
        $this->assertProcess($r, $request->binding->executionProfile);
        $this->assertActiveSession($r);
        if ($approval !== null && ! in_array('execution.replay', $this->callers->current()->permissions, true)) {
            $this->refuse('Current caller lacks original replay permission at egress');
        }
        $p = $request->permit;
        $digest = AuthorityClaims::immutableDriverRequestDigest($request->binding, $p->operationRef, $p->attemptRef, $request->providerReference, $r->kind, $request->amount);
        if ($p->immutableRequestDigest !== $digest || $p->operationRef !== $r->operationRef || $p->attemptRef !== $r->attemptRef
            || $p->ticketRef !== $ticket->ticketRef || $p->egressFenceRef !== $ticket->egressFenceRef
            || $p->sessionEpoch !== $r->localSessionEpoch || $p->leaseFence !== $r->leaseFence
            || AuthorityClaims::bindingDigest($request->binding) !== $r->bindingDigest
            || $request->capabilities->digest !== $r->capabilityDigest || $request->capabilities->merchantAccountRef !== $request->binding->merchantAccountRef
            || $request->capabilities->driverId !== $request->binding->driverId || $request->capabilities->driverContractVersion !== $request->binding->driverContractVersion
            || $request->providerReference !== $r->providerReference || $request->amount->toWire() !== $r->amount->toWire()
            || $request->credentials->merchantAccountRef !== $request->binding->merchantAccountRef
            || $request->credentials->expiresAt <= new DateTimeImmutable
            || ($request->binding->collectionAuthorizationRef !== null && $p->collectionDispatchPermitRef === null)) {
            $this->refuse('Egress request differs from immutable dispatch permit');
        }
        // No send can follow a rolled-back CAS. This read is outside any financial transaction.
        $attempt = $this->store->attempt($r->scope, $r->attemptRef);
        $operation = $this->store->operation($r->scope, $r->operationRef);
        if ($attempt === null || $operation === null || $attempt->operationRef !== $r->operationRef
            || $attempt->providerReference !== $r->providerReference
            || $attempt->leaseFence !== $r->leaseFence || $attempt->leaseExpiresAt === null || $attempt->leaseExpiresAt <= new DateTimeImmutable
            || $attempt->immutableRequestDigest !== $digest || $operation->kind !== $r->kind
            || AuthorityClaims::bindingDigest($operation->binding) !== $r->bindingDigest
            || $p->requestDeadlineAt === null || $p->requestDeadlineAt <= new DateTimeImmutable) {
            $this->refuse('No committed current outbound permit');
        }
        if ($approval === null) {
            if ($attempt->state !== AttemptState::Dispatching || $attempt->sessionEpoch !== $r->localSessionEpoch
                || $attempt->ownerSessionRef !== $r->ownerSessionRef || $attempt->dispatchPermitRef !== $ticket->ticketRef
                || $attempt->collectionDispatchPermitRef !== $p->collectionDispatchPermitRef
                || $attempt->requestDeadlineAt?->format('U.u') !== $p->requestDeadlineAt->format('U.u')) {
                $this->refuse('No committed current dispatch-start permit');
            }
        } else {
            $s = $r->scope;
            $marker = $this->databases->connection($this->connectionName)->table('billium_execution_original_replay')
                ->where(['installation_ref' => $s->installationRef, 'seller_ref' => $s->sellerRef, 'environment' => $s->environment,
                    'intent_owner_namespace' => $s->intentOwnerNamespace, 'approval_ref' => $approval->approvalRef,
                    'writer_ticket_ref' => $ticket->ticketRef, 'original_request_digest' => $approval->requestDigest, 'attempt_ref' => $r->attemptRef,
                    'operation_ref' => $r->operationRef, 'lease_fence' => $r->leaseFence, 'deadline' => $p->requestDeadlineAt->format(ExecutionRows::TIMESTAMP)])
                ->whereRaw('deadline > clock_timestamp()')->exists();
            if (! $marker || ! $this->uncertainOriginal($attempt, $operation) || $approval->writerTicketRef !== $ticket->ticketRef
                || $approval->expiresAt <= new DateTimeImmutable) {
                $this->refuse('No committed current original-request replay approval');
            }
        }
        ($this->writer)()->verify($ticket, $r);
        unset($this->verified[$ticket->ticketRef]);
    }

    private function uncertainOriginal(AttemptRecord $attempt, OperationRecord $operation): bool
    {
        return $attempt->startedAt !== null && in_array($attempt->state, [AttemptState::Dispatching, AttemptState::Pending, AttemptState::Unknown], true)
            && in_array($operation->state, ['admitted', 'pending', 'unknown'], true);
    }

    private function assertCached(DispatchTicket $ticket): void
    {
        if (($this->verified[$ticket->ticketRef]['digest'] ?? null) !== AuthorityClaims::ticketDigest($ticket)) {
            $this->refuse('Ticket has no locally verified immutable evidence');
        }
        $this->assertTicketTime($ticket);
    }

    private function assertTicketTime(DispatchTicket $ticket): void
    {
        $now = new DateTimeImmutable;
        if ($ticket->issuedAt > $now || $ticket->expiresAt <= $now || $ticket->expiresAt <= $ticket->issuedAt) {
            $this->refuse('Dispatch ticket is outside its validity interval');
        }
    }

    private function assertProcess(DispatchRequest $request, string $sourceAuthority, ?Caller $caller = null): FinancialAdmissionToken
    {
        $caller ??= $this->callers->current();
        $s = $request->scope;
        $token = ($this->tokens)()->currentToken($s->installationRef);
        if ($request->audience !== 'billium.psp_dispatch.v1' || $request->leaseFence < 1 || $request->localSessionEpoch < 1
            || $request->amount->minor <= 0 || $caller->installationRef !== $s->installationRef || $caller->sellerRef !== $s->sellerRef
            || $caller->environment !== $s->environment || ! in_array($s->intentOwnerNamespace, $caller->intentOwnerNamespaces, true)
            || ! in_array($sourceAuthority, ['remote-billium', 'standalone-billium'], true)
            || ! in_array($sourceAuthority, $caller->sourceAuthorities, true)
            || $token === null || ! $token->permits(FinancialMode::Active) || $token->expired(new DateTimeImmutable)
            || $token->installationRef !== $s->installationRef || $token->ownerSessionRef !== $request->ownerSessionRef
            || $token->deploymentGeneration !== $request->deploymentGeneration || $token->attachmentGeneration !== $request->attachmentGeneration
            || $token->authorityEpoch !== $request->authorityEpoch) {
            $this->refuse('No matching current active process authority for dispatch');
        }
        $verified = ($this->evidence)()->process($s->installationRef, $token->processRef, $token->nonce);
        if ($verified === null || AuthorityClaims::tokenDigest($verified->token) !== AuthorityClaims::tokenDigest($token)
            || $verified->audience !== 'billium.financial_session.v1' || $verified->verifiedAt > new DateTimeImmutable
            || ! array_any($verified->scopes, static fn (FinancialScope $scope): bool => $scope->permits($caller, $s->intentOwnerNamespace, $sourceAuthority))) {
            $this->refuse('Dispatch process token has no independently verified scoped evidence');
        }

        return $token;
    }

    private function assertActiveSession(DispatchRequest $request): void
    {
        $session = $this->installation->currentSession($request->scope->installationRef);
        if ($session === null || $session->state !== 'active' || $session->epoch !== $request->localSessionEpoch
            || $session->ownerSessionRef !== $request->ownerSessionRef || $session->deploymentGeneration !== $request->deploymentGeneration
            || $session->attachmentGeneration !== $request->attachmentGeneration || $session->authorityEpoch !== $request->authorityEpoch) {
            $this->refuse('Outgoing dispatch requires the same active adopted session');
        }
    }

    private function assertTx0(): void
    {
        foreach ($this->databases->getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
                throw new LogicException('Writer verification and egress require transaction level zero');
            }
        }
    }

    private function refuse(string $reason): never
    {
        throw new ProblemException(ProblemCode::RecoveryRequired, $reason);
    }
}

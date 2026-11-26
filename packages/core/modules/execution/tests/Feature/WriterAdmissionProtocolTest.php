<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Data\AdmissionPlan;
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\DispatchDecision;
use Billium\Execution\Data\DispatchRequest;
use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\DriverRequest;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Enums\ProviderLookupState;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use Billium\Execution\Internal\Authority\DispatchAuthority;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Testkit\Fixtures\DeterministicWriterHost;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
#[Group('COV-515')]
final class WriterAdmissionProtocolTest extends ExecutionTestCase
{
    private ?string $journalPath = null;

    private int $sends = 0;

    protected function tearDown(): void
    {
        if ($this->journalPath !== null && is_file($this->journalPath)) {
            unlink($this->journalPath);
        }
        parent::tearDown();
    }

    public function test_verified_ticket_checks_are_cached_under_locks_and_one_committed_permit_sends_once(): void
    {
        [$store,$operation,$attempt,$request,$host,$guard] = $this->fixture();
        self::assertSame(DeterministicWriterHost::bindingDigest($operation->binding), AuthorityClaims::bindingDigest($operation->binding));
        $ticket = $guard->prepare($request);
        $before = count($host->journal->records());
        $decision = $this->dispatch($store, $operation, $attempt, $ticket, $guard);
        self::assertSame($before, count($host->journal->records()), 'No authority/network/journal call while financial locks are held');
        self::assertSame('owner-1', $this->value('SELECT owner_session_ref FROM billium_execution_attempt'));
        $driver = $this->driver($operation, $decision, $request);
        self::assertSame(ProviderLookupState::Ambiguous, $guard->send($ticket, $driver, $this->send(...))->state);
        self::assertSame(1, $this->sends);
        try {
            $guard->send($ticket, $driver, $this->send(...));
            self::fail('Repeated ticket sent');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::RecoveryRequired, $exception->problem);
        }
        self::assertSame(1, $this->sends);
    }

    public function test_rollback_after_dispatch_cas_leaves_no_send_permission(): void
    {
        [$store,$operation,$attempt,$request,$host,$guard] = $this->fixture();
        $ticket = $guard->prepare($request);
        $decision = null;
        try {
            $this->runner()->run($this->spec(financialScope: self::selection(), modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store, $operation, $attempt, $ticket, $guard, &$decision) {
                $store->lock($ctx, $store->plan($operation->binding->scope, $operation->operationRef));
                $decision = $guard->validate($ctx, $ticket, $operation, $attempt);
                self::assertTrue($store->startDispatch($ctx, $decision));
                throw new RuntimeException('after-cas');
            });
            self::fail('Fault missing');
        } catch (RuntimeException $exception) {
            self::assertSame('after-cas', $exception->getMessage());
        }
        self::assertInstanceOf(DispatchDecision::class, $decision);
        self::assertSame('admitted', $this->value('SELECT state FROM billium_execution_attempt'));
        try {
            $guard->send($ticket, $this->driver($operation, $decision, $request), $this->send(...));
            self::fail('Rolled back permit sent');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::RecoveryRequired, $exception->problem);
        }
        self::assertSame(0, $this->sends);
    }

    public function test_immutable_claim_substitutions_fail_locked_attempt_recheck(): void
    {
        [$store,$operation,$attempt,$request,$host,$guard] = $this->fixture();
        foreach (['operationRef' => 'other-operation', 'attemptRef' => 'other-attempt', 'providerReference' => 'new-reference', 'kind' => EffectKind::Refund, 'amount' => new Money($request->amount->minor + 1, $request->amount->currency), 'bindingDigest' => 'sha256:'.str_repeat('0', 64), 'capabilityDigest' => 'other-capability', 'leaseFence' => 2] as $field => $value) {
            $changed = new DispatchRequest(...array_replace(get_object_vars($request), [$field => $value]));
            try {
                $ticket = $guard->prepare($changed);
                $this->dispatch($store, $operation, $attempt, $ticket, $guard);
                self::fail($field.' mismatch admitted');
            } catch (ProblemException $exception) {
                self::assertSame(ProblemCode::RecoveryRequired, $exception->problem);
            }
        }
        $ticket = $guard->prepare($request);
        try {
            $this->runner()->run($this->spec(financialScope: self::selection(), class: ModuleOperationClass::OriginalOwnerRefund, modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store, $operation, $attempt, $guard, $ticket) {
                $store->lock($ctx, $store->plan($operation->binding->scope, $operation->operationRef));
                $guard->validate($ctx, $ticket, $operation, $attempt);

                return self::receipt();
            });
            self::fail('Refund operation class authorized a charge');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::RecoveryRequired, $exception->problem);
        }
        self::assertSame('admitted', $this->value('SELECT state FROM billium_execution_attempt'));
        self::assertSame(0, $this->sends);
    }

    public function test_copied_ticket_changed_signature_and_expired_ticket_do_not_grant_cached_admission(): void
    {
        [$store,$operation,$attempt,$request,$host,$guard] = $this->fixture();
        $issued = $host->ticket($request);
        try {
            $this->dispatch($store, $operation, $attempt, $issued, $guard);
            self::fail('Unverified ticket admitted');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::RecoveryRequired, $exception->problem);
        }
        $ticket = $guard->prepare($request);
        foreach (['signatureDigest' => 'forged', 'expiresAt' => new DateTimeImmutable('-1 second')] as $field => $value) {
            $changed = new DispatchTicket(...array_replace(get_object_vars($ticket), [$field => $value]));
            try {
                $this->dispatch($store, $operation, $attempt, $changed, $guard);
                self::fail('Mutated ticket admitted');
            } catch (ProblemException $exception) {
                self::assertSame(ProblemCode::RecoveryRequired, $exception->problem);
            }
        }
        self::assertSame(0, $this->sends);
    }

    #[Group('PB-13')]
    public function test_retired_egress_after_dispatch_commit_refuses_late_send(): void
    {
        [$store,$operation,$attempt,$request,$host,$guard] = $this->fixture();
        $ticket = $guard->prepare($request);
        $decision = $this->dispatch($store, $operation, $attempt, $ticket, $guard);
        $host->retire($request, 'old-workers-and-egress-retired');
        try {
            $guard->send($ticket, $this->driver($operation, $decision, $request), $this->send(...));
            self::fail('Retired egress sent');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Retired simulated session', $exception->getMessage());
        }
        self::assertSame(0, $this->sends);
        self::assertSame('dispatching', $this->value('SELECT state FROM billium_execution_attempt'), 'Unknown transport stays dispatching for recovery lookup');
    }

    public function test_recovery_seal_foreign_namespace_and_authority_calls_under_tx_refuse_before_ticket(): void
    {
        [$store,$operation,$attempt,$request,$host,$guard] = $this->fixture();
        $before = count($host->journal->records());
        foreach (['ownerSessionRef' => 'foreign-owner', 'authorityEpoch' => 2, 'deploymentGeneration' => 2, 'attachmentGeneration' => 2, 'audience' => 'wrong-audience'] as $field => $value) {
            try {
                $guard->prepare(new DispatchRequest(...array_replace(get_object_vars($request), [$field => $value])));
                self::fail('Foreign process claims issued');
            } catch (ProblemException $exception) {
                self::assertSame(ProblemCode::RecoveryRequired, $exception->problem);
            }
        }
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', ['remote-billium'], ['foreign-owner']));
        try {
            $guard->prepare($request);
            self::fail('Unowned namespace issued');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::RecoveryRequired, $exception->problem);
        }
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', ['remote-billium'], ['source-a']));
        try {
            $this->runner()->run($this->spec(financialScope: self::selection(), modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($guard, $request) {
                $guard->prepare($request);

                return self::receipt();
            });
            self::fail('Authority in transaction');
        } catch (LogicException $exception) {
            self::assertStringContainsString('transaction level zero', $exception->getMessage());
        }
        $this->authority->issueTransition('seal', 'ticket-seal', [1]);
        $this->installation()->seal('ticket-seal', 1);
        try {
            $guard->prepare($request);
            self::fail('Sealed session issued ticket');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::RecoveryRequired, $exception->problem);
        }
        $this->authority->issueTransition('open', 'ticket-recovery', ['owner-2', 2, 2, 2, 'retired-owner']);
        $this->installation()->openSession('ticket-recovery', 'owner-2', 2, 2, 2, 'retired-owner');
        $token = FixedSessionAdmission::token(self::INSTALLATION, ownerSessionRef: 'owner-2', deploymentGeneration: 2, attachmentGeneration: 2, authorityEpoch: 2, modes: [FinancialMode::Recovery]);
        $this->tokens->set($token);
        $this->authority->issueProcess($token);
        $recoveryRequest = new DispatchRequest(...array_replace(get_object_vars($request), ['ownerSessionRef' => 'owner-2', 'localSessionEpoch' => 2, 'authorityEpoch' => 2, 'deploymentGeneration' => 2, 'attachmentGeneration' => 2]));
        try {
            $guard->prepare($recoveryRequest);
            self::fail('Recovery process issued an outgoing ticket');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::RecoveryRequired, $exception->problem);
        }
        self::assertSame($before, count($host->journal->records()));
        self::assertSame(0, $this->sends);
    }

    public function test_foreign_stale_incomplete_and_wrong_connection_contexts_refuse(): void
    {
        [$store, $operation, $attempt, $request, $host, $guard] = $this->fixture('billium_owner');
        $ticket = $guard->prepare($request);
        $stale = null;
        $this->runner()->run($this->spec(financialScope: self::selection(), modules: ['execution', 'platform']), function (TransactionContext $ctx) use (&$stale) {
            $stale = $ctx;

            return self::receipt();
        });
        self::assertInstanceOf(TransactionContext::class, $stale);
        foreach ([$stale, self::createStub(TransactionContext::class)] as $ctx) {
            try {
                $guard->validate($ctx, $ticket, $operation, $attempt);
                self::fail('Foreign or stale context accepted');
            } catch (LogicException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        foreach ([['execution', 'platform'], ['ledger', 'platform'], ['execution']] as $modules) {
            try {
                $this->runner()->run($this->spec(financialScope: self::selection(), modules: $modules), function (TransactionContext $ctx) use ($guard, $ticket, $operation, $attempt) {
                    $guard->validate($ctx, $ticket, $operation, $attempt);

                    return self::receipt();
                });
                self::fail('Wrong connection or incomplete closure accepted');
            } catch (LogicException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        self::assertSame('admitted', $this->value('SELECT state FROM billium_execution_attempt'));
        self::assertSame(0, $this->sends);
    }

    public function test_authority_timeout_never_creates_dispatch_or_falls_back_to_database_state(): void
    {
        [$store, $operation, $attempt, $request] = $this->fixture();
        $unavailable = $this->createMock(WriterAdmission::class);
        $unavailable->expects(self::once())->method('ticket')->willThrowException(new RuntimeException('simulated-authority-timeout'));
        $unavailable->expects(self::never())->method('verify');
        $this->application()->instance(WriterAdmission::class, $unavailable);
        $this->application()->forgetInstance(DispatchAuthority::class);
        $guard = $this->application()->make(DispatchAuthority::class);
        try {
            $guard->prepare($request);
            self::fail('Authority timeout had a fallback');
        } catch (RuntimeException $exception) {
            self::assertSame('simulated-authority-timeout', $exception->getMessage());
        }
        self::assertSame('admitted', $store->attempt($request->scope, $request->attemptRef)?->state->value);
        self::assertNull($this->value('SELECT dispatch_ticket_ref FROM billium_execution_attempt'));
        self::assertSame(0, $this->sends);
    }

    public function test_exact_owner_source_tuple_ignores_unrelated_capabilities_but_cannot_combine_grants(): void
    {
        [$store, $operation, $attempt, $request, $host, $guard] = $this->fixture();
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', ['remote-billium', 'standalone-billium'], ['source-a', 'unrelated-owner']));
        $token = $this->tokens->currentToken(self::INSTALLATION);
        self::assertNotNull($token);
        $this->authority->issueProcess($token, [self::selection(), new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'unrelated-owner', 'standalone-billium')]);
        $ticket = $guard->prepare($request);
        try {
            $this->runner()->run($this->spec(financialScope: new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'standalone-billium'), modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store, $operation, $attempt, $ticket, $guard) {
                $store->lock($ctx, $store->plan($operation->binding->scope, $operation->operationRef));
                $guard->validate($ctx, $ticket, $operation, $attempt);

                return self::receipt();
            });
            self::fail('Correlated grants were combined into an unissued tuple');
        } catch (ProblemException $exception) {
            self::assertContains($exception->problem, [ProblemCode::RecoveryRequired, ProblemCode::InstallationQuarantined]);
        }
        self::assertSame('admitted', $store->attempt($request->scope, $request->attemptRef)?->state->value);
        $decision = $this->dispatch($store, $operation, $attempt, $ticket, $guard);
        self::assertSame($request->operationRef, $decision->operationRef);
    }

    /** @return array{ExecutionStore,OperationRecord,AttemptRecord,DispatchRequest,DeterministicWriterHost,DispatchAuthority} */
    private function fixture(?string $connectionName = null): array
    {
        $store = $this->application()->make(ExecutionStore::class);
        $merchant = ExecutionSchemaTest::merchantFixture();
        $cap = ExecutionSchemaTest::capabilityFixture();
        $registry = $this->application()->make(DatabaseMerchantRegistry::class);
        $this->runner()->run($this->spec(financialScope: self::selection(), mode: FinancialMode::Administrative, class: ModuleOperationClass::HistoricalRead, modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store, $merchant, $cap, $registry) {
            $store->appendMerchantRevision($ctx, $merchant, $cap);
            $registry->appendLifecycle($ctx, $merchant->scope, $merchant->revisionRef, 'enable-new', 'enable_new', 'new', new DateTimeImmutable('-1 day'), null, 'fixture', 'authority-fixture');

            return self::receipt();
        });
        $binding = new FrozenBinding($merchant->scope, 'remote-billium', 'intent-a', 'root-a', $merchant->revisionRef, $merchant->merchantAccountRef, $merchant->driverId, $merchant->driverContractVersion, $cap->digest, 'sha256:'.str_repeat('b', 64), 'execution.intent.v1', null, 'commerz');
        $operation = null;
        $this->runner()->run($this->spec(financialScope: self::selection(), modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store, $binding, &$operation) {
            $store->transitionNamespace($ctx, $binding->scope, NamespaceState::Open, 0, 'complete-history');
            $store->insertBinding($ctx, $binding);
            $operation = $store->insertOperation($ctx, new AdmissionPlan($binding->scope, $binding, 'root-a', EffectKind::Charge, Money::parse('10.00', 'RUB'), 'command-a', $binding->semanticDigest, 'execution.intent.v1', ['execution', 'platform'], [], null));
            $admittedAttempt = $store->insertAttempt($ctx, $operation, 'attempt-a', 'reference-a');
            $store->insertDispatchWork($ctx, $admittedAttempt, 'dispatch-a', 'dispatch.v1');

            return self::receipt();
        });
        self::assertInstanceOf(OperationRecord::class, $operation);
        $attempt = null;
        $this->runner()->run($this->spec(financialScope: self::selection(), modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store, $binding, &$attempt) {
            $store->lock($ctx, $store->plan($binding->scope, 'root-a'));
            $attempt = $store->claimAttempt($ctx, 'attempt-a', 'worker-a', new DateTimeImmutable('+5 minutes'));

            return self::receipt();
        });
        self::assertInstanceOf(AttemptRecord::class, $attempt);
        $request = new DispatchRequest($binding->scope, 'root-a', 'attempt-a', 'reference-a', EffectKind::Charge, $operation->requested, AuthorityClaims::bindingDigest($binding), $cap->digest, 'owner-1', 1, 1, 1, 1, $attempt->leaseFence, 'billium.psp_dispatch.v1');
        $databases = $this->application()->make(DatabaseManager::class);
        $this->journalPath = sys_get_temp_dir().'/billium-foundation-host-'.bin2hex(random_bytes(12));
        $host = new DeterministicWriterHost(new DurableJournal($this->journalPath), new FixtureClock(new DateTimeImmutable), static fn (): int => $databases->connection()->transactionLevel());
        $host->openSession($request);
        $this->application()->instance(WriterAdmission::class, $host);
        $this->application()->instance(DispatchEgress::class, $host);
        $guard = $connectionName === null ? $this->application()->make(DispatchAuthority::class)
            : new DispatchAuthority($databases, fn () => $this->runner(), fn () => $this->application()->make(FinancialSessionAdmission::class), fn () => $this->application()->make(FinancialAuthorityEvidence::class), $this->callers, $this->installation(), $store, fn () => $host, fn () => $host, $connectionName);

        return [$store, $operation, $attempt, $request, $host, $guard];
    }

    private function dispatch(ExecutionStore $store, OperationRecord $operation, AttemptRecord $attempt, DispatchTicket $ticket, DispatchAuthority $guard): DispatchDecision
    {
        $decision = null;
        $plan = $store->plan($operation->binding->scope, $operation->operationRef);
        $this->runner()->run($this->spec(financialScope: self::selection(), modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store, $plan, $operation, $attempt, $ticket, $guard, &$decision) {
            $store->lock($ctx, $plan);
            $decision = $guard->validate($ctx, $ticket, $operation, $attempt);
            self::assertTrue($store->startDispatch($ctx, $decision));

            return self::receipt();
        });
        self::assertInstanceOf(DispatchDecision::class, $decision);

        return $decision;
    }

    private function driver(OperationRecord $operation, DispatchDecision $decision, DispatchRequest $request): DriverRequest
    {
        return new DriverRequest($decision, $operation->binding, ExecutionSchemaTest::capabilityFixture(), $operation->requested, $request->providerReference, new ResolvedCredentials('account-a', 'credential-v1', new SecretRef('secret://vault/credential_a'), 'charge', new DateTimeImmutable('+5 minutes'), 'simulated-continuity'));
    }

    private function send(): ProviderLookupResult
    {
        $this->sends++;

        return new ProviderLookupResult(ProviderLookupState::Ambiguous, null, null, null, 'simulated-response-lost', null);
    }

    private static function selection(): FinancialScope
    {
        return new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium');
    }
}

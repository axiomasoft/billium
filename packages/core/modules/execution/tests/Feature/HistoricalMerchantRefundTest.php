<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Data\Envelopes\RequestRefund;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Enums\Modules\ModuleLifecycleState;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\TestEnvironment;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionHistoryQueries;
use Billium\Execution\Contracts\ExecutionQueries;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\AdmissionConflictAudit;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\MerchantRevisionPolicy;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Platform\Data\Installation\ModuleTransitionRequest;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\DurableJournal;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
#[Group('PB-09')]
final class HistoricalMerchantRefundTest extends ExecutionTestCase
{
    public function test_rotated_credentials_refund_only_the_original_account_in_draining_and_replay_after_permission_revocation(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            Refunds::captured($app, $path);
            $old = $app->make(MerchantRegistry::class)->historical($scope, 'root-a');
            $next = SimulatedCapabilities::snapshot('replacement-account');
            $registry = $app->make(DatabaseMerchantRegistry::class);
            $admin = $this->spec(kind: 'execution.rotate', mode: FinancialMode::Administrative, class: ModuleOperationClass::HistoricalRead, modules: ['execution', 'platform']);
            $store = $app->make(ExecutionStore::class);
            $secret = new SecretRef('secret://vault/historical_v2');
            $this->runner()->run($admin, static function (TransactionContext $ctx) use ($next, $old, $registry, $store, $scope, $secret): PlatformReceipt {
                $time = new DateTimeImmutable('-1 minute');
                $registry->appendLifecycle($ctx, $scope, 'merchant-a', 'stop-old', 'stop_new', 'new', $time, null, 'future charges move to new account', 'approved-route-change');
                $registry->appendCredential($ctx, $old, 'rotated-refund', 'rotated-v2', $secret, 'refund', $time, new DateTimeImmutable('+2 days'), 'verified-old-account', 'cred-refund');
                $new = new MerchantRevision('replacement', $scope, $next->merchantAccountRef, 'simulated-provider', $next->driverId, $next->driverContractVersion,
                    $next->snapshotRef, $next->digest, new SecretRef('secret://vault/new_account'), 'new-v1', $next->currencies, $old->digest, $time,
                    new MerchantRevisionPolicy($time, null, $old->revisionRef, 'sim-api-v2', 'replacement-webhook', 'replacement-proof', 'replacement-settlement', 'replacement-mandate'));
                $store->appendMerchantRevision($ctx, $new, $next);
                $registry->appendLifecycle($ctx, $scope, 'replacement', 'enable-replacement', 'enable_new', 'new', $time, null, 'new routing', 'approved-route-change');

                return self::receipt();
            })->orThrow();
            self::assertSame('replacement-account', $registry->forAdmission($scope, 'replacement')->merchantAccountRef);
            $this->installation()->transitionModule(new ModuleTransitionRequest('drain-execution', 'execution', ModuleLifecycleState::Draining, 'hold new sales'))->orThrow();
            self::problem(ProblemCode::RecoveryRequired, fn () => $app->make(RefundCommands::class)->admit($scope, Refunds::refund('old-order', 25, 2)));
            self::assertSame(0, $this->rows('billium_execution_reservation'));
            $unavailable = $this->createMock(MerchantCredentials::class);
            $unavailable->expects(self::once())->method('resolve')->with($old, 'refund')->willThrowException(new RuntimeException('Simulated vault unavailable'));
            $app->instance(MerchantCredentials::class, $unavailable);
            self::problem(ProblemCode::RecoveryRequired, fn () => $app->make(RefundCommands::class)->admit($scope, Refunds::refund('vault-unavailable', 25, 7)));
            $app->instance(MerchantCredentials::class, new readonly class($secret) implements MerchantCredentials
            {
                public function __construct(private SecretRef $secret) {}

                public function resolve(MerchantRevision $revision, string $purpose): ResolvedCredentials
                {
                    return new ResolvedCredentials($revision->merchantAccountRef, 'rotated-v2', $this->secret, $purpose, new DateTimeImmutable('+5 minutes'), 'verified-old-account');
                }
            });
            $commands = $app->make(RefundCommands::class);
            $wire = Refunds::refund('old-order', 25, 2);
            $first = $commands->admit($scope, $wire)->orThrow();
            $tooLarge = Refunds::refund('too-large', 76, 6);
            $refusal = $commands->admit($scope, $tooLarge);
            self::assertSame(ProblemCode::RefundCapacityExceeded, $refusal->problem);
            $app->make(AttemptWorker::class)->dispatch($scope, Refunds::attemptRef('old-order'), 'fulfil-original', new DateTimeImmutable('+1 minute'))->orThrow();
            $sent = array_find(new DurableJournal($path)->records(), static fn (object $e): bool => $e instanceof ProviderRecord && $e->kind === EffectKind::Refund);
            self::assertInstanceOf(ProviderRecord::class, $sent);
            self::assertSame(['root-a', 'merchant-a', 'account-a', 'rotated-v2'], [$sent->request->binding->rootOperationRef, $sent->request->binding->merchantRevisionRef,
                $sent->request->credentials->merchantAccountRef, $sent->request->credentials->credentialVersionRef]);
            $this->runner()->run($admin, static function (TransactionContext $ctx) use ($registry, $scope): PlatformReceipt {
                $registry->appendLifecycle($ctx, $scope, 'merchant-a', 'stop-refunds', 'disable_recovery', 'refund', new DateTimeImmutable, null, 'refund permission revoked', 'approved-refund-revocation');

                return self::receipt();
            })->orThrow();
            $repeatedRefusal = $commands->admit($scope, $tooLarge);
            self::assertSame($refusal->receiptId, $repeatedRefusal->receiptId);
            self::assertSame($refusal->problem, $repeatedRefusal->problem);
            self::assertTrue($repeatedRefusal->replayed);
            self::assertSame($first->body, $commands->admit($scope, $wire)->orThrow()->body);
            $fresh = $commands->admit($scope, Refunds::refund('old-order', 25, 3))->orThrow();
            self::assertSame($first->body['receiptRef'], $fresh->body['receiptRef']);
            self::problem(ProblemCode::RecoveryRequired, fn () => $commands->admit($scope, Refunds::refund('fresh', 10, 4)));
            foreach ([['reasonCode' => 'other'], ['category' => 'other'], ['operationRef' => 'another-child']] as $changes) {
                self::problem(ProblemCode::IdempotencyConflict, fn () => $commands->admit($scope, Refunds::refund('old-order', 25, 5, $changes)));
            }
            $audit = $app->make(ExecutionHistoryQueries::class)->admissionConflicts($scope, 'remote-billium', RequestRefund::SCHEMA, 'checkout', Fixture::key(5));
            self::assertCount(3, $audit);
            self::assertSame(['old-order'], array_values(array_unique(array_map(static fn (AdmissionConflictAudit $conflict): ?string => $conflict->originalOperationRef, $audit))));
            self::assertSame(1, $this->rows('billium_execution_reservation'));
            self::assertSame(2, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_cold_authorized_query_retains_child_terms_original_basis_and_exact_remaining_capacity(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            Refunds::captured($app, $path);
            $commands = $app->make(RefundCommands::class);
            self::problem(ProblemCode::AuthDenied, fn () => $commands->admit($scope, Refunds::refund('bad-order', 55, 2, ['source' => ['context' => 'checkout', 'intentRef' => 'x', 'orderRef' => 'other']])));
            self::problem(ProblemCode::AuthDenied, fn () => $commands->admit($scope, Refunds::refund('bad-source', 55, 3, ['source' => ['context' => 'elsewhere', 'intentRef' => 'x']])));
            $ledgerWrites = [];
            $app->make(DatabaseManager::class)->connection()->listen(static function (QueryExecuted $query) use (&$ledgerWrites): void {
                if (preg_match('/\b(?:insert\s+into|update|delete\s+from)\s+(?:"?public"?\.)?"?billium_(?:allocation|payment|observed_payment|refund_reconcile_progress)/i', $query->sql) === 1) {
                    $ledgerWrites[] = $query->sql;
                }
            });
            $ref = $commands->admit($scope, Refunds::refund('refund-a', 55, 4))->orThrow()->body['receiptRef'];
            self::assertSame([], $ledgerWrites);
            $cold = Fixture::workerApp(150);
            $history = $cold->make(ExecutionHistoryQueries::class);
            $child = $history->childBusiness($scope, 'remote-billium', EffectKind::Refund, 'checkout', 'business-refund-a');
            self::assertSame(['refund-a', 'root-a', 55, 'RUB', 'intent-a', 'order-a', 'business-refund-a', 'returned_goods', 'customer_return', $ref],
                [$child->operation->operationRef, $child->operation->rootOperationRef, $child->operation->requested->minor, $child->operation->requested->currency->code,
                    $child->operation->binding->businessIntentRef, $child->operation->binding->sourceOrderRef, $child->businessIntentRef, $child->reasonCode, $child->category, $child->receiptRef]);
            $view = $cold->make(ExecutionQueries::class)->status($scope, 'refund-a');
            self::assertSame('root-a', $view->rootOperationRef);
            self::assertSame(55, $view->requested->minor);
            self::assertEquals((object) ['amount' => '0.45', 'currency' => 'RUB'], $view->capacity->refundAvailable);
            $foreign = new ExecutionScope($scope->installationRef, 'seller-b', $scope->environment, $scope->intentOwnerNamespace);
            self::problem(ProblemCode::AuthDenied, fn () => $history->childBusiness($foreign, 'remote-billium', EffectKind::Refund, 'checkout', 'business-refund-a'));
            $owner = TestEnvironment::pdo(owner: true);
            foreach (["reason_code='changed'", "category='other'", "business_intent_ref='another'", "root_operation_ref='different'", 'requested_minor=0'] as $alter) {
                self::assertSame('55000', self::sqlState($owner, 'UPDATE billium_execution_child_identity SET '.$alter));
            }
            self::assertSame('23514', self::sqlState($owner, 'UPDATE billium_execution_capacity SET held_refund_minor=-1'));
            self::assertSame('23514', self::sqlState($owner, 'UPDATE billium_execution_capacity SET confirmed_refund_minor=101'));
            self::assertSame(1, $this->rows('billium_execution_child_identity'));
            self::assertSame(0, $this->rows('billium_payment'));
            self::assertSame(0, $this->rows('billium_allocation'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_original_binding_without_a_retained_order_ref_never_substitutes_an_unverified_order(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            Fixture::seedMerchant($app);
            Fixture::host($app, $path);
            $app->make(RootPaymentCommands::class)->admit($scope, Fixture::wire(['source' => ['context' => 'checkout', 'intentRef' => 'intent-a']]))->orThrow();
            $app->make(AttemptWorker::class)->dispatch($scope, Refunds::attemptRef('root-a'), 'sender', new DateTimeImmutable('+1 minute'))->orThrow();
            $work = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE state='due'"));
            self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce($work)->disposition);
            $commands = $app->make(RefundCommands::class);
            self::problem(ProblemCode::RecoveryRequired, fn () => $commands->admit($scope, Refunds::refund('with-order', 20, 2)));
            $commands->admit($scope, Refunds::refund('without-order', 20, 3, ['source' => ['context' => 'checkout', 'intentRef' => 'independent-refund-reference']]))->orThrow();
            self::assertSame(1, $this->rows('billium_execution_child_identity', "operation_ref='without-order' AND NOT jsonb_exists(binding,'sourceOrderRef')"));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'held_refund_minor=20'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    /** @return list<array{string}> */
    public static function modes(): array
    {
        return [['no-refund'], ['full-only'], ['single-only'], ['unqualified']];
    }

    #[DataProvider('modes')]
    public function test_refund_requires_qualified_historical_workflow_before_allocating_an_attempt(string $mode): void
    {
        $path = Fixture::tempPath();
        try {
            $scope = Fixture::scope();
            $app = $this->application();
            $cap = SimulatedCapabilities::snapshot(effects: $mode === 'no-refund' ? [EffectKind::Charge, EffectKind::Authorize, EffectKind::Capture] : null,
                partialRefund: $mode !== 'full-only', multipleRefund: $mode !== 'single-only');
            Refunds::captured($app, $path, $cap);
            Fixture::host($app, $path, cap: $cap, qualified: $mode !== 'unqualified');
            $commands = $app->make(RefundCommands::class);
            $prior = $mode === 'single-only' ? 1 : 0;
            if ($prior === 1) {
                $commands->admit($scope, Refunds::refund('one-allowed', 30, 2))->orThrow();
            }
            self::problem(ProblemCode::CapabilityUnsupported, fn () => $commands->admit($scope, Refunds::refund('not-qualified', 20, 3))->orThrow());
            self::assertSame($prior + 1, $this->rows('billium_execution_attempt'));
            self::assertSame($prior, $this->rows('billium_execution_reservation'));
            self::assertSame($prior, $this->rows('billium_execution_child_identity'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    /** @param callable():mixed $call */
    public static function problem(ProblemCode $expected, callable $call): void
    {
        try {
            $call();
            self::fail('Expected '.$expected->value);
        } catch (ProblemException $failure) {
            self::assertSame($expected, $failure->problem);
        }
    }
}

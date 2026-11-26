<?php

declare(strict_types=1);

namespace Billium\Execution\Tests;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\TestEnvironment;
use Billium\Execution\Contracts\CollectionExecutionGuard;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\CollectionGuardPlan;
use Billium\Execution\Data\CollectionGuardReceipt;
use Billium\Execution\Data\DispatchDecision;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Enums\ExecutionPhase;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\SettlementIntentLocks;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\CloseSettlementIntentNoEffect;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Ledger\Data\Settlements\NoEffectProof;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use LogicException;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class CollectionAdmissionGuardTest extends ExecutionTestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->make(Repository::class)->set('billium.modules.enabled', ['platform', 'ledger', 'execution', 'collection']);
    }

    public static function installStep(Application $app): void
    {
        $account = new BillingAccountScope('seller-a', 'test', 'account-a');
        $ledger = $app->make(Ledger::class);
        $wire = Fixture::wire();
        $ledger->recordObligation(new RecordObligation('declare-obligation', $account, 'obligation-a', 'basis-a', 'customer-a', 'component-a',
            $wire->money, 'sha256:'.str_repeat('b', 64)))->orThrow();
        $ledger->declareSettlementIntent(new DeclareSettlementIntent('declare-settlement', $account, 'settlement-a', 'RUB', ['obligation-a']))->orThrow();
        $owner = TestEnvironment::pdo(owner: true);
        $owner->exec('CREATE TABLE billium_test_collection_step(id bigint primary key,seller_ref text,environment text,step_ref text,expected_digest text,issued_generation int,consent_valid boolean,cancel_generation int,dispatch_permit text,incoming_held boolean)');
        $role = $app->make(DatabaseManager::class)->connection()->scalar('SELECT current_user');
        self::assertIsString($role);
        $owner->exec('GRANT SELECT,UPDATE ON billium_test_collection_step TO "'.str_replace('"', '""', $role).'"');
        $origin = Fixture::origin('step-a');
        $digest = (new Codec)->digest([
            'schema' => 'billium.execution.root-semantics.v1', 'scope' => get_object_vars(Fixture::scope()), 'operationRef' => 'root-a',
            'schemaId' => 'payment.execute.v1', 'logicalKind' => 'charge', 'source' => $wire->source, 'executionProfile' => 'remote-billium', 'routingEpoch' => '1',
            'money' => ['amount' => '1.00', 'currency' => 'RUB'], 'merchantRevisionRef' => 'merchant-a', 'merchantRevisionDigest' => 'sha256:'.str_repeat('b', 64),
            'merchantAccountRef' => 'account-a', 'credentialVersionRef' => 'credential-v1', 'driverId' => 'simulated-driver',
            'driverContractVersion' => 'simulated-driver.v1', 'capabilityDigest' => SimulatedCapabilities::snapshot()->digest, 'returnContextRef' => null,
            'collectionAuthorizationRef' => 'step-a', 'commercialBasisDigest' => $origin->basisDigest, 'policyVersion' => 'terms-v1', 'periodRef' => 'period-a',
        ]);
        $owner->prepare("INSERT INTO billium_test_collection_step VALUES(1,'seller-a','test','step-a',?,1,true,0,null,true)")->execute([$digest]);
    }

    public static function guard(Application $app, bool $declareLedger = true): void
    {
        $app->instance(CollectionExecutionGuard::class, new readonly class($app->make(DatabaseManager::class), $app->make(SettlementIntentLocks::class), $declareLedger) implements CollectionExecutionGuard
        {
            public function __construct(private DatabaseManager $db, private SettlementIntentLocks $settlements, private bool $ledger) {}

            public function plan(string $authorizationRef, ExecutionPhase $phase, ExecutionScope $scope): CollectionGuardPlan
            {
                $row = $this->db->connection()->table('billium_test_collection_step')->where('step_ref', $authorizationRef)->first();
                if ($row === null || get_object_vars($scope) !== get_object_vars(Fixture::scope())) {
                    throw new ProblemException(ProblemCode::AuthDenied, 'Unknown registered step');
                }

                return new CollectionGuardPlan('step-a', is_int($row->issued_generation) ? $row->issued_generation : throw new LogicException('Unusable fixture generation'), new DateTimeImmutable('2026-01-01'), $scope,
                    $this->ledger ? ['collection', 'ledger'] : ['collection'], ModuleOperationClass::FrozenIntentCompletion,
                    [new LockRequest(LockRank::DunningCase, 'billium_test_collection_step', [1]),
                        $this->settlements->plan(new BillingAccountScope($scope->sellerRef, $scope->environment, 'account-a'), 'settlement-a')], 'step-a:plan-v1');
            }

            public function validate(TransactionContext $ctx, CollectionGuardPlan $plan, string $operationRef, string $businessDigest): CollectionGuardReceipt
            {
                $ctx->assertParticipant('collection');
                $ctx->assertParticipant('ledger');
                $ctx->lock(...$plan->locks);
                $row = $this->db->connection($ctx->connectionName())->table('billium_test_collection_step')->where('id', 1)->first() ?? throw new LogicException('Step missing');
                $permitted = $row->consent_valid === true && $row->incoming_held === true && $row->dispatch_permit === null
                    && $row->expected_digest === $businessDigest && $operationRef === 'root-a' && $row->step_ref === $plan->authorizationRef;

                return new CollectionGuardReceipt('step-a', 1, 'consent-v1', is_int($row->cancel_generation) ? $row->cancel_generation : throw new LogicException('Unusable cancellation generation'), $businessDigest, $operationRef, $permitted, $permitted ? null : 'revoked_or_changed');
            }

            public function markDispatch(TransactionContext $ctx, CollectionGuardPlan $plan, DispatchDecision $decision): string
            {
                $ctx->assertParticipant('collection');
                $ref = 'step-permit:'.$decision->ticketRef;
                $changed = $this->db->connection($ctx->connectionName())->table('billium_test_collection_step')->where('id', 1)->where('consent_valid', true)
                    ->whereNull('dispatch_permit')->update(['dispatch_permit' => $ref]);
                if ($changed !== 1) {
                    throw new ProblemException(ProblemCode::RecoveryRequired, 'Collection permit unavailable');
                }

                return $ref;
            }
        });
    }

    public static function revoke(Application $app): void
    {
        $app->make(OperationRunner::class)->run(new OperationSpec('collection.revoke_step', 'seller-a', 'test', FinancialMode::Active,
            ModuleOperationClass::FrozenIntentCompletion, ['collection', 'ledger', 'platform', 'execution'], commitKind: 'collection.revoke',
            financialScope: new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium')),
            static function (TransactionContext $ctx) use ($app): Receipt {
                $ctx->lock(new LockRequest(LockRank::DunningCase, 'billium_test_collection_step', [1]));
                $q = $app->make(DatabaseManager::class)->connection($ctx->connectionName())->table('billium_test_collection_step')->where('id', 1);
                $q->update(['consent_valid' => false, 'cancel_generation' => 1]);
                if ($q->value('dispatch_permit') !== null) {
                    return new PlatformReceipt('collection.revocation', ['status' => 'in_flight']);
                }
                $q->update(['incoming_held' => false]);
                $proof = new NoEffectProof($ctx->rootRef(), 'settlement-a', 1, 'step-a', 'not_dispatched', 'local-owner-revoke');
                $command = new CloseSettlementIntentNoEffect(new BillingAccountScope('seller-a', 'test', 'account-a'), 'settlement-a', 1,
                    'step-a', 'close-step-a', 'RUB', 'consent_revoked', $proof);

                return $app->make(LedgerParticipants::class)->closeSettlementIntentNoEffect($ctx, $command);
            })->orThrow();
    }

    public function test_revoked_step_refuses_current_and_reordered_admission_and_cannot_be_reclassified_by_changing_context(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            Fixture::seedMerchant($app);
            self::installStep($app);
            Fixture::host($app, $path, [Fixture::origin('step-a')]);
            self::guard($app);
            self::revoke($app);
            $commands = $app->make(RootPaymentCommands::class);
            foreach ([1, 2] as $key) {
                $outcome = $commands->admit(Fixture::scope(), Fixture::wire(['commandKey' => Fixture::key($key)]));
                self::assertFalse($outcome->accepted);
                self::assertSame(ProblemCode::OperationFinancialHold, $outcome->problem);
                self::assertNotNull($outcome->receiptId);
            }
            try {
                $commands->admit(Fixture::scope(), Fixture::wire(['source' => ['context' => 'direct', 'intentRef' => 'intent-a']]));
                self::fail('Changed source bypassed original collection step');
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::AuthDenied, $e->problem);
            }
            self::assertSame(0, $this->rows('billium_execution_operation'));
            self::assertSame(0, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(1, $this->rows('billium_settlement_intent_closure'));
            self::assertSame(1, $this->rows('billium_settlement_target'));
            self::assertSame(false, $this->value('SELECT incoming_held FROM billium_test_collection_step'));
        } finally {
            TestEnvironment::pdo(owner: true)->exec('DROP TABLE IF EXISTS billium_test_collection_step');
            UnknownResultTest::clean($path);
        }
    }
}

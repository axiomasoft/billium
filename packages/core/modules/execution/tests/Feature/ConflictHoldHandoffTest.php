<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AuthorizationCommands;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\OutcomeDeliveryTest as Delivery;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Obligations\AdjustObligation;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Platform\Contracts\Outbox\FactualCommandOutbox;
use Billium\Platform\Data\EventContracts\PreparedFactualCommand;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
#[Group('PB-08')]
#[Group('PB-24')]
final class ConflictHoldHandoffTest extends ExecutionTestCase
{
    public function test_authenticated_conflict_preserves_provider_economics_and_blocks_allocation_through_the_qualified_receiver_once(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            $account = new BillingAccountScope('seller-a', 'test', 'billing-a');
            $ledger = $app->make(LedgerParticipants::class);
            $spec = $this->spec(financialScope: new FinancialScope('inst-test', 'seller-a', 'test', 'source-a', 'remote-billium'));
            $this->runner()->run($spec, fn (TransactionContext $ctx) => $ledger->recordObligation($ctx, new RecordObligation('debt', $account,
                'first-debt', 'basis', 'buyer', 'component', Money::parse('0.25', 'RUB'), 'sha256:'.str_repeat('a', 64))))->orThrow();
            $this->runner()->run($spec, fn (TransactionContext $ctx) => $ledger->declareSettlementIntent($ctx,
                new DeclareSettlementIntent('intent-command', $account, 'intent-a', 'RUB', ['first-debt'])))->orThrow();

            $first = CallbackCustodyTest::dispatched($app, $path);
            $reducer = $app->make(EvidenceReducer::class);
            $capture = $reducer->reduce(CallbackCustodyTest::work($first));
            $accepted = Delivery::attest($app, $path, $capture->observationCommandRefs[0]);
            self::assertSame(1, Delivery::relay($app, $path)->delivered);
            $queries = $app->make(LedgerQueries::class);
            self::assertSame('0.75', self::arr($queries->paymentStatus('root-a', 'remote-billium')['spendable'])['amount']);
            $allocation = $app->make(Ledger::class);
            $allocation->adjustObligation(new AdjustObligation('adjust', $account, 'late-usage', 'first-debt', Money::parse('1.00', 'RUB'), 'additional-usage'))->orThrow();
            self::assertTrue($allocation->allocate(new Allocate('before', $account, 'before', 'first-debt', Money::parse('0.10', 'RUB'), paymentRef: 'root-a'))->accepted);
            self::assertSame('0.65', self::arr($queries->paymentStatus('root-a', 'remote-billium')['spendable'])['amount']);

            $zero = Money::parse('0.00', 'RUB')->toWire();
            $conflict = CallbackCustodyTest::signed($first, ['revision' => '8', 'status' => 'declined', 'operationAmount' => $zero, 'captured' => $zero]);
            $bad = new ProviderEvidence($conflict->evidenceRef, $conflict->scope, $conflict->providerId, $conflict->merchantAccountRef, $conflict->providerEventId,
                $conflict->payloadDigest, $conflict->signatureVerdict, $conflict->handlerContractVersion, $conflict->receivedAt,
                array_replace($conflict->sanitizedPayload, ['fixtureSignature' => 'unsigned']), $conflict->securePayloadRef);
            $foreign = CallbackCustodyTest::signed($first, ['revision' => '11', 'operationRef' => 'other-root']);
            foreach ([$bad, $foreign] as $untrusted) {
                try {
                    $app->make(CallbackCustody::class)->receive(Fixture::scope(), 'merchant-a', 'root-a', $untrusted);
                    self::fail('Invalid input reached financial reduction');
                } catch (ProblemException $error) {
                    self::assertSame(ProblemCode::AuthDenied, $error->problem);
                }
            }
            self::assertSame(2, $this->rows('billium_execution_provider_work', "state='quarantined'"));
            self::assertSame(0, $this->rows('billium_execution_discrepancy'));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'NOT financial_hold AND confirmed_capture_minor=100'));
            self::assertSame(1, $this->rows('billium_outbox_command'));

            $result = AuthorizationLifecycleTest::receive($app, 'root-a', $conflict);
            self::assertSame(ReductionDisposition::Quarantined, $result->disposition);
            self::assertCount(1, $result->observationCommandRefs);
            self::assertSame([], $result->publicEventIds);
            $command = $result->observationCommandRefs[0];
            $held = Delivery::attest($app, $path, $command);
            self::assertSame([2, 2, 'succeeded', 100, 100, 0, $accepted->evidenceRef], [$held->sourceSequence, $held->operationRevision, $held->state,
                $held->authorizedTotal->minor, $held->capturedTotal->minor, $held->refundedTotal->minor, $held->evidenceRef]);
            self::assertSame($result->discrepancyRef, $held->discrepancy?->discrepancyRef);
            self::assertSame(1, $this->rows('billium_execution_observation_emission', "command_ref=? AND payload#>>'{discrepancy,verifiedExternalFacts,0,evidenceRef}'=? AND payload#>>'{discrepancy,verifiedExternalFacts,0,disposition}'='quarantined'", [$command, $conflict->evidenceRef]));
            self::assertSame(0, $this->rows('billium_execution_provider_fact', 'evidence_refs @> ?::jsonb', [json_encode([$conflict->evidenceRef], JSON_THROW_ON_ERROR)]));
            self::assertSame(1, $this->rows('billium_execution_operation', "revision=2 AND state='succeeded'"));
            self::assertSame(1, $this->rows('billium_execution_attempt', "state='succeeded'"));
            self::assertSame(1, $this->rows('billium_execution_operation_revision', "revision=2 AND observed->>'schema'='billium.execution.quarantine-hold.v1' AND observed->'authorizedTotal' IS NULL"));
            self::assertSame(1, Delivery::relay($app, $path)->delivered);
            $status = $queries->paymentStatus('root-a', 'remote-billium');
            self::assertSame('held_discrepancy', $status['projectionStatus']);
            self::assertSame('0.00', self::arr($status['spendable'])['amount']);
            self::assertSame(ProblemCode::AllocationReallocationRequired, $allocation->allocate(new Allocate('after', $account, 'after', 'first-debt', Money::parse('0.10', 'RUB'), paymentRef: 'root-a'))->problem);

            Delivery::duplicateDelivery($app, $command);
            self::assertSame([], $reducer->reduce(CallbackCustodyTest::work($conflict))->observationCommandRefs);
            $repeat = CallbackCustodyTest::signed($conflict, ['stableEffectId' => 'fresh-delivery-of-disputed-decline']);
            $again = AuthorizationLifecycleTest::receive($app, 'root-a', $repeat);
            self::assertSame([$result->discrepancyRef, []], [$again->discrepancyRef, $again->observationCommandRefs]);
            self::assertSame(1, $this->rows('billium_execution_discrepancy'));
            self::assertSame(1, $this->rows('billium_execution_observation_partition', 'last_sequence=2'));
            self::assertSame(1, $this->rows('billium_payment', 'financial_hold AND captured_minor=100 AND refunded_minor=0 AND allocated_minor=35 AND applied_revision=1'));
            self::assertSame(2, $this->rows('billium_allocation'));
            self::assertSame(1, $this->rows('billium_ledger_discrepancy', "reason='source_reported' AND hold"));
            self::assertSame(2, $this->rows('billium_observed_payment_revision'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
            if (is_file($path.'.origins')) {
                unlink($path.'.origins');
            }
        }
    }

    public function test_hold_outbox_failure_rolls_back_discrepancy_revision_and_counter_and_retries_from_the_original_work(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            $first = CallbackCustodyTest::dispatched($app, $path);
            $app->make(EvidenceReducer::class)->reduce(CallbackCustodyTest::work($first));
            $bad = CallbackCustodyTest::signed($first, ['revision' => '4', 'status' => 'declined', 'operationAmount' => Money::parse('0.00', 'RUB')->toWire()]);
            $app->make(CallbackCustody::class)->receive(Fixture::scope(), 'merchant-a', 'root-a', $bad)->orThrow();
            $work = CallbackCustodyTest::work($bad);
            $real = $app->make(FactualCommandOutbox::class);
            $app->instance(FactualCommandOutbox::class, new readonly class($real) implements FactualCommandOutbox
            {
                public function __construct(private FactualCommandOutbox $real) {}

                public function record(TransactionContext $context, PreparedFactualCommand $command): int
                {
                    $this->real->record($context, $command);
                    throw new RuntimeException('rollback_after_hold_outbox');
                }
            });
            try {
                $app->make(EvidenceReducer::class)->reduce($work);
                self::fail('Missing hold command in the financial transaction');
            } catch (RuntimeException $e) {
                self::assertSame('rollback_after_hold_outbox', $e->getMessage());
            }
            self::assertSame(0, $this->rows('billium_execution_discrepancy'));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'NOT financial_hold AND confirmed_capture_minor=100'));
            self::assertSame(1, $this->rows('billium_execution_operation_revision'));
            self::assertSame(1, $this->rows('billium_execution_observation_partition', 'last_sequence=1'));
            self::assertSame(1, $this->rows('billium_outbox_command'));
            self::assertSame(1, $this->rows('billium_execution_provider_work', "work_ref=? AND state='due' AND last_error_code='financial_processing_deferred'", [$work]));
            $app->instance(FactualCommandOutbox::class, $real);
            $this->owner()->update('UPDATE billium_execution_provider_work SET due_at=clock_timestamp() WHERE work_ref=?', [$work]);
            $retry = $app->make(EvidenceReducer::class)->reduce($work);
            self::assertCount(1, $retry->observationCommandRefs);
            self::assertSame(1, $this->rows('billium_execution_observation_emission', 'source_sequence=2 AND operation_revision=2'));
            self::assertSame(1, $this->rows('billium_execution_provider_work', "state='quarantined' AND work_ref=? AND result_ref=?", [$work, $retry->discrepancyRef]));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_newer_pending_without_actual_amount_constrains_an_older_success_and_reports_only_local_confirmed_capacity(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            $first = CallbackCustodyTest::dispatched($app, $path);
            $zero = Money::parse('0.00', 'RUB')->toWire();
            $pending = CallbackCustodyTest::signed($first, ['revision' => '12', 'status' => 'pending', 'authorized' => $zero, 'captured' => $zero, 'operationAmount' => null]);
            $seen = AuthorizationLifecycleTest::receive($app, 'root-a', $pending);
            self::assertSame([ReductionDisposition::Applied, []], [$seen->disposition, $seen->observationCommandRefs]);
            self::assertSame(0, $this->rows('billium_execution_operation_revision'));
            $older = CallbackCustodyTest::signed($first, ['revision' => '11']);
            $held = AuthorizationLifecycleTest::receive($app, 'root-a', $older);
            self::assertSame(ReductionDisposition::Quarantined, $held->disposition);
            self::assertCount(1, $held->observationCommandRefs);
            self::assertSame(1, $this->rows('billium_execution_provider_fact', "normalized_snapshot->>'providerRevision'='12' AND normalized_snapshot->>'operationAmount' IS NULL"));
            self::assertSame(0, $this->rows('billium_execution_provider_fact', "normalized_snapshot->>'providerRevision'='11'"));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'financial_hold AND confirmed_capture_minor=0'));
            self::assertSame(1, $this->rows('billium_execution_observation_emission', "payload->>'evidenceRef'=? AND payload#>>'{capturedTotal,amount}'='0.00' AND payload#>>'{discrepancy,kind}'='older_revision_increases_totals' AND payload#>>'{discrepancy,verifiedExternalFacts,0,totalsBasis}'='local_confirmed_capacity'", [$held->discrepancyRef]));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_identical_newer_cumulative_fact_on_one_child_constrains_the_next_siblings_older_increase(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            AuthorizationLifecycleTest::authorize($app, $path);
            $commands = $app->make(AuthorizationCommands::class);
            $commands->admit(Fixture::scope(), AuthorizationLifecycleTest::child('capture-a', 30))->orThrow();
            AuthorizationLifecycleTest::dispatchAndReduce($app, 'capture-a');
            $first = AuthorizationLifecycleTest::evidence($app, 'capture-a');
            $same = CallbackCustodyTest::signed($first, ['revision' => '20']);
            self::assertSame(ReductionDisposition::Duplicate, AuthorizationLifecycleTest::receive($app, 'capture-a', $same)->disposition);
            self::assertSame(2, $this->rows('billium_execution_observation_emission'));
            $commands->admit(Fixture::scope(), AuthorizationLifecycleTest::child('capture-b', 40))->orThrow();
            AuthorizationLifecycleTest::dispatch($app, 'capture-b');
            $older = CallbackCustodyTest::signed(AuthorizationLifecycleTest::evidence($app, 'capture-b'), ['revision' => '19']);
            $held = AuthorizationLifecycleTest::receive($app, 'capture-b', $older);
            self::assertSame(ReductionDisposition::Quarantined, $held->disposition);
            self::assertCount(1, $held->observationCommandRefs);
            self::assertSame(0, $this->rows('billium_execution_provider_fact', "operation_ref='capture-b'"));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=30 AND held_capture_minor=40 AND financial_hold'));
            self::assertSame(1, $this->rows('billium_execution_observation_emission', "operation_ref='root-a' AND operation_revision=3 AND payload#>>'{capturedTotal,amount}'='0.30' AND payload#>>'{discrepancy,kind}'='older_revision_increases_totals'"));
            self::assertSame(1, $this->rows('billium_outbox_event', "type='payment.effect.outcome.v1'"));
        } finally {
            UnknownResultTest::clean($path);
        }
    }
}

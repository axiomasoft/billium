<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AuthorizationCommands;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionQueries;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ReductionResult;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\OutcomeDeliveryTest as Delivery;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest;
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
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

#[Group('pg')]
final class LateChildHoldHandoffTest extends ExecutionTestCase
{
    #[TestWith(['capture'])]
    #[TestWith(['refund'])]
    #[TestWith(['void_authorization'])]
    #[TestWith(['capture', 'already-held'])]
    #[TestWith(['capture', 'rollback'])]
    #[TestWith(['capture', 'valid'])]
    #[TestWith(['capture', 'sibling-frontier'])]
    #[TestWith(['capture', 'held-declined-child'])]
    public function test_qualified_late_child_preserves_the_newer_root_and_hands_off_a_genuine_hold(string $kind, string $case = 'held'): void
    {
        $app = $this->application();
        $scope = Fixture::scope();
        $path = Fixture::tempPath();
        try {
            $account = new BillingAccountScope('seller-a', 'test', 'billing-a');
            $this->declareIntent($app, $account);
            $refund = $kind === 'refund';
            $void = $kind === 'void_authorization';
            $ref = 'late-child';
            if ($refund) {
                $first = CallbackCustodyTest::dispatched($app, $path);
                $initial = $app->make(EvidenceReducer::class)->reduce(CallbackCustodyTest::work($first));
            } else {
                $initial = AuthorizationLifecycleTest::authorize($app, $path);
            }
            $this->deliver($app, $path, $initial);
            if ($refund) {
                $app->make(RefundCommands::class)->admit($scope, RefundCapacityTest::refund($ref, 20, 81))->orThrow();
            } else {
                $app->make(AuthorizationCommands::class)->admit($scope, AuthorizationLifecycleTest::child($ref, $void ? 10 : 20, $void))->orThrow();
            }
            AuthorizationLifecycleTest::dispatch($app, $ref);
            $captured = $refund ? 100 : 60;
            $refunded = $refund ? 60 : 0;
            $released = $void ? 20 : 0;
            $source = 'root-a';
            $frontierTerms = ['revision' => '20', 'captured' => self::wire($captured), 'refunded' => self::wire($refunded), 'voided' => self::wire($released)];
            if ($case === 'sibling-frontier') {
                // The declined sibling reports the newer cumulative, while the root still owns its successful status.
                $source = 'other-child';
                $app->make(AuthorizationCommands::class)->admit($scope, AuthorizationLifecycleTest::child($source, 5))->orThrow();
                AuthorizationLifecycleTest::dispatch($app, $source);
                $frontierTerms += ['status' => 'declined', 'operationAmount' => self::wire(0)];
            }
            $rootReport = CallbackCustodyTest::signed(AuthorizationLifecycleTest::evidence($app, $source), $frontierTerms);
            $rootResult = AuthorizationLifecycleTest::receive($app, $source, $rootReport);
            self::assertSame(ReductionDisposition::Applied, $rootResult->disposition);
            [$prior] = $this->deliver($app, $path, $rootResult);
            $queries = $app->make(LedgerQueries::class);
            $spendable = fn (): mixed => self::arr($queries->paymentStatus('root-a', 'remote-billium')['spendable'])['amount'];
            $remaining = $refund ? '0.30' : '0.50';
            self::assertSame($remaining, $spendable());
            $db = $app->make(DatabaseManager::class)->connection();
            $previousDiscrepancy = null;
            $frontierRevision = '20';
            $publishedCapture = $captured;
            $reason = 'operation_amount_exceeds_request';
            if ($case === 'already-held') {
                $contradiction = CallbackCustodyTest::signed($rootReport, ['revision' => '18', 'captured' => self::wire(70)]);
                $quarantined = AuthorizationLifecycleTest::receive($app, 'root-a', $contradiction);
                self::assertSame(ReductionDisposition::Quarantined, $quarantined->disposition);
                [$prior] = $this->deliver($app, $path, $quarantined);
                $previousDiscrepancy = $quarantined->discrepancyRef;
                self::assertSame('0.00', $spendable());
            }
            if ($case === 'held-declined-child') {
                // Ninety captured and twenty reserved genuinely overlap. Sixty was last applied.
                $rootReport = CallbackCustodyTest::signed($rootReport, ['revision' => '21', 'captured' => self::wire(90)]);
                $alreadyHeld = AuthorizationLifecycleTest::receive($app, 'root-a', $rootReport);
                self::assertSame(ReductionDisposition::HeldDiscrepancy, $alreadyHeld->disposition);
                [$prior] = $this->deliver($app, $path, $alreadyHeld);
                self::assertSame('held', $db->table('billium_execution_operation')->where('operation_ref', 'root-a')->value('state'));
                self::assertSame('succeeded', $prior->state);
                self::assertSame('0.00', $spendable());
                $previousDiscrepancy = $alreadyHeld->discrepancyRef;
                $publishedCapture = 90;
                $frontierRevision = '21';
                $reason = 'unresolved_financial_hold';
            }
            $declined = $case === 'held-declined-child';
            $actual = $declined ? 0 : ($void ? 15 : ($case === 'valid' ? 20 : 30));
            $older = CallbackCustodyTest::signed(AuthorizationLifecycleTest::evidence($app, $ref), [
                'revision' => '19', 'captured' => self::wire($refund ? 100 : 30), 'refunded' => self::wire($refund ? 30 : 0),
                'voided' => self::wire($void ? 15 : 0), 'operationAmount' => self::wire($actual), 'status' => $declined ? 'declined' : 'succeeded',
            ]);
            if ($case === 'rollback') {
                $this->rollbackAndRetry($app, $older, $ref);
            }
            $result = AuthorizationLifecycleTest::receive($app, $ref, $older);
            if ($case === 'valid') {
                self::assertSame(ReductionDisposition::Applied, $result->disposition);
                self::assertCount(0, $result->observationCommandRefs);
                self::assertCount(1, $result->publicEventIds);
                self::assertSame(1, $this->rows('billium_execution_reservation', "state='consumed' AND consumed_minor=20"));
                self::assertSame(1, $this->rows('billium_execution_capacity', 'NOT financial_hold AND held_capture_minor=0 AND confirmed_capture_minor=60'));
                self::assertSame($remaining, $spendable());

                return;
            }
            self::assertSame(ReductionDisposition::HeldDiscrepancy, $result->disposition);
            $held = $db->table('billium_execution_capacity')->sole();
            self::assertSame([true, $captured, $refunded, $released], [$held->financial_hold, $held->confirmed_capture_minor, $held->confirmed_refund_minor, $held->released_authorization_minor]);
            self::assertSame(1, $this->rows('billium_execution_reservation', "operation_ref=? AND state='held' AND consumed_minor=0 AND release_evidence_ref IS NULL", [$ref]));
            self::assertSame(1, $this->rows('billium_execution_discrepancy', "discrepancy_ref=? AND operation_ref=? AND exposure->>'reason'=? AND observed->>'providerRevision'='19' AND evidence_refs->>0=?", [$result->discrepancyRef, $ref, $reason, $older->evidenceRef]));
            if ($previousDiscrepancy !== null) {
                self::assertSame(1, $this->rows('billium_execution_discrepancy', 'discrepancy_ref=?', [$previousDiscrepancy]));
            }
            self::assertCount($refund ? 2 : 1, $result->observationCommandRefs);
            self::assertCount($refund ? 0 : 1, $result->publicEventIds);
            $execution = $app->make(ExecutionQueries::class);
            $before = $execution->status($scope, $ref)->delivery;
            self::assertSame($result->observationCommandRefs, $before->observationCommandRefs);
            self::assertFalse($before->projectionApplied);
            if ($refund) {
                $detail = $result->observationCommandRefs[1];
                Delivery::attest($app, $path, $detail);
                AuthorizationLifecycleTest::refuses(ProblemCode::ObservationParked, static fn () => Delivery::duplicateDelivery($app, $detail));
                self::assertFalse($execution->status($scope, $ref)->delivery->projectionApplied);
            }
            $messages = $this->deliver($app, $path, $result);
            $after = $execution->status($scope, $ref)->delivery;
            self::assertSame($result->observationCommandRefs, $after->observationCommandRefs);
            self::assertTrue($after->projectionApplied);
            $root = $messages[0];
            self::assertSame('root-a', $root->operationRef);
            self::assertSame($prior->state, $root->state);
            self::assertSame($prior->operationRevision + 1, $root->operationRevision);
            self::assertSame($prior->sourceSequence + 1, $root->sourceSequence);
            self::assertSame([$prior->sourceAuthority, $prior->sourceLineage, $prior->partitionRef], [$root->sourceAuthority, $root->sourceLineage, $root->partitionRef]);
            self::assertSame([$publishedCapture, $refunded, $rootReport->evidenceRef], [$root->capturedTotal->minor, $root->refundedTotal->minor, $root->evidenceRef]);
            self::assertSame($result->discrepancyRef, $root->discrepancy?->discrepancyRef);
            self::assertSame(1, $this->rows('billium_execution_observation_emission',
                "operation_ref='root-a' AND operation_revision=? AND payload#>>'{discrepancy,verifiedExternalFacts,0,evidenceRef}'=?", [$root->operationRevision, $older->evidenceRef]));
            self::assertSame(1, $this->rows('billium_execution_operation_revision',
                "operation_ref='root-a' AND revision=? AND observed->>'providerRevision'=? AND evidence_refs->>0=? AND exposure->>'discrepancyRef'=?", [$root->operationRevision, $frontierRevision, $rootReport->evidenceRef, $result->discrepancyRef]));
            self::assertSame(1, $this->rows('billium_execution_operation_revision', "operation_ref=? AND observed->>'providerRevision'='19' AND evidence_refs->>0=?", [$ref, $older->evidenceRef]));
            if ($refund) {
                self::assertSame([$ref, $older->evidenceRef, $actual, $root->sourceSequence + 1], [$messages[1]->operationRef, $messages[1]->evidenceRef, $messages[1]->capturedTotal->minor, $messages[1]->sourceSequence]);
            }
            $payment = $db->table('billium_payment')->where('operation_ref', 'root-a')->sole();
            self::assertSame([$captured, $refunded, 10, true], [$payment->captured_minor, $payment->refunded_minor, $payment->allocated_minor, $payment->financial_hold]);
            self::assertSame('0.00', $spendable());
            $revisions = $this->rows('billium_execution_operation_revision');
            $commands = $this->rows('billium_outbox_command');
            $ledgerRevisions = $this->rows('billium_observed_payment_revision');
            $repeat = AuthorizationLifecycleTest::receive($app, $ref, $older);
            self::assertSame(ReductionDisposition::Duplicate, $repeat->disposition);
            foreach ($result->observationCommandRefs as $command) {
                Delivery::duplicateDelivery($app, $command);
            }
            self::assertSame([$revisions, $commands, $ledgerRevisions], [$this->rows('billium_execution_operation_revision'), $this->rows('billium_outbox_command'), $this->rows('billium_observed_payment_revision')]);
            self::assertSame($case === 'sibling-frontier' ? 3 : 2, UnknownResultTest::events($path, 'provider.call'));
            fwrite(STDOUT, json_encode(['kind' => $kind, 'case' => $case, 'triggerRevision' => '19', 'rootCaptured' => $payment->captured_minor,
                'rootRefunded' => $payment->refunded_minor, 'allocated' => $payment->allocated_minor, 'spendable' => $spendable(),
                'commands' => count($result->observationCommandRefs), 'publicEvents' => count($result->publicEventIds), 'providerCalls' => UnknownResultTest::events($path, 'provider.call')], JSON_THROW_ON_ERROR).PHP_EOL);
        } finally {
            UnknownResultTest::clean($path);
            if (is_file($path.'.origins')) {
                unlink($path.'.origins');
            }
        }
    }

    #[TestWith(['declined'])]
    #[TestWith(['succeeded'])]
    #[TestWith(['pending'])]
    #[TestWith(['unknown'])]
    public function test_journal_only_sibling_revision_never_discards_a_late_child_effect(string $siblingStatus): void
    {
        $app = $this->application();
        $scope = Fixture::scope();
        $path = Fixture::tempPath();
        $account = new BillingAccountScope('seller-a', 'test', 'billing-a');
        $provisional = in_array($siblingStatus, ['pending', 'unknown'], true);
        try {
            $this->declareIntent($app, $account);
            $this->deliver($app, $path, AuthorizationLifecycleTest::authorize($app, $path));
            $children = $app->make(AuthorizationCommands::class);
            if (! $provisional) {
                $children->admit($scope, AuthorizationLifecycleTest::child('cap-base', 60))->orThrow();
                $this->deliver($app, $path, AuthorizationLifecycleTest::dispatchAndReduce($app, 'cap-base'));
            }
            $base = $siblingStatus === 'succeeded' ? 'cap-base' : 'other-child';
            if ($base === 'other-child') {
                $children->admit($scope, AuthorizationLifecycleTest::child($base, 10))->orThrow();
                AuthorizationLifecycleTest::dispatch($app, $base);
            }
            $children->admit($scope, AuthorizationLifecycleTest::child('late-child', 20))->orThrow();
            AuthorizationLifecycleTest::dispatch($app, 'late-child');
            $sibling = CallbackCustodyTest::signed(AuthorizationLifecycleTest::evidence($app, $base), [
                'revision' => '20', 'status' => $siblingStatus, 'captured' => self::wire($provisional ? 20 : 60),
                'operationAmount' => $provisional ? null : self::wire($base === 'cap-base' ? 60 : 0),
            ]);
            $journalOnly = AuthorizationLifecycleTest::receive($app, $base, $sibling);
            self::assertSame($base === 'cap-base' ? ReductionDisposition::Duplicate : ReductionDisposition::Applied, $journalOnly->disposition);
            self::assertSame([], $journalOnly->observationCommandRefs);
            self::assertSame(1, $this->rows('billium_execution_provider_fact', "operation_ref=? AND normalized_snapshot->>'providerRevision'='20'", [$base]));
            self::assertSame(0, $this->rows('billium_execution_operation_revision', "operation_ref='root-a' AND observed->>'providerRevision'='20'"));

            $older = CallbackCustodyTest::signed(AuthorizationLifecycleTest::evidence($app, 'late-child'), [
                'revision' => '19', 'captured' => self::wire($provisional ? 20 : 60),
                'operationAmount' => self::wire($provisional ? 20 : 30),
            ]);
            $late = AuthorizationLifecycleTest::receive($app, 'late-child', $older);
            self::assertCount(1, $late->observationCommandRefs);
            self::assertCount(1, $late->publicEventIds);
            $this->deliver($app, $path, $late);
            if ($provisional) {
                self::assertSame(ReductionDisposition::Applied, $late->disposition);
                self::assertSame(1, $this->rows('billium_execution_capacity', 'NOT financial_hold AND confirmed_capture_minor=20 AND held_capture_minor=10'));
                self::assertSame(1, $this->rows('billium_execution_reservation', "operation_ref='late-child' AND state='consumed' AND consumed_minor=20"));
                self::assertSame(1, $this->rows('billium_execution_reservation', "operation_ref='other-child' AND state='held'"));
                self::assertSame(1, $this->rows('billium_payment', "operation_ref='root-a' AND captured_minor=20 AND NOT financial_hold"));
            } else {
                self::assertSame(ReductionDisposition::HeldDiscrepancy, $late->disposition);
                self::assertSame(1, $this->rows('billium_execution_discrepancy', "operation_ref='late-child' AND exposure->>'reason'='operation_amount_exceeds_request'"));
                self::assertSame(1, $this->rows('billium_execution_capacity', 'financial_hold AND confirmed_capture_minor=60 AND held_capture_minor=20'));
                self::assertSame(1, $this->rows('billium_payment', "operation_ref='root-a' AND captured_minor=60 AND financial_hold"));
                $ledger = $app->make(Ledger::class);
                $ledger->adjustObligation(new AdjustObligation('adjust', $account, 'late-usage', 'debt', Money::parse('0.60', 'RUB'), 'additional-usage'))->orThrow();
                $allocation = $ledger->allocate(new Allocate('after', $account, 'after', 'debt', Money::parse('0.10', 'RUB'), paymentRef: 'root-a'));
                self::assertSame(ProblemCode::AllocationReallocationRequired, $allocation->problem);
            }
            self::assertSame(ReductionDisposition::Duplicate, AuthorizationLifecycleTest::receive($app, 'late-child', $older)->disposition);
        } finally {
            UnknownResultTest::clean($path);
            if (is_file($path.'.origins')) {
                unlink($path.'.origins');
            }
        }
    }

    private function declareIntent(Application $app, BillingAccountScope $account): void
    {
        $ledger = $app->make(LedgerParticipants::class);
        $spec = $this->spec(financialScope: new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium'));
        $this->runner()->run($spec, static fn (TransactionContext $ctx) => $ledger->recordObligation($ctx, new RecordObligation('debt', $account, 'debt', 'basis', 'buyer', 'component', Money::parse('0.10', 'RUB'), 'sha256:'.str_repeat('a', 64))))->orThrow();
        $this->runner()->run($spec, static fn (TransactionContext $ctx) => $ledger->declareSettlementIntent($ctx, new DeclareSettlementIntent('intent-command', $account, 'intent-a', 'RUB', ['debt'])))->orThrow();
    }

    /** @return list<PaymentObservation> */
    private function deliver(Application $app, string $path, ReductionResult $result): array
    {
        $messages = [];
        foreach ($result->observationCommandRefs as $ref) {
            $messages[] = Delivery::attest($app, $path, $ref);
        }
        $delivered = 0;
        for ($i = 0; $i < count($messages) && $delivered < count($messages); $i++) {
            $delivered += Delivery::relay($app, $path)->delivered;
        }
        self::assertSame(count($messages), $delivered);

        return $messages;
    }

    /** @return array<string,string> */
    private static function wire(int $minor): array
    {
        return new Money($minor, Currency::of('RUB'))->toWire();
    }

    private function rollbackAndRetry(Application $app, ProviderEvidence $older, string $ref): void
    {
        $app->make(CallbackCustody::class)->receive(Fixture::scope(), 'merchant-a', $ref, $older)->orThrow();
        $work = CallbackCustodyTest::work($older);
        $real = $app->make(FactualCommandOutbox::class);
        $app->instance(FactualCommandOutbox::class, new readonly class($real) implements FactualCommandOutbox
        {
            public function __construct(private FactualCommandOutbox $real) {}

            public function record(TransactionContext $context, PreparedFactualCommand $command): int
            {
                $this->real->record($context, $command);
                throw new RuntimeException('rollback_after_late_child_root_command');
            }
        });
        try {
            $app->make(EvidenceReducer::class)->reduce($work);
            self::fail('Missing late child root hold command');
        } catch (RuntimeException $e) {
            self::assertSame('rollback_after_late_child_root_command', $e->getMessage());
        } finally {
            $app->instance(FactualCommandOutbox::class, $real);
        }
        self::assertSame(0, $this->rows('billium_execution_discrepancy'));
        self::assertSame(0, $this->rows('billium_execution_provider_fact', 'operation_ref=?', [$ref]));
        self::assertSame(0, $this->rows('billium_execution_operation_revision', 'operation_ref=?', [$ref]));
        self::assertSame(2, $this->rows('billium_execution_operation_revision', "operation_ref='root-a'"));
        self::assertSame(1, $this->rows('billium_execution_capacity', 'NOT financial_hold AND held_capture_minor=20 AND confirmed_capture_minor=60'));
        self::assertSame(1, $this->rows('billium_execution_observation_partition', 'last_sequence=2'));
        self::assertSame(2, $this->rows('billium_outbox_command'));
        self::assertSame(1, $this->rows('billium_execution_provider_work', "work_ref=? AND state='due'", [$work]));
        $this->owner()->update('UPDATE billium_execution_provider_work SET due_at=clock_timestamp() WHERE work_ref=?', [$work]);
    }
}

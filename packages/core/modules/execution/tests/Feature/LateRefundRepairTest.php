<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RepairCommands;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\RecoveryAction;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Support\CertifiesAbsentRefund;
use Billium\Platform\Contracts\Outbox\Outbox;
use Billium\Platform\Data\EventContracts\RegisteredPublicEvent;
use Billium\Platform\Data\Outbox\OutboxEvent;
use Billium\Platform\Internal\EventContracts\DatabaseEventContractRegistrar;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
#[Group('ACC-RST-12')]
final class LateRefundRepairTest extends ExecutionTestCase
{
    use CertifiesAbsentRefund;

    /** @return array{string,ProviderEvidence,ProviderEvidence} */
    private function lateRefundReview(string $path): array
    {
        $app = $this->application();
        $scope = Fixture::scope();
        Refunds::captured($app, $path);
        $commands = $app->make(RefundCommands::class);
        $commands->admit($scope, Refunds::refund('old', 70, 2))->orThrow();
        $this->certifyAbsentAndAdoptNewSession($path);
        self::assertSame(1, $this->rows('billium_execution_operation', "operation_ref='old' AND state='failed'"));
        $commands->admit($scope, Refunds::refund('replacement', 70, 3))->orThrow();
        $app->make(AttemptWorker::class)->dispatch($scope, Refunds::attemptRef('replacement'), 'replacement', new DateTimeImmutable('+1 minute'))->orThrow();
        $work = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint='replacement' AND state='due'"));
        $input = $app->make(ExecutionStore::class)->providerWork($work);
        self::assertNotNull($input);
        self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce($work)->disposition);
        $late = CallbackCustodyTest::signed($input->evidence, ['revision' => '18', 'operationRef' => 'old', 'stableEffectId' => 'late-paid-refund',
            'providerOperationId' => 'late-original-provider-operation', 'reference' => self::text($this->value("SELECT provider_reference FROM billium_execution_attempt WHERE operation_ref='old'")),
            'refunded' => ['amount' => '1.40', 'currency' => 'RUB'], 'operationAmount' => ['amount' => '0.70', 'currency' => 'RUB']]);
        InventoryReconciliationTest::grant($this->callers);
        InventoryReconciliationTest::enableInventory($app, $path);
        InventoryReconciliationTest::page($path, [$late]);
        InventoryReconciliationTest::scan($app, 'refund-review', new DateTimeImmutable('-1 hour'), new DateTimeImmutable);
        $review = self::text($this->value("SELECT event_ref FROM billium_execution_recon_review WHERE code='material_conflict' AND status='open' AND root_ref='root-a' AND evidence_ref=?", [$late->evidenceRef]));
        self::assertSame(1, $this->rows('billium_execution_recon_item', "review_ref=? AND evidence_ref=? AND disposition='held_discrepancy' AND operation_ref='old'", [$review, $late->evidenceRef]));
        $this->assertHeld($path);

        return [$review, $late, $input->evidence];
    }

    private function assertHeld(string $path): void
    {
        self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND confirmed_refund_minor=70 AND held_refund_minor=0 AND financial_hold'));
        self::assertSame(1, $this->rows('billium_execution_operation_revision', "operation_ref='root-a' AND (observed->'refundedTotal'->>'minor')::int=140 AND (exposure->'overRefunded'->>'minor')::int=40 AND (last_consistent_applied->'confirmedRefund'->>'minor')::int=70"));
        self::assertSame(1, $this->rows('billium_execution_operation_revision', "operation_ref='old' AND observed->>'normalizedStatus'='succeeded' AND (observed->'operationAmount'->>'minor')::int=70"));
        self::assertSame(1, $this->rows('billium_execution_reservation', "operation_ref='old' AND state='released' AND consumed_minor=0 AND release_evidence_ref IN (SELECT evidence_ref FROM billium_execution_certified_absence)"));
        self::assertSame(1, $this->rows('billium_execution_reservation', "operation_ref='replacement' AND state='consumed' AND consumed_minor=70"));
        self::assertSame(3, $this->rows('billium_execution_attempt'));
        self::assertSame(0, $this->rows('billium_allocation'));
        self::assertSame(3, UnknownResultTest::events($path, 'provider.call'));
    }

    /** @param list<string> $evidence */
    private function action(string $key, string $review, array $evidence, string $reason = 'Confirmed both paid refunds against the original release. Exposure remains held for financial correction.'): RecoveryAction
    {
        $scope = Fixture::scope();
        $actor = $this->callers->current()->actorRef;
        $digest = PaymentIdentity::digest(['schema' => 'billium.execution.repair.v1', 'scope' => get_object_vars($scope), 'source' => 'remote-billium',
            'root' => 'root-a', 'review' => $review, 'action' => 'resolve_discrepancy', 'actor' => $actor, 'reason' => $reason, 'evidence' => $evidence]);

        return new RecoveryAction($key, $scope, 'resolve_discrepancy', $evidence, $actor, $reason, $digest, 'root-a');
    }

    /** @return array<string,mixed> */
    private function financialHistory(): array
    {
        $history = [];
        foreach (['provider_evidence', 'provider_fact', 'certified_absence', 'operation', 'attempt', 'reservation', 'operation_revision', 'discrepancy', 'capacity', 'observation_emission'] as $name) {
            $history[$name] = $this->value('SELECT jsonb_agg(to_jsonb(t) ORDER BY id)::text FROM billium_execution_'.$name.' t');
        }

        return $history;
    }

    public function test_operator_adjudicates_the_exact_late_refund_comparison_while_money_remains_held(): void
    {
        $path = Fixture::tempPath();
        $app = $this->application();
        try {
            [$event, $late, $replacement] = $this->lateRefundReview($path);
            // Retain a signed frame presented against the wrong original; its quarantine cannot support an adjudication.
            HistoricalMerchantRefundTest::problem(ProblemCode::AuthDenied, static fn () => $app->make(CallbackCustody::class)->receive(Fixture::scope(), 'merchant-a', 'replacement', $late));
            $untrusted = self::text($this->value("SELECT evidence_ref FROM billium_execution_provider_work WHERE state='quarantined'"));
            $history = $this->financialHistory();
            $evidence = [$late->evidenceRef, $replacement->evidenceRef];
            $action = $this->action('resolve-late', $event, $evidence);
            $modules = $app->make(ModuleRegistry::class);
            $registrar = new DatabaseEventContractRegistrar($app->make(DatabaseManager::class)->connection('billium_owner'), $modules);
            $descriptor = $modules->descriptor('execution');
            self::assertNotNull($descriptor);
            $registrar->register($registrar->plan($descriptor, true));
            $api = $app->make(RepairCommands::class);
            InventoryReconciliationTest::grant($this->callers, ['execution.reconcile']);
            HistoricalMerchantRefundTest::problem(ProblemCode::AuthDenied, static fn () => $api->repair($action, $event));
            InventoryReconciliationTest::grant($this->callers);
            $c = $this->callers->current();
            $this->callers->set(new Caller($c->sellerRef, $c->environment, $c->installationRef, $c->actorRef, ['standalone-billium'], $c->intentOwnerNamespaces, $c->permissions));
            HistoricalMerchantRefundTest::problem(ProblemCode::AuthDenied, static fn () => $api->repair($action, $event));
            $this->callers->set($c);
            foreach ([[$late->evidenceRef], [$late->evidenceRef, $untrusted], [$late->evidenceRef, self::text($this->value("SELECT evidence_ref FROM billium_execution_provider_work WHERE operation_hint='root-a' AND state='processed'"))]] as $unproven) {
                HistoricalMerchantRefundTest::problem(ProblemCode::RecoveryRequired, fn () => $api->repair($this->action('denied', $event, $unproven), $event));
            }
            $actual = $app->make(Outbox::class);
            $app->instance(Outbox::class, new readonly class($actual) implements Outbox
            {
                public function __construct(private Outbox $delegate) {}

                public function record(TransactionContext $context, OutboxEvent|RegisteredPublicEvent $event): int
                {
                    $this->delegate->record($context, $event);
                    throw new RuntimeException('Stop after repair outbox insertion');
                }
            });
            try {
                $app->make(RepairCommands::class)->repair($action, $event)->orThrow();
                self::fail('Expected atomic rollback');
            } catch (RuntimeException $error) {
                self::assertSame('Stop after repair outbox insertion', $error->getMessage());
            } finally {
                $app->instance(Outbox::class, $actual);
            }
            self::assertSame(0, $this->rows('billium_execution_repair_receipt'));
            self::assertSame(0, $this->rows('billium_execution_recon_review', "status='resolved'"));
            self::assertSame(0, $this->rows('billium_outbox_event', "type='execution.repair_recorded.v1'"));
            self::assertSame($history, $this->financialHistory());
            $this->assertHeld($path);
            $receipt = $api->repair($action, $event)->orThrow();
            $replayed = $api->repair($action, $event)->orThrow();
            self::assertTrue($replayed->replayed);
            self::assertSame($receipt->receiptId, $replayed->receiptId);
            self::assertSame($receipt->body, $replayed->body);
            HistoricalMerchantRefundTest::problem(ProblemCode::IdempotencyConflict, fn () => $api->repair($this->action('resolve-late', $event, $evidence, 'Different disposition'), $event));
            HistoricalMerchantRefundTest::problem(ProblemCode::RecoveryRequired, fn () => $api->repair($this->action('fresh-key', $event, $evidence), $event));
            self::assertSame(1, $this->rows('billium_execution_recon_review', "event_ref=? AND status='open'", [$event]));
            self::assertSame(1, $this->rows('billium_execution_recon_review', "case_ref=(SELECT case_ref FROM billium_execution_recon_review WHERE event_ref=?) AND status='resolved' AND receipt_ref=? AND provenance='resolve_discrepancy' AND next_review_at IS NULL", [$event, $receipt->body['receiptRef']]));
            self::assertSame(1, $this->rows('billium_execution_repair_receipt', 'before_digest<>after_digest AND authorization_ref=? AND reason=? AND evidence_refs=?::jsonb AND repair_ref=?', [$c->actorRef, $action->reason, json_encode($evidence, JSON_THROW_ON_ERROR), $receipt->body['receiptRef']]));
            $links = json_decode(self::text($this->value('SELECT correction_refs FROM billium_execution_repair_receipt')), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($links);
            self::assertSame([$event,
                $this->value("SELECT event_ref FROM billium_execution_recon_review WHERE status='resolved'"),
                $this->value("SELECT event_id FROM billium_outbox_event WHERE type='execution.repair_recorded.v1'"),
                $this->value('SELECT discrepancy_ref FROM billium_execution_discrepancy'),
                $this->value("SELECT fact_ref FROM billium_execution_provider_fact WHERE operation_ref='old' AND evidence_refs @> ?::jsonb", [json_encode([$late->evidenceRef], JSON_THROW_ON_ERROR)]),
                $this->value('SELECT evidence_ref FROM billium_execution_certified_absence'),
                $this->value("SELECT fact_ref FROM billium_execution_provider_fact WHERE operation_ref='replacement' AND evidence_refs @> ?::jsonb", [json_encode([$replacement->evidenceRef], JSON_THROW_ON_ERROR)]),
            ], $links);
            self::assertSame(1, $this->rows('billium_outbox_delivery', 'event_id=?', [$links[2]]));
            self::assertSame($history, $this->financialHistory());
            $this->assertHeld($path);
            self::assertSame(ProblemCode::OperationFinancialHold, $app->make(RefundCommands::class)->admit(Fixture::scope(), Refunds::refund('extra', 1, 45))->problem);
        } finally {
            InventoryReconciliationTest::clear($path);
        }
    }

    public function test_comparison_is_stale_after_a_new_material_revision_even_while_it_is_the_latest_review(): void
    {
        $path = Fixture::tempPath();
        try {
            [$event, $late, $replacement] = $this->lateRefundReview($path);
            $next = CallbackCustodyTest::signed($late, ['revision' => '19', 'refunded' => ['amount' => '1.41', 'currency' => 'RUB']]);
            $app = $this->application();
            $app->make(CallbackCustody::class)->receive(Fixture::scope(), 'merchant-a', 'old', $next)->orThrow();
            self::assertSame(ReductionDisposition::HeldDiscrepancy, $app->make(EvidenceReducer::class)->reduce(CallbackCustodyTest::work($next))->disposition);
            self::assertSame(1, $this->rows('billium_execution_recon_review'));
            $action = $this->action('stale-basis', $event, [$late->evidenceRef, $replacement->evidenceRef]);
            HistoricalMerchantRefundTest::problem(ProblemCode::RecoveryRequired, static fn () => $app->make(RepairCommands::class)->repair($action, $event));
            self::assertSame(0, $this->rows('billium_execution_repair_receipt'));
            self::assertSame(1, $this->rows('billium_execution_recon_review', "status='open' AND event_ref=?", [$event]));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'financial_hold AND confirmed_refund_minor=70'));
            self::assertSame(3, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            InventoryReconciliationTest::clear($path);
        }
    }
}

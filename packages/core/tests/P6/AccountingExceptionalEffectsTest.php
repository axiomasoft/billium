<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6;

use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Data\Money;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ReductionResult;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\OutcomeDeliveryTest as Delivery;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\CallbackCustodyTest as Inputs;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Platform\Contracts\Accounting\AccountingMapper;
use Billium\Platform\Data\Accounting\AccountingFactCategory;
use Billium\Platform\Data\Accounting\AccountingFactKind;
use Billium\Platform\Data\Accounting\AccountingMappingStatus;
use Billium\Platform\Data\Accounting\AccountingSourceRecord;
use Billium\Platform\Data\Accounting\FinBridgeExportEnvelope;
use Billium\Testkit\Accounting\FakeFinBridgeConsumer;
use Billium\Testkit\Drivers\ResponseMode;
use DateTimeImmutable;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Group;

/**
 * Accounting exceptional effects proof:
 * capture 100 + two confirmed actual refunds 70 + 70 =>
 * 1 capture CashIn (100), 2 refund CashOut facts totaling 140, 1 NoncashExposure (40) never counted as outgoing cash,
 * applied ledger projection retains last consistent 100 / 70 with financial_hold=true and spendable=0,
 * N replay permutations produce identical identities/counts/amounts with 0 automatic compensation,
 * and an unknown second refund produces 0 confirmed CashOut facts until actual evidence arrives.
 */
#[Group('pg')]
#[Group('ACC-RST-12')]
final class AccountingExceptionalEffectsTest extends ExecutionTestCase
{
    public function test_acc_rst_12_capture_100_two_refunds_70_plus_70_exports_140_cash_out_and_40_noncash_exposure(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            self::assertNotContains('documents', $app->make(BilliumOptions::class)->modules->enabled);

            // 1. Seed real ledger obligation + settlement intent so observation handoff projects onto real billium_payment.
            $account = new BillingAccountScope('seller-a', 'test', 'billing-a');
            $this->declareIntent($app, $account);

            // 2. Capture 100 minor (1.00 RUB) and project onto Ledger.
            $captureEvidence = Inputs::dispatched($app, $path);
            $captureReduced = $app->make(EvidenceReducer::class)->reduce(Inputs::work($captureEvidence));
            self::assertSame(ReductionDisposition::Applied, $captureReduced->disposition);
            $this->deliver($app, $path, $captureReduced);

            // 3. Admit refund 'old' (70), decline it at provider so reservation releases, then admit & succeed 'replacement' (70).
            $commands = $app->make(RefundCommands::class);
            $commands->admit($scope, Refunds::refund('old', 70, 2))->orThrow();
            Fixture::host($app, $path, mode: ResponseMode::Declined);
            $app->make(AttemptWorker::class)->dispatch($scope, Refunds::attemptRef('old'), 'worker-old', new DateTimeImmutable('+1 minute'))->orThrow();
            [$oldDeclinedEvidence, $oldDeclinedReduced] = $this->reduceSigned($app, 'old');
            $this->deliver($app, $path, $oldDeclinedReduced);
            Fixture::host($app, $path);

            $commands->admit($scope, Refunds::refund('replacement', 70, 3))->orThrow();
            $app->make(AttemptWorker::class)->dispatch($scope, Refunds::attemptRef('replacement'), 'worker-replacement', new DateTimeImmutable('+1 minute'))->orThrow();
            [$replacementEvidence, $replacementReduced] = $this->reduceSigned($app, 'replacement');
            $this->deliver($app, $path, $replacementReduced);

            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND confirmed_refund_minor=70 AND held_refund_minor=0 AND NOT financial_hold'));

            // 4. Late authenticated provider correction confirms 'old' (70) too => observed refundedTotal=140, overRefund=40, HeldDiscrepancy!
            // Preserve the original provider operation identity from $oldDeclinedEvidence (same shape as LateRefundSuccessTest).
            $lateEvidence = Inputs::signed($oldDeclinedEvidence, [
                'revision' => '18',
                'status' => 'succeeded',
                'operationRef' => 'old',
                'reference' => self::text($this->value("SELECT provider_reference FROM billium_execution_attempt WHERE operation_ref='old'")),
                'refunded' => ['amount' => '1.40', 'currency' => 'RUB'],
                'operationAmount' => ['amount' => '0.70', 'currency' => 'RUB'],
            ]);
            $app->make(CallbackCustody::class)->receive($scope, 'merchant-a', 'old', $lateEvidence)->orThrow();
            $lateReduced = $app->make(EvidenceReducer::class)->reduce(Inputs::work($lateEvidence));
            self::assertSame(ReductionDisposition::HeldDiscrepancy, $lateReduced->disposition);
            self::assertNotNull($lateReduced->discrepancyRef);
            $this->deliver($app, $path, $lateReduced);

            // 5. Verify REAL Execution & Ledger PostgreSQL state: observed 140, exposure 40, last-consistent applied 100/70, financial_hold=true, spendable=0, 0 automatic compensation.
            self::assertSame(1, $this->rows('billium_execution_operation_revision', "operation_ref='root-a' AND (observed->'refundedTotal'->>'minor')::int=140 AND (exposure->'overRefunded'->>'minor')::int=40 AND (last_consistent_applied->'confirmedRefund'->>'minor')::int=70"));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND confirmed_refund_minor=70 AND held_refund_minor=0 AND financial_hold'));
            self::assertSame(3, $this->rows('billium_execution_attempt'));
            self::assertSame(3, UnknownResultTest::events($path, 'provider.call'));

            $paymentStatus = $app->make(LedgerQueries::class)->paymentStatus('root-a', 'remote-billium');
            $applied = self::arr($paymentStatus['applied']);
            self::assertSame(100, Money::fromWire($applied['capturedTotal'])->minor);
            self::assertSame(70, Money::fromWire($applied['refundedTotal'])->minor);
            self::assertSame(0, Money::fromWire($paymentStatus['spendable'])->minor);
            self::assertSame(1, $this->rows('billium_payment', "operation_ref='root-a' AND captured_minor=100 AND refunded_minor=70 AND financial_hold"));

            // 6. Build authenticated actual-effect accounting inputs from the real execution evidence + discrepancy exposure.
            $mapper = $app->make(AccountingMapper::class);
            $consumer = new FakeFinBridgeConsumer;
            $at = new DateTimeImmutable('2026-11-19T15:00:00Z');

            $captureRecord = new AccountingSourceRecord(
                schemaVersion: 'accounting.source.v1',
                eventType: 'payment.captured.v1',
                installationRef: self::INSTALLATION,
                sellerRef: 'seller-a',
                environment: 'test',
                sourceLineage: 'execution:remote-billium',
                aggregateRef: 'root-a',
                aggregateRevision: 1,
                occurredAt: $at,
                payload: [
                    'operationRef' => 'root-a',
                    'status' => 'succeeded',
                    'providerAccountRef' => 'merchant-a',
                    'providerEffectRef' => $captureEvidence->evidenceRef,
                    'providerEvidenceRef' => $captureEvidence->evidenceRef,
                    'effectMoney' => ['amount' => '1.00', 'currency' => 'RUB'],
                ],
                deliveryAttemptRef: 'acc-cap-1',
            );
            $captureMapped = $mapper->map($captureRecord);
            self::assertSame(AccountingMappingStatus::Mapped, $captureMapped->status);
            self::assertNotNull($captureMapped->fact);
            $captureFactRef = $captureMapped->fact->sourceFactRef;

            $refund1Record = new AccountingSourceRecord(
                schemaVersion: 'accounting.source.v1',
                eventType: 'refund.succeeded.v1',
                installationRef: self::INSTALLATION,
                sellerRef: 'seller-a',
                environment: 'test',
                sourceLineage: 'execution:remote-billium',
                aggregateRef: 'replacement',
                aggregateRevision: 1,
                occurredAt: $at,
                payload: [
                    'operationRef' => 'replacement',
                    'originalOperationRef' => 'root-a',
                    'status' => 'succeeded',
                    'providerAccountRef' => 'merchant-a',
                    'providerEffectRef' => $replacementEvidence->evidenceRef,
                    'providerEvidenceRef' => $replacementEvidence->evidenceRef,
                    'effectMoney' => ['amount' => '0.70', 'currency' => 'RUB'],
                    'originalFactRef' => $captureFactRef,
                    'exceptional' => false,
                ],
                deliveryAttemptRef: 'acc-ref-1',
            );
            $refund2Record = new AccountingSourceRecord(
                schemaVersion: 'accounting.source.v1',
                eventType: 'refund.succeeded.v1',
                installationRef: self::INSTALLATION,
                sellerRef: 'seller-a',
                environment: 'test',
                sourceLineage: 'execution:remote-billium',
                aggregateRef: 'old',
                aggregateRevision: 2,
                occurredAt: $at,
                payload: [
                    'operationRef' => 'old',
                    'originalOperationRef' => 'root-a',
                    'status' => 'succeeded',
                    'providerAccountRef' => 'merchant-a',
                    'providerEffectRef' => $lateEvidence->evidenceRef,
                    'providerEvidenceRef' => $lateEvidence->evidenceRef,
                    'effectMoney' => ['amount' => '0.70', 'currency' => 'RUB'],
                    'originalFactRef' => $captureFactRef,
                    'exceptional' => true,
                ],
                deliveryAttemptRef: 'acc-ref-2',
            );

            $ref1Fact = $mapper->map($refund1Record)->fact;
            $ref2Fact = $mapper->map($refund2Record)->fact;
            self::assertNotNull($ref1Fact);
            self::assertNotNull($ref2Fact);
            self::assertNotSame($ref1Fact->sourceFactRef, $ref2Fact->sourceFactRef);

            $exposureRecord = new AccountingSourceRecord(
                schemaVersion: 'accounting.source.v1',
                eventType: 'exposure.over_refund.v1',
                installationRef: self::INSTALLATION,
                sellerRef: 'seller-a',
                environment: 'test',
                sourceLineage: 'execution:remote-billium',
                aggregateRef: 'root-a',
                aggregateRevision: 3,
                occurredAt: $at,
                payload: [
                    'rootOperationRef' => 'root-a',
                    'reason' => 'over_refund',
                    'discrepancyRef' => $lateReduced->discrepancyRef,
                    'exposureMoney' => ['amount' => '0.40', 'currency' => 'RUB'],
                    'originalFactRef' => $captureFactRef,
                    'linkedCashFactRefs' => [$ref1Fact->sourceFactRef, $ref2Fact->sourceFactRef],
                    'observedRefundedMinor' => 140,
                    'appliedRefundedMinor' => 70,
                    'appliedCapturedMinor' => 100,
                    'contributingEffects' => [
                        ['providerEffectRef' => $captureEvidence->evidenceRef, 'revision' => 1, 'digest' => $captureEvidence->payloadDigest],
                        ['providerEffectRef' => $replacementEvidence->evidenceRef, 'revision' => 1, 'digest' => $replacementEvidence->payloadDigest],
                        ['providerEffectRef' => $lateEvidence->evidenceRef, 'revision' => 18, 'digest' => $lateEvidence->payloadDigest],
                    ],
                ],
                deliveryAttemptRef: 'acc-exp-1',
            );

            foreach ([$captureRecord, $refund1Record, $refund2Record, $exposureRecord] as $record) {
                self::assertSame(AccountingMappingStatus::Mapped, $consumer->consume($record, $mapper)->status);
            }

            // Assert the accounting invariants:
            // - 1 capture CashIn = 100 (counted separately)
            // - 2 distinct refund CashOut facts = 140 (70 + 70), NOT bounded 70!
            // - 1 NoncashExposure = 40, linked to both refund cash facts, NEVER counted again as outgoing cash!
            self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::CashIn));
            self::assertSame(100, $consumer->totalMinorByCategory(AccountingFactCategory::CashIn, 'RUB'));
            self::assertSame(2, $consumer->appliedCount(AccountingFactCategory::CashOut, AccountingFactKind::RefundSucceeded));
            self::assertSame(140, $consumer->totalMinorByCategory(AccountingFactCategory::CashOut, 'RUB'));
            self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::NoncashExposure, AccountingFactKind::OverRefundExposure));
            self::assertSame(40, $consumer->totalMinorByCategory(AccountingFactCategory::NoncashExposure, 'RUB'));
            self::assertSame(0, $consumer->glWriteCount());

            $exposureEnvelope = $consumer->appliedEnvelopes(AccountingFactCategory::NoncashExposure)[0];
            $expectedLinked = [$ref1Fact->sourceFactRef, $ref2Fact->sourceFactRef];
            sort($expectedLinked, SORT_STRING);
            self::assertSame($expectedLinked, $exposureEnvelope->linkedCashFactRefs);
            self::assertSame($captureFactRef, $exposureEnvelope->originalFactRef);
            self::assertTrue($exposureEnvelope->exceptional);

            // 7. N replay permutations (different orders + reversed contributingEffects) produce IDENTICAL identities, counts, amounts, and 0 automatic compensation.
            $exposureReversedBasis = new AccountingSourceRecord(
                schemaVersion: $exposureRecord->schemaVersion,
                eventType: $exposureRecord->eventType,
                installationRef: $exposureRecord->installationRef,
                sellerRef: $exposureRecord->sellerRef,
                environment: $exposureRecord->environment,
                sourceLineage: $exposureRecord->sourceLineage,
                aggregateRef: $exposureRecord->aggregateRef,
                aggregateRevision: 9,
                occurredAt: $at,
                payload: array_replace($exposureRecord->payload, [
                    'linkedCashFactRefs' => [$ref2Fact->sourceFactRef, $ref1Fact->sourceFactRef],
                    'contributingEffects' => array_reverse(self::arr($exposureRecord->payload['contributingEffects'])),
                ]),
                deliveryAttemptRef: 'acc-exp-reversed-order',
            );

            $permutations = [
                [$captureRecord, $refund1Record, $refund2Record, $exposureRecord],
                [$refund2Record, $exposureReversedBasis, $captureRecord, $refund1Record],
                [$exposureReversedBasis, $refund1Record, $captureRecord, $refund2Record],
                [$refund1Record, $refund2Record, $captureRecord, $exposureRecord],
                [$captureRecord, $exposureReversedBasis, $refund2Record, $refund1Record],
                [$refund2Record, $refund1Record, $exposureRecord, $captureRecord],
            ];

            $baselineRefs = array_map(static fn (FinBridgeExportEnvelope $e): string => $e->sourceFactRef, $consumer->appliedEnvelopes());
            sort($baselineRefs, SORT_STRING);

            foreach ($permutations as $index => $batch) {
                $freshConsumer = new FakeFinBridgeConsumer("finbridge:perm-{$index}");
                foreach ($batch as $step => $item) {
                    $attempt = "perm-{$index}-step-{$step}";
                    $consumer->consume($item, $mapper, $attempt);
                    $freshConsumer->consume($item, $mapper, $attempt);
                }

                $permRefs = array_map(static fn (FinBridgeExportEnvelope $e): string => $e->sourceFactRef, $freshConsumer->appliedEnvelopes());
                sort($permRefs, SORT_STRING);
                self::assertSame($baselineRefs, $permRefs);
                self::assertSame(1, $freshConsumer->appliedCount(AccountingFactCategory::CashIn));
                self::assertSame(100, $freshConsumer->totalMinorByCategory(AccountingFactCategory::CashIn, 'RUB'));
                self::assertSame(2, $freshConsumer->appliedCount(AccountingFactCategory::CashOut));
                self::assertSame(140, $freshConsumer->totalMinorByCategory(AccountingFactCategory::CashOut, 'RUB'));
                self::assertSame(1, $freshConsumer->appliedCount(AccountingFactCategory::NoncashExposure));
                self::assertSame(40, $freshConsumer->totalMinorByCategory(AccountingFactCategory::NoncashExposure, 'RUB'));
            }

            // Original consumer still has strictly 4 applied facts, while delivery ACKs recorded all 7 deliveries per fact (1 initial + 6 permutations).
            self::assertSame(4, $consumer->appliedCount());
            self::assertSame(28, $consumer->deliveryAckCount());
            self::assertSame(3, $this->rows('billium_execution_attempt'));
            self::assertSame(3, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
            if (is_file($path.'.origins')) {
                unlink($path.'.origins');
            }
        }
    }

    public function test_unknown_second_refund_produces_zero_confirmed_cash_out_until_actual_evidence_arrives(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            $account = new BillingAccountScope('seller-a', 'test', 'billing-a');
            $this->declareIntent($app, $account);

            // Capture 100 + confirm first refund 70.
            $captureEvidence = Inputs::dispatched($app, $path);
            $captureReduced = $app->make(EvidenceReducer::class)->reduce(Inputs::work($captureEvidence));
            $this->deliver($app, $path, $captureReduced);

            $commands = $app->make(RefundCommands::class);
            $commands->admit($scope, Refunds::refund('refund-confirmed', 70, 2))->orThrow();
            $app->make(AttemptWorker::class)->dispatch($scope, Refunds::attemptRef('refund-confirmed'), 'worker-r1', new DateTimeImmutable('+1 minute'))->orThrow();
            [$refund1Evidence, $refund1Reduced] = $this->reduceSigned($app, 'refund-confirmed');
            $this->deliver($app, $path, $refund1Reduced);

            // Second refund (30 remaining normal capacity) dispatched under provider unavailability => attempt stays unknown!
            $commands->admit($scope, Refunds::refund('refund-unknown', 30, 3))->orThrow();
            Fixture::host($app, $path, mode: ResponseMode::Unavailable);
            $app->make(AttemptWorker::class)->dispatch($scope, Refunds::attemptRef('refund-unknown'), 'worker-r2', new DateTimeImmutable('+1 minute'))->orThrow();
            self::assertSame(1, $this->rows('billium_execution_attempt', "operation_ref='refund-unknown' AND state='unknown'"));

            $mapper = $app->make(AccountingMapper::class);
            $consumer = new FakeFinBridgeConsumer;
            $at = new DateTimeImmutable('2026-11-19T16:00:00Z');

            // Consume confirmed capture 100 and confirmed refund 1 (70).
            $capRes = $consumer->consume(new AccountingSourceRecord(
                'accounting.source.v1', 'payment.captured.v1', self::INSTALLATION, 'seller-a', 'test', 'execution:remote-billium', 'root-a', 1, $at,
                ['status' => 'succeeded', 'providerAccountRef' => 'merchant-a', 'providerEffectRef' => $captureEvidence->evidenceRef, 'effectMoney' => ['amount' => '1.00', 'currency' => 'RUB']],
            ), $mapper);
            self::assertNotNull($capRes->fact);
            $ref1Res = $consumer->consume(new AccountingSourceRecord(
                'accounting.source.v1', 'refund.succeeded.v1', self::INSTALLATION, 'seller-a', 'test', 'execution:remote-billium', 'refund-confirmed', 1, $at,
                ['status' => 'succeeded', 'providerAccountRef' => 'merchant-a', 'providerEffectRef' => $refund1Evidence->evidenceRef, 'effectMoney' => ['amount' => '0.70', 'currency' => 'RUB'], 'originalFactRef' => $capRes->fact->sourceFactRef],
            ), $mapper);
            self::assertNotNull($ref1Res->fact);

            // Unknown second refund + ambiguous aggregate-only notice must produce ZERO confirmed CashOut facts!
            $unknownRes = $consumer->consume(new AccountingSourceRecord(
                'accounting.source.v1', 'refund.succeeded.v1', self::INSTALLATION, 'seller-a', 'test', 'execution:remote-billium', 'refund-unknown', 1, $at,
                ['status' => 'accepted-unknown', 'providerAccountRef' => 'merchant-a', 'providerEffectRef' => 'pending-ref-2', 'effectMoney' => ['amount' => '0.70', 'currency' => 'RUB']],
            ), $mapper);
            $ambiguousRes = $consumer->consume(new AccountingSourceRecord(
                'accounting.source.v1', 'refund.succeeded.v1', self::INSTALLATION, 'seller-a', 'test', 'execution:remote-billium', 'root-a', 2, $at,
                ['status' => 'succeeded', 'providerAccountRef' => 'merchant-a', 'evidenceKind' => 'aggregate_only', 'refundedTotal' => ['amount' => '1.40', 'currency' => 'RUB']],
            ), $mapper);

            self::assertSame(AccountingMappingStatus::IgnoredNonMonetary, $unknownRes->status);
            self::assertSame(AccountingMappingStatus::IgnoredNonMonetary, $ambiguousRes->status);
            self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::CashIn));
            self::assertSame(100, $consumer->totalMinorByCategory(AccountingFactCategory::CashIn, 'RUB'));
            self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::CashOut));
            self::assertSame(70, $consumer->totalMinorByCategory(AccountingFactCategory::CashOut, 'RUB'));
            self::assertSame(0, $consumer->appliedCount(AccountingFactCategory::NoncashExposure));

            // Once authenticated actual provider evidence for the second refund arrives (70 exceptional), CashOut becomes 2 facts totaling 140 + Exposure 40.
            $confirmedSecond = $consumer->consume(new AccountingSourceRecord(
                'accounting.source.v1', 'refund.succeeded.v1', self::INSTALLATION, 'seller-a', 'test', 'execution:remote-billium', 'refund-unknown', 2, $at,
                ['status' => 'succeeded', 'providerAccountRef' => 'merchant-a', 'providerEffectRef' => 'prov-ref-2-arrived', 'effectMoney' => ['amount' => '0.70', 'currency' => 'RUB'], 'originalFactRef' => $capRes->fact->sourceFactRef, 'exceptional' => true],
            ), $mapper);
            self::assertSame(AccountingMappingStatus::Mapped, $confirmedSecond->status);
            self::assertNotNull($confirmedSecond->fact);

            $expRes = $consumer->consume(new AccountingSourceRecord(
                'accounting.source.v1', 'exposure.over_refund.v1', self::INSTALLATION, 'seller-a', 'test', 'execution:remote-billium', 'root-a', 3, $at,
                [
                    'rootOperationRef' => 'root-a',
                    'reason' => 'over_refund',
                    'exposureMoney' => ['amount' => '0.40', 'currency' => 'RUB'],
                    'originalFactRef' => $capRes->fact->sourceFactRef,
                    'linkedCashFactRefs' => [$ref1Res->fact->sourceFactRef, $confirmedSecond->fact->sourceFactRef],
                    'contributingEffects' => [
                        ['providerEffectRef' => $captureEvidence->evidenceRef, 'revision' => 1, 'digest' => $captureEvidence->payloadDigest],
                        ['providerEffectRef' => $refund1Evidence->evidenceRef, 'revision' => 1, 'digest' => $refund1Evidence->payloadDigest],
                        ['providerEffectRef' => 'prov-ref-2-arrived', 'revision' => 2, 'digest' => 'sha256:'.str_repeat('e', 64)],
                    ],
                ],
            ), $mapper);
            self::assertSame(AccountingMappingStatus::Mapped, $expRes->status);
            self::assertSame(2, $consumer->appliedCount(AccountingFactCategory::CashOut));
            self::assertSame(140, $consumer->totalMinorByCategory(AccountingFactCategory::CashOut, 'RUB'));
            self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::NoncashExposure));
            self::assertSame(40, $consumer->totalMinorByCategory(AccountingFactCategory::NoncashExposure, 'RUB'));
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
        $this->runner()->run($spec, static fn (TransactionContext $ctx) => $ledger->recordObligation(
            $ctx,
            new RecordObligation('debt', $account, 'debt', 'basis', 'buyer', 'component', Money::parse('0.10', 'RUB'), 'sha256:'.str_repeat('a', 64)),
        ))->orThrow();
        $this->runner()->run($spec, static fn (TransactionContext $ctx) => $ledger->declareSettlementIntent(
            $ctx,
            new DeclareSettlementIntent('intent-command', $account, 'intent-a', 'RUB', ['debt']),
        ))->orThrow();
    }

    /** @return array{0: ProviderEvidence, 1: ReductionResult} */
    private function reduceSigned(Application $app, string $operation): array
    {
        $work = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint=? AND state='due'", [$operation]));
        $input = $app->make(ExecutionStore::class)->providerWork($work);
        self::assertNotNull($input);
        $result = $app->make(EvidenceReducer::class)->reduce($work);
        self::assertSame(ReductionDisposition::Applied, $result->disposition);

        return [$input->evidence, $result];
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
}

<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Drill;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\CancelOperation;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\OriginDecision;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use Billium\Execution\Tests\Feature\CallbackCustodyTest as Inputs;
use Billium\Execution\Tests\Support\CertifiesAbsentRefund;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\ProcessBarrier;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
#[Group('PB-16')]
final class LateRefundSuccessTest extends ExecutionTestCase
{
    use CertifiesAbsentRefund;

    public static function sendAndPause(string $journal): void
    {
        $app = Fixture::workerApp(160);
        $barrier = new ProcessBarrier(new DurableJournal($journal.'.control'));
        Fixture::host($app, $journal, checkpoint: static function (string $stage) use ($barrier): void {
            if ($stage === 'after_accept') {
                $barrier->arrive('refund-accepted');
                $barrier->await('resume', 1, 25_000);
            }
        });
        $app->make(AttemptWorker::class)->dispatch(Fixture::scope(), Refunds::attemptRef('crashed'), 'crashing-worker', new DateTimeImmutable('+2 seconds'))->orThrow();
    }

    public function test_accepted_refund_survives_worker_death_and_one_lookup_emits_root_total_and_distinct_detail_once(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            Refunds::captured($app, $path);
            $app->make(RefundCommands::class)->admit($scope, Refunds::refund('crashed', 45, 2))->orThrow();
            $child = new ChildProcess([PHP_BINARY, '-r', 'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Drill\\LateRefundSuccessTest::sendAndPause($argv[1]);', $path]);
            new ProcessBarrier(new DurableJournal($path.'.control'))->await('refund-accepted', 1, 20_000);
            $child->kill();
            self::assertNotSame(0, $child->wait());
            self::assertSame(1, $this->rows('billium_execution_capacity', 'held_refund_minor=45 AND confirmed_refund_minor=0'));
            self::assertSame(1, $this->rows('billium_execution_attempt', "operation_ref='crashed' AND state='dispatching'"));
            self::assertSame(ProblemCode::RefundCapacityExceeded, $app->make(RefundCommands::class)->admit($scope, Refunds::refund('over', 56, 3))->problem);
            $cancel = (new Codec)->decode(json_encode(['schema' => CancelOperation::SCHEMA, 'commandKey' => Fixture::key(9), 'installationRef' => self::INSTALLATION,
                'sellerRef' => $scope->sellerRef, 'environment' => $scope->environment, 'operationRef' => 'crashed'], JSON_THROW_ON_ERROR));
            self::assertInstanceOf(CancelOperation::class, $cancel);
            self::assertSame(ProblemCode::OperationNotCancellable, $app->make(RootPaymentCommands::class)->cancel($scope, $cancel)->problem);
            self::assertSame(1, $this->rows('billium_execution_reservation', "operation_ref='crashed' AND state='held' AND consumed_minor=0"));
            usleep(2_100_000);
            $app->make(AttemptWorker::class)->recover($scope, Refunds::attemptRef('crashed'), 'replacement-worker', new DateTimeImmutable('+1 minute'))->orThrow();
            $work = $this->work('crashed');
            $reducer = $app->make(EvidenceReducer::class);
            self::assertCount(2, $reducer->reduce($work)->observationCommandRefs);
            self::assertSame(ReductionDisposition::Duplicate, $reducer->reduce($work)->disposition);
            self::assertSame(1, $this->rows('billium_execution_reservation', "operation_ref='crashed' AND state='consumed' AND consumed_minor=45"));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'held_refund_minor=0 AND confirmed_refund_minor=45 AND confirmed_capture_minor=100'));
            self::assertSame(1, $this->rows('billium_execution_observation_emission', "operation_ref='root-a' AND payload->'refundedTotal'->>'amount'='0.45'"));
            self::assertSame(1, $this->rows('billium_execution_observation_emission', "operation_ref='crashed' AND payload->>'originalPaymentRef'='root-a' AND payload->'capturedTotal'->>'amount'='0.45' AND payload->'refundedTotal'->>'amount'='0.00'"));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.lookup'));
            self::assertSame(2, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    /** @return list<array{string}> */
    public static function terminalEvidence(): array
    {
        return [['certified'], ['declined']];
    }

    #[DataProvider('terminalEvidence')]
    public function test_late_original_refund_after_replacement_preserves_observed_140_applied_70_exposure_40(string $release): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            Refunds::captured($app, $path);
            $commands = $app->make(RefundCommands::class);
            $commands->admit($scope, Refunds::refund('old', 70, 2))->orThrow();
            if ($release === 'certified') {
                $this->certifyAbsentAndAdoptNewSession($path);
                $first = null;
            } else {
                Fixture::host($app, $path, mode: ResponseMode::Declined);
                $app->make(AttemptWorker::class)->dispatch($scope, Refunds::attemptRef('old'), 'old', new DateTimeImmutable('+1 minute'))->orThrow();
                $first = $this->reduceSigned('old');
                Fixture::host($app, $path);
            }
            self::assertSame(1, $this->rows('billium_execution_reservation', "operation_ref='old' AND state='released' AND consumed_minor=0 AND release_evidence_ref IS NOT NULL"));
            $commands->admit($scope, Refunds::refund('replacement', 70, 3))->orThrow();
            $app->make(AttemptWorker::class)->dispatch($scope, Refunds::attemptRef('replacement'), 'replacement', new DateTimeImmutable('+1 minute'))->orThrow();
            $replacement = $this->reduceSigned('replacement');
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND confirmed_refund_minor=70 AND held_refund_minor=0 AND NOT financial_hold'));
            $source = $first ?? $replacement;
            // An independently authenticated provider correction can contradict even a previous terminal, certified no-effect decision.
            $fields = ['revision' => '18', 'status' => 'succeeded', 'operationRef' => 'old', 'reference' => self::text($this->value("SELECT provider_reference FROM billium_execution_attempt WHERE operation_ref='old'")),
                'refunded' => ['amount' => '1.40', 'currency' => 'RUB'], 'operationAmount' => ['amount' => '0.70', 'currency' => 'RUB']];
            if ($first === null) {
                $fields['stableEffectId'] = 'late-original-provider-effect';
                $fields['providerOperationId'] = 'late-original-provider-operation';
            }
            $late = Inputs::signed($source, $fields);
            $intake = $app->make(CallbackCustody::class);
            $intake->receive($scope, 'merchant-a', 'old', $late)->orThrow();
            $reducer = $app->make(EvidenceReducer::class);
            $result = $reducer->reduce(Inputs::work($late));
            self::assertSame(ReductionDisposition::HeldDiscrepancy, $result->disposition);
            self::assertNotNull($result->discrepancyRef);
            self::assertSame(1, $this->rows('billium_execution_operation_revision', "operation_ref='root-a' AND (observed->'refundedTotal'->>'minor')::int=140 AND (exposure->'overRefunded'->>'minor')::int=40 AND (last_consistent_applied->'confirmedRefund'->>'minor')::int=70"));
            self::assertSame(1, $this->rows('billium_execution_operation_revision', "operation_ref='old' AND (observed->'refundedTotal'->>'minor')::int=140"));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND confirmed_refund_minor=70 AND held_refund_minor=0 AND financial_hold'));
            self::assertSame(1, $this->rows('billium_execution_reservation', "operation_ref='replacement' AND state='consumed' AND consumed_minor=70"));
            self::assertSame(1, $this->rows('billium_execution_reservation', "operation_ref='old' AND state='released' AND consumed_minor=0"));
            self::assertSame(0, $this->rows('billium_allocation'));
            self::assertSame(3, $this->rows('billium_execution_attempt'));
            self::assertSame(3, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(ReductionDisposition::Duplicate, $reducer->reduce(Inputs::work($late))->disposition);
            $changed = Inputs::signed($late, ['stableEffectId' => 'changed-same-provider-revision', 'refunded' => ['amount' => '1.50', 'currency' => 'RUB']]);
            $intake->receive($scope, 'merchant-a', 'old', $changed)->orThrow();
            self::assertSame(ReductionDisposition::Quarantined, $reducer->reduce(Inputs::work($changed))->disposition);
            self::assertSame(1, $this->rows('billium_execution_provider_evidence', 'evidence_ref=?', [$changed->evidenceRef]));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'financial_hold AND confirmed_refund_minor=70'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_external_overrefund_of_original_charge_never_spends_the_replacement_charge_capacity(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            $original = Inputs::dispatched($app, $path);
            $reducer = $app->make(EvidenceReducer::class);
            self::assertSame(ReductionDisposition::Applied, $reducer->reduce(Inputs::work($original))->disposition);
            $replacement = new OriginDecision($scope, 'replacement-charge', 'second-order', 'checkout', 'remote-billium', 1, null, 'sha256:'.hash('sha256', 'replacement-order'), 'terms-v1', null);
            Fixture::host($app, $path, [$replacement]);
            $app->make(RootPaymentCommands::class)->admit($scope, Fixture::wire(['commandKey' => Fixture::key(8), 'operationRef' => 'replacement-charge',
                'source' => ['context' => 'checkout', 'intentRef' => 'second-order', 'orderRef' => 'second-order'], 'money' => ['amount' => '2.00', 'currency' => 'RUB']]))->orThrow();
            $app->make(AttemptWorker::class)->dispatch($scope, Refunds::attemptRef('replacement-charge'), 'new-charge', new DateTimeImmutable('+1 minute'))->orThrow();
            $this->reduceSigned('replacement-charge');
            $manual = Inputs::signed($original, ['revision' => '4', 'refunded' => ['amount' => '1.50', 'currency' => 'RUB']]);
            $app->make(CallbackCustody::class)->receive($scope, 'merchant-a', 'root-a', $manual)->orThrow();
            self::assertSame(ReductionDisposition::HeldDiscrepancy, $reducer->reduce(Inputs::work($manual))->disposition);
            self::assertSame(1, $this->rows('billium_execution_operation_revision', "operation_ref='root-a' AND (observed->'refundedTotal'->>'minor')::int=150 AND (exposure->'overRefunded'->>'minor')::int=50"));
            self::assertSame(1, $this->rows('billium_execution_capacity', "root_operation_ref='root-a' AND financial_hold AND confirmed_refund_minor=0"));
            self::assertSame(1, $this->rows('billium_execution_capacity', "root_operation_ref='replacement-charge' AND NOT financial_hold AND confirmed_capture_minor=200 AND confirmed_refund_minor=0 AND held_refund_minor=0"));
            self::assertSame(ProblemCode::OperationFinancialHold, $app->make(RefundCommands::class)->admit($scope, Refunds::refund('manual-held', 20, 9))->problem);
            self::assertSame(0, $this->rows('billium_execution_reservation'));
            self::assertSame(2, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_uncorrelated_external_refund_cannot_consume_the_basis_of_another_unknown_refund(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            $root = Inputs::dispatched($app, $path);
            $reducer = $app->make(EvidenceReducer::class);
            self::assertSame(ReductionDisposition::Applied, $reducer->reduce(Inputs::work($root))->disposition);
            $app->make(RefundCommands::class)->admit($scope, Refunds::refund('unknown', 70, 2))->orThrow();
            Fixture::host($app, $path, mode: ResponseMode::Unavailable);
            $app->make(AttemptWorker::class)->dispatch($scope, Refunds::attemptRef('unknown'), 'refund', new DateTimeImmutable('+1 minute'))->orThrow();
            $manual = Inputs::signed($root, ['revision' => '8', 'refunded' => ['amount' => '0.80', 'currency' => 'RUB']]);
            $app->make(CallbackCustody::class)->receive($scope, 'merchant-a', 'root-a', $manual)->orThrow();
            $result = $reducer->reduce(Inputs::work($manual));
            self::assertSame(ReductionDisposition::HeldDiscrepancy, $result->disposition);
            self::assertNotNull($result->discrepancyRef);
            self::assertSame(1, $this->rows('billium_execution_operation_revision', "operation_ref='root-a' AND (observed->'refundedTotal'->>'minor')::bigint=80 AND (last_consistent_applied->'confirmedRefund'->>'minor')::bigint=0"));
            self::assertSame(1, $this->rows('billium_execution_reservation', "operation_ref='unknown' AND state='held' AND consumed_minor=0 AND release_evidence_ref IS NULL"));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND confirmed_refund_minor=0 AND held_refund_minor=70 AND financial_hold'));
            self::assertSame(2, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    private function work(string $operation): string
    {
        return self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint=? AND state='due'", [$operation]));
    }

    private function reduceSigned(string $operation): ProviderEvidence
    {
        $app = $this->application();
        $work = $this->work($operation);
        $input = $app->make(ExecutionStore::class)->providerWork($work);
        self::assertNotNull($input);
        self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce($work)->disposition);

        return $input->evidence;
    }
}

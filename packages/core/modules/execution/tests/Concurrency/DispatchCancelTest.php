<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Concurrency;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\CancelOperation;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\CallbackCustodyTest;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\ProcessBarrier;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class DispatchCancelTest extends ExecutionTestCase
{
    public static function command(): CancelOperation
    {
        $decoded = (new Codec)->decode(json_encode(['schema' => 'payment.cancel.v1', 'commandKey' => AdmissionIdentityTest::key(44),
            'installationRef' => self::INSTALLATION, 'sellerRef' => 'seller-a', 'environment' => 'test', 'operationRef' => 'root-a'], JSON_THROW_ON_ERROR));
        self::assertInstanceOf(CancelOperation::class, $decoded);

        return $decoded;
    }

    /** @return list<array{string,int}> */
    public static function orderings(): array
    {
        return [['after-txa', 0], ['after-txb', 1]];
    }

    #[DataProvider('orderings')]
    #[Group('ACC-CON-07')]
    #[Group('PB-22')]
    public function test_cancellation_linearizes_at_committed_dispatch_start(string $point, int $calls): void
    {
        $app = $this->application();
        AdmissionIdentityTest::seedMerchant($app);
        $path = AdmissionIdentityTest::tempPath();
        AdmissionIdentityTest::host($app, $path);
        try {
            $child = UnknownResultTest::start($path, $point);
            $barrier = new ProcessBarrier(new DurableJournal($path.'.control'));
            $barrier->await('at-boundary', 1, 15_000);
            $cancel = $app->make(RootPaymentCommands::class)->cancel(AdmissionIdentityTest::scope(), self::command());
            if ($calls === 0) {
                self::assertTrue($cancel->accepted);
                self::assertSame('cancelled', $cancel->body['status']);
                self::assertSame(1, $this->rows('billium_execution_operation_revision'));
                self::assertSame(1, $this->rows('billium_execution_attempt', "state='cancelled' AND started_at IS NULL"));
                $again = $app->make(RootPaymentCommands::class)->cancel(AdmissionIdentityTest::scope(), self::command());
                self::assertSame($cancel->receiptId, $again->receiptId);
                $result = $app->make(AttemptWorker::class)->dispatch(AdmissionIdentityTest::scope(), UnknownResultTest::attemptRef(), 'late-worker', new DateTimeImmutable('+1 minute'));
                self::assertSame('cancelled', $result->body['status']);
            } else {
                self::assertFalse($cancel->accepted);
                self::assertSame(ProblemCode::OperationNotCancellable, $cancel->problem);
                self::assertSame(1, $this->rows('billium_execution_attempt', "state='dispatching' AND started_at IS NOT NULL AND request_deadline_at IS NOT NULL"));
            }
            $barrier->arrive('resume');
            self::assertSame(0, $child->wait());
            self::assertSame($calls, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(1, $this->rows('billium_execution_attempt'));
            self::assertSame(0, $this->rows('billium_execution_operation', "kind='refund'"));
            if ($calls === 0) {
                self::assertSame(1, $this->rows('billium_execution_operation', "kind='charge' AND state='cancelled'"));
            } else {
                $effect = CallbackCustodyTest::providerInput($app);
                self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce(CallbackCustodyTest::work($effect))->disposition);
                self::assertSame(1, $this->rows('billium_execution_operation', "kind='charge' AND state='succeeded'"));
                self::assertSame(0, $this->rows('billium_execution_operation', "kind='refund'"));
                $refund = $app->make(RefundCommands::class)->admit(AdmissionIdentityTest::scope(), RefundCapacityTest::refund('after-cancel-refusal', 25, 45))->orThrow();
                self::assertSame('after-cancel-refusal', $refund->body['operationRef']);
                self::assertSame(1, $this->rows('billium_execution_operation', "kind='refund' AND root_operation_ref='root-a'"));
                self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            }
        } finally {
            UnknownResultTest::clean($path);
        }
    }
}

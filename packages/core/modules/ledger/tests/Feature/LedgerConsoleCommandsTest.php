<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Internal\Observation\ParkedDrainer;
use Billium\Ledger\Internal\Observation\RefundReconciler;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

/** Operator entry points of the observation drain worker and the refund reconcile job. */
#[Group('D60')]
#[Group('pg')]
final class LedgerConsoleCommandsTest extends ObservationTestCase
{
    public function test_drain_parked_retries_observations_whose_dependency_now_exists(): void
    {
        $charge = ObservationFactory::charge('op-1', 1, 1000);
        self::assertSame(ProblemCode::ObservationParked->value, $this->tryObserve($charge));
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);

        $this->artisan('billium:ledger:drain-parked')
            ->expectsOutput('drained=0 retried=1 applied=1')
            ->assertExitCode(0);
        self::assertSame('applied', $this->inboxStatus(self::eventId($charge)));
        self::assertSame(1000, $this->allocated('o1'));

        $this->artisan('billium:ledger:drain-parked')
            ->expectsOutput('drained=0 retried=0 ')
            ->assertExitCode(0);
    }

    public function test_drain_parked_also_drains_a_named_partition(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000));
        $partition = (string) self::integer($this->partition()['id']);

        $this->artisan('billium:ledger:drain-parked', ['--partition' => $partition, '--limit' => '1'])
            ->expectsOutput('drained=0 retried=0 ')
            ->assertExitCode(0);
    }

    #[TestWith(['0', null])]
    #[TestWith(['1001', null])]
    #[TestWith(['many', null])]
    #[TestWith(['10', '-1'])]
    #[TestWith(['10', 'part-1'])]
    public function test_drain_parked_rejects_malformed_options(string $limit, ?string $partition): void
    {
        $this->artisan('billium:ledger:drain-parked', array_filter(['--limit' => $limit, '--partition' => $partition], is_string(...)))
            ->expectsOutput('limit must be between 1 and 1000 and partition a positive id')
            ->assertExitCode(1);
    }

    public function test_drain_parked_failure_shows_only_the_class(): void
    {
        Exceptions::fake();
        $this->failingCaller('payload card=4111');

        $this->artisan('billium:ledger:drain-parked')
            ->expectsOutput('Drain unavailable (RuntimeException)')
            ->doesntExpectOutputToContain('4111')
            ->assertExitCode(1);
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_reconcile_refunds_records_a_mismatch_once_and_leaves_the_ledger_alone(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, 300));
        $journal = $this->journalSum();

        $this->artisan('billium:ledger:reconcile-refunds', ['--after' => '0'])
            ->expectsOutput('compared=1 recorded=1 resolved=0')
            ->assertExitCode(0);
        $this->artisan('billium:ledger:reconcile-refunds', ['--after' => '0', '--limit' => '1'])
            ->expectsOutput('compared=1 recorded=0 resolved=0')
            ->assertExitCode(0);
        self::assertSame(1, $this->rows('billium_ledger_discrepancy', "reason = 'refund_basis_mismatch'"));
        self::assertSame($journal, $this->journalSum());
    }

    public function test_reconcile_refunds_waits_for_the_configured_consistency_window(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, 300));

        $this->artisan('billium:ledger:reconcile-refunds')
            ->expectsOutput('compared=0 recorded=0 resolved=0')
            ->assertExitCode(0);
        self::assertSame(0, $this->rows('billium_ledger_discrepancy'));
    }

    #[TestWith(['0', null])]
    #[TestWith(['1001', null])]
    #[TestWith(['', null])]
    #[TestWith(['10', '-5'])]
    #[TestWith(['10', '1h'])]
    public function test_reconcile_refunds_rejects_malformed_options(string $limit, ?string $after): void
    {
        $this->artisan('billium:ledger:reconcile-refunds', array_filter(['--limit' => $limit, '--after' => $after], is_string(...)))
            ->expectsOutput('limit must be between 1 and 1000 and after a nonnegative number of seconds')
            ->assertExitCode(1);
    }

    public function test_reconcile_refunds_failure_shows_only_the_class(): void
    {
        Exceptions::fake();
        $this->failingCaller('basis secret');

        $this->artisan('billium:ledger:reconcile-refunds')
            ->expectsOutput('Reconcile unavailable (RuntimeException)')
            ->doesntExpectOutputToContain('secret')
            ->assertExitCode(1);
        Exceptions::assertReported(RuntimeException::class);
    }

    /** The workers resolve the caller first, so a failing caller context fails them inside the command. */
    private function failingCaller(string $message): void
    {
        $this->application()->instance(CallerContext::class, new readonly class($message) implements CallerContext
        {
            public function __construct(private string $message) {}

            public function current(): Caller
            {
                throw new RuntimeException($this->message);
            }
        });
        $this->application()->forgetInstance(ParkedDrainer::class);
        $this->application()->forgetInstance(RefundReconciler::class);
    }
}

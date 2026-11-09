<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Internal\Observation\EconomicDigest;
use Billium\Ledger\Internal\Observation\ObservationHandler;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use Billium\Ledger\Tests\Observation\OverRefundVector;
use Billium\Platform\Data\Inbox\BootstrapItem;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Str;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * D57 actual vs applied (invariant-model, observation-apply 7): an actual provider refund above the capture is retained
 * unclamped and unrejected, the applied projection keeps its last consistent totals, the exposure is visible, a
 * financial hold makes the payment unspendable and needs explicit repair. Over-capture and regressions take the same
 * path. No automatic refund, charge or collection is created.
 */
#[Group('D60')]
final class ExceptionalExposureTest extends ObservationTestCase
{
    use OverRefundVector;

    public function test_a_parked_provider_account_contradiction_holds_before_any_assignment(): void
    {
        $this->bootstrapper()->execute($this->snapshot('boot', 'L1', 2, [
            ObservationFactory::charge('op-1', 1, 4000, sequence: 1, fields: ['providerAccountRef' => 'pa-1']),
            ObservationFactory::charge('op-1', 2, 4000, sequence: 2, fields: ['providerAccountRef' => 'pa-2']),
        ]));
        self::assertSame(2, $this->rows('billium_observed_payment_revision'));
        self::assertSame(2, $this->rows('billium_observation_park', 'released_at IS NULL'));
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);

        self::assertSame(['applied', 'applied'], $this->drainer()->retryParked());

        self::assertSame(0, $this->allocated('o1'));
        self::assertTrue($this->paymentRow()['financial_hold']);
        self::assertSame(0, self::integer($this->paymentRow()['applied_revision']));
        self::assertSame(0, $this->rows('billium_payment_revision'));
        self::assertSame(0, $this->rows('billium_allocation'));
        self::assertSame(2, $this->rows('billium_ledger_discrepancy', "reason = 'binding_changed' AND hold"));
    }

    #[TestWith([1])]
    #[TestWith([2])]
    public function test_the_projection_routine_rejects_persisted_provider_account_contradictions(int $revision): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        foreach ([1 => 'pa-1', 2 => 'pa-2'] as $at => $account) {
            $this->routine('record_observation', [self::INSTALLATION, 'seller-a', 'test', 'embedded-commerz', 'L1', 'op-1', 'charge', null,
                $at, self::digest($at === 1 ? 'a' : 'b'), 'succeeded', 'RUB', 4000, 4000, 4000, 0, $account, 'evidence-'.$at, '{}']);
        }
        $tables = ['billium_payment', 'billium_payment_revision', 'billium_allocation', 'billium_allocation_receipt', 'billium_outbox_event'];
        $before = array_map($this->rows(...), $tables);
        try {
            $this->routine('project_payment', [...$this->scopeArguments(), self::integer($this->observedRow()['id']), $revision,
                'op-1', self::integer($this->value("SELECT id FROM billium_settlement_intent WHERE business_intent_ref = 'intent-a'")),
                'assign', '[]', null, 'direct-projection', self::digest($revision === 1 ? 'a' : 'b')]);
            self::fail('persisted provider account contradictions must refuse direct assignment');
        } catch (PDOException $exception) {
            self::assertSame('P0001', $exception->getCode());
            self::assertStringContainsString(ProblemCode::ObservationConflict->value, $exception->getMessage());
        }
        self::assertSame($before, array_map($this->rows(...), $tables));
        self::assertSame(0, $this->allocated('o1'));
        self::assertSame(['pa-1', 'pa-2'], array_column($this->table("SELECT payload->>'providerAccountRef' AS account FROM billium_observed_payment_revision ORDER BY revision"), 'account'));
    }

    /** @param array<string, mixed> $fields */
    #[TestWith([['currency' => 'USD']])]
    #[TestWith([['providerAccountRef' => 'pa-2']])]
    public function test_a_changed_currency_or_provider_account_holds_the_original_projection(array $fields): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 2000));
        $envelope = ObservationFactory::charge('op-1', 2, 2000, sequence: 2, fields: $fields);

        $held = $this->observe($envelope);

        self::assertSame('hold', $held->mode());
        self::assertSame('binding_changed', self::arr($held->body['discrepancy'])['reason']);
        self::assertTrue($held->body['financial_hold']);
        self::assertSame('0', $held->body['spendable_minor']);
        self::assertSame('RUB', $held->body['currency']);
        self::assertSame(['RUB', 'pa-1', 1], [$this->observedRow()['currency'], $this->observedRow()['provider_account_ref'],
            self::integer($this->observedRow()['revision'])]);
        self::assertSame(2, $this->rows('billium_observed_payment_revision'));
        self::assertSame(1, $this->rows('billium_payment_revision'));

        $status = $this->application()->make(LedgerQueries::class)->paymentStatus('op-1');
        self::assertSame('held_discrepancy', $status['projectionStatus']);
        self::assertTrue(self::arr($status['financialHold'])['active']);
        self::assertSame('0.00', self::arr($status['spendable'])['amount']);
        $applied = self::arr($status['applied']);
        self::assertSame(['amount' => '20.00', 'currency' => 'RUB'], $applied['capturedTotal']);
        self::assertSame(['amount' => '10.00', 'currency' => 'RUB'], $applied['allocated']);
        self::assertSame($held->body, $this->observe(ObservationFactory::with($envelope, [
            'eventId' => (string) Str::ulid(), 'sourceSequence' => 3,
        ]))->body);
        self::assertSame(1, $this->rows('billium_ledger_discrepancy'));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_a_parked_currency_contradiction_holds_before_any_assignment(bool $compatibleDelivery): void
    {
        $handler = $this->application()->make(ObservationHandler::class);
        foreach ([1 => 'RUB', 2 => 'USD'] as $revision => $currency) {
            $envelope = ObservationFactory::charge('op-1', $revision, 2000, fields: ['currency' => $currency]);
            $o = ObservationHandler::decode($envelope) ?? self::fail('valid observation');
            $item = new BootstrapItem('op-1', $revision, EconomicDigest::of($o), $envelope);
            $this->drainer()->root(fn (TransactionContext $context) => $handler->apply($context, $item));
        }
        self::assertSame(2, $this->rows('billium_observed_payment_revision'));
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);

        if ($compatibleDelivery) {
            $held = $this->observe(ObservationFactory::charge('op-1', 3, 2000));
        } else {
            self::assertSame(['applied', 'applied'], $this->drainer()->retryParked());
            $held = $this->observe(ObservationFactory::charge('op-1', 2, 2000, fields: ['currency' => 'USD']));
        }

        self::assertSame('hold', $held->mode());
        self::assertSame('binding_changed', self::arr($held->body['discrepancy'])['reason']);
        self::assertSame('RUB', $held->body['currency']);
        self::assertTrue($this->paymentRow()['financial_hold']);
        self::assertSame(0, self::integer($this->paymentRow()['applied_revision']));
        self::assertSame(0, $this->rows('billium_allocation'));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_changed_intents_are_both_locked_in_order_before_the_payment(bool $boundIntentFirst): void
    {
        $this->debt('o1', 1000);
        foreach ($boundIntentFirst ? ['intent-a', 'intent-b'] : ['intent-b', 'intent-a'] as $ref) {
            $this->intent($ref, ['o1']);
        }
        $this->observe(ObservationFactory::charge('op-1', 1, 2000));
        $intents = array_map(static fn (mixed $id): string => (string) self::integer($id),
            array_column($this->table('SELECT id FROM billium_settlement_intent ORDER BY id'), 'id'));
        $locks = [];
        $this->application()->make(DatabaseManager::class)->listen(static function (QueryExecuted $query) use (&$locks): void {
            if (str_contains($query->sql, 'billium_lock_settlement_intent')) {
                $locks[] = ['intent', $query->bindings[0]];
            } elseif (str_contains($query->sql, 'billium_lock_payment')) {
                $locks[] = ['payment', $query->bindings[0]];
            }
        });

        $held = $this->observe(ObservationFactory::charge('op-1', 2, 2000, sequence: 2, fields: ['businessIntentRef' => 'intent-b']));

        self::assertSame('hold', $held->mode());
        self::assertSame('binding_changed', self::arr($held->body['discrepancy'])['reason']);
        self::assertSame([['intent', '{'.implode(',', $intents).'}'], ['payment', '{'.self::integer($this->paymentRow()['id']).'}']], $locks);
        self::assertSame($this->value("SELECT id FROM billium_settlement_intent WHERE business_intent_ref = 'intent-a'"),
            $this->paymentRow()['settlement_intent_id']);
        self::assertSame(1000, $this->allocated('o1'));
    }

    #[TestWith(['pending'])]
    #[TestWith(['succeeded'])]
    public function test_all_bootstrap_history_is_held_before_a_later_delivery_can_allocate(string $state): void
    {
        $this->bootstrapper()->execute($this->snapshot('boot', 'L1', 2, [
            ObservationFactory::charge('op-1', 1, 4000, sequence: 1, fields: ['state' => 'succeeded']),
            ObservationFactory::charge('op-1', 2, 4000, sequence: 2, fields: ['state' => 'pending']),
        ]));
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);

        $receipt = $this->observe(ObservationFactory::charge('op-1', 3, 4000, sequence: 3, fields: ['state' => $state]));

        self::assertSame('hold', $receipt->mode());
        self::assertSame('regression_state', self::arr($receipt->body['discrepancy'])['reason']);
        self::assertTrue($receipt->body['financial_hold']);
        self::assertSame([], $receipt->body['allocations']);
        self::assertSame('0', $receipt->body['spendable_minor']);
        self::assertSame([], $this->table('SELECT id FROM billium_allocation'));
        $this->drainer()->retryParked();
        self::assertSame(0, $this->rows('billium_allocation'));
        self::assertTrue($this->paymentRow()['financial_hold']);
    }

    public function test_a_compatible_delivery_waits_for_bootstrap_parks_and_retries_in_revision_order(): void
    {
        $this->bootstrapper()->execute($this->snapshot('boot', 'L1', 2, [
            ObservationFactory::charge('op-1', 1, 2000, sequence: 1, fields: ['state' => 'pending']),
            ObservationFactory::charge('op-1', 2, 3000, sequence: 2, fields: ['state' => 'pending']),
        ]));
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);

        self::assertSame(ProblemCode::ObservationParked->value,
            $this->attempt(ObservationFactory::charge('op-1', 3, 4000, sequence: 3)));
        self::assertSame(0, $this->rows('billium_payment'));
        self::assertSame('operation_revision', $this->value('SELECT dependency_kind FROM billium_observation_park WHERE revision = 3'));

        self::assertSame(['applied', 'applied', 'applied'], $this->drainer()->retryParked());
        self::assertSame([1, 2, 3], array_column($this->table('SELECT revision FROM billium_payment_revision ORDER BY id'), 'revision'));
        self::assertSame(0, $this->rows('billium_observation_park', 'released_at IS NULL'));
        self::assertSame(1000, $this->allocated('o1'));
        self::assertFalse($this->paymentRow()['financial_hold']);
    }

    #[TestWith([3000, 0, 0, 'regression_captured'])]
    #[TestWith([4000, 500, 0, 'regression_refunded'])]
    public function test_persisted_amount_regressions_stay_held_after_totals_recover(int $secondCaptured, int $firstRefunded, int $secondRefunded, string $reason): void
    {
        $this->bootstrapper()->execute($this->snapshot('boot', 'L1', 2, [
            ObservationFactory::charge('op-1', 1, 4000, $firstRefunded, sequence: 1, fields: ['state' => 'pending']),
            ObservationFactory::charge('op-1', 2, $secondCaptured, $secondRefunded, sequence: 2, fields: ['state' => 'pending']),
        ]));
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);

        $receipt = $this->observe(ObservationFactory::charge('op-1', 3, 4000, $firstRefunded, sequence: 3, fields: ['state' => 'pending']));

        self::assertSame('hold', $receipt->mode());
        self::assertSame($reason, self::arr($receipt->body['discrepancy'])['reason']);
        self::assertSame('0', $receipt->body['spendable_minor']);
        self::assertSame(0, $this->rows('billium_allocation'));
    }

    #[TestWith([1, 'succeeded', 4000, 500, 'pending', 4000, 500, 'regression_state'])]
    #[TestWith([1, 'failed-terminal', 4000, 500, 'succeeded', 4000, 500, 'regression_state'])]
    #[TestWith([1, 'cancelled', 4000, 500, 'pending', 4000, 500, 'regression_state'])]
    #[TestWith([1, 'pending', 4000, 500, 'pending', 3000, 500, 'regression_captured'])]
    #[TestWith([1, 'pending', 4000, 500, 'pending', 4000, 0, 'regression_refunded'])]
    #[TestWith([3, 'succeeded', 4000, 500, 'failed-terminal', 4000, 500, 'regression_state'])]
    #[TestWith([3, 'pending', 4000, 500, 'pending', 5000, 500, 'regression_captured'])]
    #[TestWith([3, 'pending', 4000, 500, 'pending', 4000, 1000, 'regression_refunded'])]
    #[TestWith([1, 'pending', 3000, 0, 'succeeded', 4000, 500, null])]
    #[TestWith([3, 'succeeded', 4000, 500, 'pending', 3000, 0, null])]
    public function test_parked_observed_history_bounds_the_first_assignment(int $neighborRevision, string $neighborState, int $neighborCaptured, int $neighborRefunded, string $state, int $captured, int $refunded, ?string $reason): void
    {
        $neighbor = ObservationFactory::charge('op-1', $neighborRevision, $neighborCaptured, $neighborRefunded, fields: ['state' => $neighborState]);
        $observation = ObservationHandler::decode($neighbor) ?? self::fail('valid observation');
        $item = new BootstrapItem('op-1', $neighborRevision, EconomicDigest::of($observation), $neighbor);
        $handler = $this->application()->make(ObservationHandler::class);
        $this->drainer()->root(fn (TransactionContext $context) => $handler->apply($context, $item));
        self::assertSame(0, $this->rows('billium_payment'));
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $envelope = ObservationFactory::charge('op-1', 2, $captured, $refunded, fields: ['state' => $state]);

        $waitsForEarlier = $reason === null && $neighborRevision < 2;
        if ($waitsForEarlier) {
            self::assertSame(ProblemCode::ObservationParked->value, $this->attempt($envelope));
            self::assertSame(['applied', 'applied'], $this->drainer()->retryParked());
        }
        $receipt = $this->observe($envelope);

        self::assertSame($reason === null ? 'assign' : 'hold', $receipt->mode());
        self::assertSame($reason, $reason === null ? $receipt->body['discrepancy'] : self::arr($receipt->body['discrepancy'])['reason']);
        self::assertSame($reason === null ? 2 : 0, self::integer($this->paymentRow()['applied_revision']));
        self::assertSame($reason === null ? $captured : 0, self::integer($this->paymentRow()['captured_minor']));
        self::assertSame($reason === null ? $refunded : 0, self::integer($this->paymentRow()['refunded_minor']));
        self::assertSame($reason !== null, $this->paymentRow()['financial_hold']);
        self::assertSame($reason === null ? 1000 : 0, $this->allocated('o1'));
        self::assertSame($reason === null ? ($waitsForEarlier ? 2 : 1) : 0, $this->rows('billium_payment_revision'));
        self::assertSame($reason === null ? 0 : 1, $this->rows('billium_ledger_discrepancy', 'hold'));
        self::assertSame($reason === null ? 0 : 1, $this->rows('billium_outbox_event', "type = 'discrepancy.recorded.v1'"));
        self::assertSame(2, $this->rows('billium_observed_payment_revision'));
        self::assertSame(0, $this->rows('billium_outbox_command'));
        self::assertSame($receipt->body, $this->observe($envelope)->body);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_retrying_parked_live_and_bootstrap_history_holds_before_any_assignment(bool $bootstrap): void
    {
        foreach ([1 => 'succeeded', 2 => 'pending'] as $revision => $state) {
            $envelope = ObservationFactory::charge('op-1', $revision, 4000, sequence: $revision, fields: ['state' => $state]);
            if ($bootstrap || $revision === 2) {
                $observation = ObservationHandler::decode($envelope) ?? self::fail('valid observation');
                $item = new BootstrapItem('op-1', $revision, EconomicDigest::of($observation), $envelope);
                $handler = $this->application()->make(ObservationHandler::class);
                $this->drainer()->root(fn (TransactionContext $context) => $handler->apply($context, $item));
            } else {
                self::assertSame(ProblemCode::ObservationParked->value, $this->attempt($envelope));
            }
        }
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);

        self::assertSame(['applied', 'applied'], $this->drainer()->retryParked());

        self::assertTrue($this->paymentRow()['financial_hold']);
        self::assertSame([0, 0, 0], [self::integer($this->paymentRow()['applied_revision']),
            self::integer($this->paymentRow()['captured_minor']), self::integer($this->paymentRow()['refunded_minor'])]);
        self::assertSame(0, $this->rows('billium_payment_revision'));
        self::assertSame(0, $this->rows('billium_allocation'));
        self::assertSame(2, $this->rows('billium_ledger_discrepancy', "reason = 'regression_state' AND hold"));
        self::assertSame(0, $this->rows('billium_observation_park', 'released_at IS NULL'));
        self::assertSame([], $this->drainer()->retryParked());
    }

    #[TestWith(['succeeded', 'pending', 4000, 500, 'regression_state'])]
    #[TestWith(['pending', 'pending', 3000, 500, 'regression_captured'])]
    #[TestWith(['pending', 'pending', 4000, 0, 'regression_refunded'])]
    #[TestWith(['pending', 'succeeded', 5000, 1000, null])]
    public function test_unapplied_history_also_bounds_a_newer_assignment(string $neighborState, string $state, int $captured, int $refunded, ?string $reason): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, fields: ['state' => 'pending']));
        $this->routine('record_observation', ['inst-test', 'seller-a', 'test', 'embedded-commerz', 'L1', 'op-1', 'charge', null, 3, self::digest('c'),
            $neighborState, 'RUB', 6000, 6000, 4000, 500, 'pa-1', 'ev-neighbor', '{}']);

        $receipt = $this->observe(ObservationFactory::charge('op-1', 4, $captured, $refunded, sequence: 2, fields: ['state' => $state]));

        self::assertSame($reason === null ? 'assign' : 'hold', $receipt->mode());
        self::assertSame($reason, $reason === null ? $receipt->body['discrepancy'] : self::arr($receipt->body['discrepancy'])['reason']);
        self::assertSame($reason === null ? 4 : 1, self::integer($this->paymentRow()['applied_revision']));
        self::assertSame($reason === null ? $captured : 1000, self::integer($this->paymentRow()['captured_minor']));
        self::assertSame($reason === null ? $refunded : 0, self::integer($this->paymentRow()['refunded_minor']));
        self::assertSame($reason === null ? 2 : 1, $this->rows('billium_payment_revision'));
        self::assertSame($reason === null ? 0 : 1, $this->rows('billium_ledger_discrepancy', 'hold'));
        self::assertSame($reason !== null, $this->paymentRow()['financial_hold']);
        self::assertSame(1000, $this->allocated('o1'));
        self::assertSame(3, $this->rows('billium_observed_payment_revision'));
    }

    #[TestWith(['succeeded', 'pending', 4000, 500, 'regression_state'])]
    #[TestWith(['failed-terminal', 'accepted-unknown', 4000, 500, 'regression_state'])]
    #[TestWith(['cancelled', 'succeeded', 4000, 500, 'regression_state'])]
    #[TestWith(['pending', 'pending', 2000, 500, 'regression_captured'])]
    #[TestWith(['pending', 'pending', 4000, 0, 'regression_refunded'])]
    #[TestWith(['pending', 'pending', 6000, 500, 'regression_captured'])]
    #[TestWith(['pending', 'pending', 4000, 1500, 'regression_refunded'])]
    #[TestWith(['pending', 'failed-terminal', 4000, 500, 'regression_state'])]
    #[TestWith(['pending', 'pending', 4000, 500, null])]
    #[TestWith(['succeeded', 'succeeded', 4000, 500, null])]
    public function test_an_intermediate_revision_must_fit_both_persisted_neighbors(string $previous, string $state, int $captured, int $refunded, ?string $reason): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 3000, 500, fields: ['state' => $previous]));
        $next = $previous === 'pending' ? 'succeeded' : $previous;
        $this->observe(ObservationFactory::charge('op-1', 3, 5000, 1000, sequence: 2, fields: ['state' => $next]));
        $envelope = ObservationFactory::charge('op-1', 2, $captured, $refunded, sequence: 3, fields: ['state' => $state]);

        $receipt = $this->observe($envelope);

        self::assertSame($reason === null ? 'stale' : 'hold', $receipt->mode());
        self::assertSame($reason, $reason === null ? $receipt->body['discrepancy'] : self::arr($receipt->body['discrepancy'])['reason']);
        self::assertSame($reason === null ? '3000' : '0', $receipt->body['spendable_minor']);
        self::assertSame([3, 5000, 1000, 1000, $reason !== null], [self::integer($this->paymentRow()['applied_revision']),
            self::integer($this->paymentRow()['captured_minor']), self::integer($this->paymentRow()['refunded_minor']),
            self::integer($this->paymentRow()['allocated_minor']), $this->paymentRow()['financial_hold']]);
        self::assertSame(3, self::integer($this->observedRow()['revision']));
        self::assertSame(3, $this->rows('billium_observed_payment_revision'));
        self::assertSame($reason === null ? 0 : 1, $this->rows('billium_ledger_discrepancy', 'hold'));
        self::assertSame($reason === null ? 0 : 1, $this->rows('billium_outbox_event', "type = 'discrepancy.recorded.v1'"));
        self::assertSame(2, $this->rows('billium_payment_revision'));
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertSame(0, $this->rows('billium_outbox_command'));
        self::assertSame($receipt->body, $this->observe($envelope)->body);
        self::assertSame($reason === null ? 0 : 1, $this->rows('billium_ledger_discrepancy'));
    }

    #[TestWith([2, 'pending', 'succeeded', 'regression_captured'])]
    #[TestWith([4, 'succeeded', 'pending', 'regression_state'])]
    public function test_previously_stale_history_also_bounds_an_intermediate_revision(int $revision, string $neighborState, string $state, string $reason): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, fields: ['state' => 'pending']));
        $this->observe(ObservationFactory::charge('op-1', 5, 5000, sequence: 2));
        self::assertSame('stale', $this->observe(ObservationFactory::charge('op-1', 3, 3000, sequence: 3, fields: ['state' => $neighborState]))->mode());

        $held = $this->observe(ObservationFactory::charge('op-1', $revision, 4000, sequence: 4, fields: ['state' => $state]));

        self::assertSame('hold', $held->mode());
        self::assertSame($reason, self::arr($held->body['discrepancy'])['reason']);
        self::assertSame('3000', self::arr($held->body['discrepancy'])['expected_minor']);
        self::assertSame(5, self::integer($this->paymentRow()['applied_revision']));
        self::assertSame(2, $this->rows('billium_payment_revision'));
    }

    /** @param array<string, mixed> $fields */
    #[TestWith([5000, 6000, [], 'over_refund'])]
    #[TestWith([6000, 0, ['authorizedTotal' => 5000], 'over_capture'])]
    #[TestWith([6000, 0, [], 'regression_captured'])]
    #[TestWith([5000, 1000, [], 'regression_refunded'])]
    #[TestWith([5000, 0, ['state' => 'failed-terminal'], 'regression_state'])]
    #[TestWith([5000, 0, ['discrepancy' => ['discrepancyRef' => 'disc-old', 'kind' => 'amount_mismatch', 'revision' => 1]], 'source_reported'])]
    public function test_an_unseen_older_contradiction_is_held_without_changing_applied_money(int $captured, int $refunded, array $fields, string $reason): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 3, 5000));
        $older = ObservationFactory::charge('op-1', 2, $captured, $refunded, sequence: 2, fields: $fields);

        $held = $this->observe($older);

        self::assertSame('hold', $held->mode());
        self::assertSame($reason, self::arr($held->body['discrepancy'])['reason']);
        self::assertSame('0', $held->body['spendable_minor']);
        self::assertSame([3, 5000, 0, 1000, true], [self::integer($this->paymentRow()['applied_revision']),
            self::integer($this->paymentRow()['captured_minor']), self::integer($this->paymentRow()['refunded_minor']),
            self::integer($this->paymentRow()['allocated_minor']), $this->paymentRow()['financial_hold']]);
        self::assertSame(3, self::integer($this->observedRow()['revision']), 'the latest observed snapshot stays current');
        self::assertSame($refunded, self::integer($this->value('SELECT refunded_minor FROM billium_observed_payment_revision WHERE revision = 2')));
        self::assertSame(1, $this->rows('billium_ledger_discrepancy', 'hold'));
        self::assertSame(1, $this->rows('billium_outbox_event', "type = 'discrepancy.recorded.v1'"));
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertSame(1, $this->rows('billium_payment_revision'));
        self::assertSame(0, $this->rows('billium_outbox_command'));

        self::assertSame($held->body, $this->observe($older)->body, 'an exact delivery replay keeps its receipt');
        self::assertSame(1, $this->rows('billium_ledger_discrepancy'));
    }

    #[TestWith(['pending', 3000, 0])]
    #[TestWith(['accepted-unknown', 0, 0])]
    #[TestWith(['succeeded', 5000, 1000])]
    public function test_a_compatible_unseen_older_snapshot_stays_stale(string $state, int $captured, int $refunded): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 3, 5000, 1000));

        $stale = $this->observe(ObservationFactory::charge('op-1', 2, $captured, $refunded, sequence: 2, fields: ['state' => $state]));

        self::assertSame('stale', $stale->mode());
        self::assertSame('3000', $stale->body['spendable_minor']);
        self::assertSame(3, self::integer($this->paymentRow()['applied_revision']));
        self::assertFalse($this->paymentRow()['financial_hold']);
        self::assertSame(0, $this->rows('billium_ledger_discrepancy'));
        self::assertSame(1, $this->rows('billium_allocation'));
    }

    #[TestWith(['succeeded', 'pending'])]
    #[TestWith(['succeeded', 'accepted-unknown'])]
    #[TestWith(['succeeded', 'failed-terminal'])]
    #[TestWith(['succeeded', 'cancelled'])]
    #[TestWith(['failed-terminal', 'pending'])]
    #[TestWith(['failed-terminal', 'succeeded'])]
    #[TestWith(['cancelled', 'pending'])]
    #[TestWith(['cancelled', 'succeeded'])]
    public function test_a_terminal_state_contradiction_is_observed_but_keeps_the_last_applied_revision(string $previous, string $next): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $consistent = $this->observe(ObservationFactory::charge('op-1', 1, 5000, sequence: 1, fields: ['state' => $previous]));
        self::assertSame('4000', $consistent->body['spendable_minor']);

        $envelope = ObservationFactory::charge('op-1', 2, 5000, sequence: 2, fields: ['state' => $next]);
        $held = $this->observe($envelope);

        self::assertSame('hold', $held->mode());
        self::assertSame('regression_state', self::arr($held->body['discrepancy'])['reason']);
        self::assertSame('0', self::arr($held->body['discrepancy'])['exposure_minor']);
        self::assertTrue($held->body['financial_hold']);
        self::assertSame('0', $held->body['spendable_minor']);
        self::assertSame(1, self::arr($held->body['applied'])['revision']);
        self::assertSame([1, 5000, 1000, true], [self::integer($this->paymentRow()['applied_revision']), self::integer($this->paymentRow()['captured_minor']),
            self::integer($this->paymentRow()['allocated_minor']), $this->paymentRow()['financial_hold']]);
        self::assertSame(2, self::integer($this->observedRow()['revision']));

        $status = $this->application()->make(LedgerQueries::class)->paymentStatus('op-1');
        self::assertSame('held_discrepancy', $status['projectionStatus']);
        self::assertSame([1, $previous], [self::arr($status['applied'])['revision'], self::arr($status['applied'])['state']]);
        self::assertSame($next, self::arr($status['observed'])['state']);
        self::assertTrue(self::arr($status['financialHold'])['active']);
        self::assertSame('0.00', self::arr($status['spendable'])['amount']);
        self::assertContains($envelope->evidenceRef, self::arr(self::arr($status['observed'])['evidenceRefs']));
        self::assertSame(1, $this->rows('billium_ledger_discrepancy', "reason = 'regression_state' AND hold"));
        self::assertSame(1, $this->rows('billium_outbox_event', "type = 'discrepancy.recorded.v1'"));
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertSame(0, $this->rows('billium_outbox_command'));
    }

    #[TestWith(['succeeded', 'succeeded'])]
    #[TestWith(['failed-terminal', 'failed-terminal'])]
    #[TestWith(['cancelled', 'cancelled'])]
    #[TestWith(['pending', 'succeeded'])]
    #[TestWith(['accepted-unknown', 'pending'])]
    public function test_compatible_states_can_advance_the_applied_revision(string $previous, string $next): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 5000, sequence: 1, fields: ['state' => $previous]));

        $applied = $this->observe(ObservationFactory::charge('op-1', 2, 5000, sequence: 2, fields: ['state' => $next]));

        self::assertSame('assign', $applied->mode());
        self::assertSame(2, self::arr($applied->body['applied'])['revision']);
        self::assertFalse($applied->body['financial_hold']);
        self::assertSame('4000', $applied->body['spendable_minor']);
        self::assertSame(0, $this->rows('billium_ledger_discrepancy'));
    }

    public function test_an_actual_over_refund_is_kept_held_and_never_clamped(): void
    {
        ['consistent' => $consistent, 'held' => $held, 'refusal' => $refusal] = $this->overRefund('embedded-commerz');

        self::assertSame('1000', $consistent->body['spendable_minor'], 'before the exception 30.00 − 20.00 allocated was spendable');
        self::assertSame('hold', $held->mode());
        self::assertEquals(['revision' => 2, 'captured_minor' => '10000', 'refunded_minor' => '7000'], $held->body['applied']);
        self::assertSame('14000', self::arr($held->body['observed'])['refunded_minor']);
        $discrepancy = self::arr($held->body['discrepancy']);
        self::assertSame('over_refund', $discrepancy['reason']);
        self::assertSame('4000', $discrepancy['exposure_minor']);
        self::assertSame('10000', $discrepancy['expected_minor']);
        self::assertSame('14000', $discrepancy['actual_minor']);
        self::assertTrue($held->body['financial_hold']);
        self::assertSame('0', $held->body['spendable_minor']);
        self::assertSame(ProblemCode::AllocationReallocationRequired->value, $refusal, 'typed financial-hold allocation refusal');

        $payment = $this->paymentRow();
        self::assertSame([10000, 7000, 2000, true, 'held', 2], [self::integer($payment['captured_minor']), self::integer($payment['refunded_minor']),
            self::integer($payment['allocated_minor']), $payment['financial_hold'], $payment['projection_status'], self::integer($payment['applied_revision'])]);
        $observed = $this->observedRow();
        self::assertSame([10000, 14000, 3], [self::integer($observed['captured_minor']), self::integer($observed['refunded_minor']), self::integer($observed['revision'])]);
        self::assertSame(1, $this->rows('billium_ledger_discrepancy', "reason = 'over_refund' AND hold AND expected_minor = 10000 AND actual_minor = 14000"));
        self::assertSame(1, $this->rows('billium_outbox_event', "type = 'discrepancy.recorded.v1'"));
        self::assertSame(2, $this->rows('billium_allocation'), 'no allocation and no reversal from the exception');
        self::assertSame(0, $this->rows('billium_outbox_command'), 'no automatic refund, charge or collection command');
    }

    public function test_over_capture_and_regression_are_held_with_the_last_consistent_totals(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 5000, sequence: 1));

        $over = $this->observe(ObservationFactory::charge('op-1', 2, 7000, sequence: 2, fields: ['authorizedTotal' => 6000]));
        self::assertSame('over_capture', self::arr($over->body['discrepancy'])['reason']);
        self::assertSame('1000', self::arr($over->body['discrepancy'])['exposure_minor']);
        self::assertSame(5000, self::integer($this->paymentRow()['captured_minor']));

        $regression = $this->observe(ObservationFactory::charge('op-1', 3, 3000, sequence: 3));
        self::assertSame('hold', $regression->mode());
        self::assertSame('regression_captured', self::arr($regression->body['discrepancy'])['reason']);
        self::assertSame(5000, self::integer($this->paymentRow()['captured_minor']), 'no silent negative cumulative overwrite');
        self::assertSame(3000, self::integer($this->observedRow()['captured_minor']));
        self::assertSame(2, $this->rows('billium_ledger_discrepancy', 'hold'));
    }

    public function test_an_actual_effect_against_a_closed_intent_is_held_without_reopening_it(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $intent = self::integer($this->value("SELECT id FROM billium_settlement_intent WHERE business_intent_ref = 'intent-a'"));
        $this->owner()->update("UPDATE billium_settlement_intent SET status = 'closed_no_effect', revision = revision + 1 WHERE id = ?", [$intent]);

        $held = $this->observe(ObservationFactory::charge('op-1', 1, 1000));

        self::assertSame('hold', $held->mode());
        self::assertSame('closed_intent_effect', self::arr($held->body['discrepancy'])['reason']);
        self::assertNull($this->paymentRow()['settlement_intent_id']);
        self::assertSame('closed_no_effect', $this->value('SELECT status FROM billium_settlement_intent WHERE id = ?', [$intent]));
        self::assertSame(0, $this->rows('billium_allocation'));
        self::assertSame(1000, self::integer($this->observedRow()['captured_minor']));
    }
}

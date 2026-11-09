<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Database backstops of the observation path (D60, INV-01, INV-06): the runtime role reads observation tables and only
 * EXECUTEs the guarded routines; a direct counter or park write is refused; the projection routine itself refuses an
 * inconsistent snapshot in assign mode (only hold may carry it) and a parked marker is released once, never edited.
 */
#[Group('D60')]
final class ObservationBackstopTest extends ObservationTestCase
{
    #[TestWith(['succeeded', 'pending', false])]
    #[TestWith(['succeeded', 'unknown', false])]
    #[TestWith(['succeeded', 'failed', false])]
    #[TestWith(['failed-terminal', 'succeeded', false])]
    #[TestWith(['failed-terminal', 'pending', false])]
    #[TestWith(['failed-terminal', 'cancelled', false])]
    #[TestWith(['cancelled', 'pending', false])]
    #[TestWith(['cancelled', 'succeeded', false])]
    #[TestWith(['cancelled', 'failed', false])]
    #[TestWith(['succeeded', 'succeeded', true])]
    #[TestWith(['failed-terminal', 'failed', true])]
    #[TestWith(['cancelled', 'cancelled', true])]
    #[TestWith(['pending', 'succeeded', true])]
    public function test_the_granted_routine_guards_terminal_state_using_the_applied_revision(string $previous, string $next, bool $compatible): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 5000, fields: ['state' => $previous]));
        $observed = self::integer($this->observedRow()['id']);
        $intent = self::integer($this->value('SELECT id FROM billium_settlement_intent'));
        $this->routine('record_observation', ['inst-test', 'seller-a', 'test', 'embedded-commerz', 'L1', 'op-1', 'charge', null, 2, self::digest('b'),
            $next, 'RUB', 5000, 5000, 5000, 0, 'pa-1', 'ev-x', '{}']);

        try {
            $this->routine('project_payment', [...$this->scopeArguments(), $observed, 2, 'op-1', $intent, 'assign', '[]', null, 'observation:op-1#2', self::digest('b')]);
            self::assertTrue($compatible, 'a terminal-state change cannot bypass the application through the runtime routine');
        } catch (PDOException $exception) {
            self::assertFalse($compatible);
            self::assertStringContainsString('billium.observation.conflict', $exception->getMessage());
        }
        self::assertSame($compatible ? 2 : 1, self::integer($this->paymentRow()['applied_revision']));
        self::assertSame($compatible ? 2 : 1, $this->rows('billium_payment_revision'));
        self::assertSame($compatible ? 1 : 0, $this->rows('billium_allocation_receipt', "receipt_ref = 'observation:op-1#2'"));
        self::assertSame([5000, 0, 1000], [self::integer($this->paymentRow()['captured_minor']),
            self::integer($this->paymentRow()['refunded_minor']), self::integer($this->paymentRow()['allocated_minor'])]);
        self::assertSame($next, $this->observedRow()['state'], 'the mutable latest observed state is not the applied-state basis');
        self::assertFalse($this->paymentRow()['financial_hold'], 'a refused direct call rolls back without mutating the payment');
    }

    public function test_runtime_cannot_write_observation_state_directly(): void
    {
        $runtime = TestEnvironment::pdo();
        foreach ([
            "INSERT INTO billium_observation_park(installation_ref,seller_ref,environment,observed_payment_id,revision,dependency_kind,dependency_ref) VALUES('inst-test','seller-a','test',1,1,'settlement_intent','x')",
            'UPDATE billium_payment SET refunded_minor = 0',
            'UPDATE billium_observed_payment SET captured_minor = 0',
            'UPDATE billium_refund_reconcile_progress SET compared_at = now()',
            "INSERT INTO billium_ledger_discrepancy(installation_ref,seller_ref,environment,fingerprint,comparison_basis_digest,observed_payment_id,observed_revision,expected_minor,actual_minor,reason) VALUES('inst-test','seller-a','test','f','sha256:".str_repeat('a', 64)."',1,1,0,0,'x')",
        ] as $sql) {
            self::assertSame('42501', self::sqlState($runtime, $sql), $sql);
        }
        foreach (['billium_record_observation', 'billium_project_payment', 'billium_record_refund_detail', 'billium_park_observation',
            'billium_release_observation_park', 'billium_reconcile_refund_basis'] as $routine) {
            self::assertTrue(self::pdoValue($runtime, "SELECT bool_and(has_function_privilege(current_user, p.oid, 'EXECUTE')) FROM pg_proc p WHERE p.proname = '{$routine}'"), $routine);
        }
        self::assertFalse(self::pdoValue($runtime, "SELECT has_function_privilege(current_user, 'public.billium_observation_park_guard()', 'EXECUTE')"));
        self::assertSame(1, self::integer($this->value("SELECT count(*) FROM pg_proc WHERE proname = 'billium_project_payment'")), 'no stale overload');
    }

    public function test_the_projection_routine_refuses_an_inconsistent_snapshot_outside_hold(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, sequence: 1));
        $observed = self::integer($this->observedRow()['id']);
        $intent = self::integer($this->value('SELECT id FROM billium_settlement_intent'));
        $this->routine('record_observation', ['inst-test', 'seller-a', 'test', 'embedded-commerz', 'L1', 'op-1', 'charge', null, 2, self::digest('b'),
            'succeeded', 'RUB', 1000, 1000, 1000, 1500, 'pa-1', 'ev-x', '{}']);

        try {
            $this->routine('project_payment', [...$this->scopeArguments(), $observed, 2, 'op-1', $intent, 'assign', '[]', null, 'observation:op-1#2', self::digest('b')]);
            self::fail('an over-refund cannot be assigned');
        } catch (PDOException $exception) {
            self::assertStringContainsString('billium.observation.conflict', $exception->getMessage());
        }
        self::assertSame([1000, 0, 1], [self::integer($this->paymentRow()['captured_minor']), self::integer($this->paymentRow()['refunded_minor']),
            self::integer($this->paymentRow()['applied_revision'])]);
    }

    public function test_the_granted_projection_routine_refuses_over_capture_and_keeps_applied_totals(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, sequence: 1));
        $observed = self::integer($this->observedRow()['id']);
        $intent = self::integer($this->value('SELECT id FROM billium_settlement_intent'));
        $this->routine('record_observation', ['inst-test', 'seller-a', 'test', 'embedded-commerz', 'L1', 'op-1', 'charge', null, 2, self::digest('b'),
            'succeeded', 'RUB', 1000, 1000, 1500, 0, 'pa-1', 'ev-x', '{}']);

        try {
            $this->routine('project_payment', [...$this->scopeArguments(), $observed, 2, 'op-1', $intent, 'assign', '[]', null, 'observation:op-1#2', self::digest('b')]);
            self::fail('an over-capture cannot be assigned by the runtime role');
        } catch (PDOException $exception) {
            self::assertStringContainsString('billium.observation.conflict', $exception->getMessage());
        }
        $payment = $this->paymentRow();
        self::assertSame([1000, 1000, 0, 1], [self::integer($payment['authorized_minor']), self::integer($payment['captured_minor']),
            self::integer($payment['refunded_minor']), self::integer($payment['applied_revision'])]);
        self::assertSame(1000, $this->allocated('o1'));
        self::assertSame(1500, self::integer($this->observedRow()['captured_minor']), 'the actual fact is retained');
        self::assertSame(1, $this->rows('billium_payment_revision'));
        self::assertSame(0, $this->rows('billium_allocation_receipt', "receipt_ref = 'observation:op-1#2'"));
    }

    public function test_a_parked_marker_is_released_once_and_never_edited(): void
    {
        $this->attempt(ObservationFactory::charge('op-1', 1, 1000, sequence: 1));
        $park = self::integer($this->value('SELECT id FROM billium_observation_park'));
        $this->routine('release_observation_park', ['seller-a', 'test', $park, null, 'applied']);

        $owner = TestEnvironment::pdo(owner: true);
        foreach (["UPDATE billium_observation_park SET released_at = NULL, release_reason = NULL WHERE id = {$park}",
            "UPDATE billium_observation_park SET release_reason = 'other', released_at = now() WHERE id = {$park}",
            "UPDATE billium_observation_park SET dependency_ref = 'other' WHERE id = {$park}",
            "DELETE FROM billium_observation_park WHERE id = {$park}"] as $sql) {
            self::assertSame('55000', self::sqlState($owner, $sql), $sql);
        }
        self::assertSame(0, self::integer(self::arr($this->routine('release_observation_park', ['seller-a', 'test', $park, null, 'again'])['body'])['released']));
        self::assertSame('applied', $this->value('SELECT release_reason FROM billium_observation_park'));
    }
}

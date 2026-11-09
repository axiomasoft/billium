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
    /** @param array<int, mixed> $overrides */
    #[TestWith(['charge', null, [0 => 'other-installation']])]
    #[TestWith(['charge', null, [6 => 'refund', 7 => 'original']])]
    #[TestWith(['refund', 'original', [7 => 'other-original']])]
    #[TestWith(['refund', 'original', []])]
    public function test_only_charge_revisions_with_the_original_identity_can_record_new_binding_evidence(string $kind, ?string $original, array $overrides): void
    {
        $arguments = ['inst-test', 'seller-a', 'test', 'embedded-commerz', 'L1', 'op-1', $kind, $original, 1, self::digest('a'),
            'succeeded', 'RUB', 2000, 2000, 2000, 0, 'pa-1', 'ev-1', '{}'];
        $this->routine('record_observation', $arguments);
        $arguments[8] = 2;
        $arguments[9] = self::digest('b');
        $refused = $this->routine('record_observation', array_values(array_replace($arguments, $overrides)));

        self::assertSame('conflict', self::arr($refused['body'])['disposition']);
        self::assertFalse($refused['replayed']);
        self::assertSame(1, $this->rows('billium_observed_payment_revision'));
        self::assertSame(1, self::integer($this->observedRow()['revision']));
    }

    #[TestWith(['USD', 'pa-1'])]
    #[TestWith(['RUB', 'pa-2'])]
    public function test_binding_contradictions_are_retained_and_replayed_without_changing_the_observed_identity(string $currency, string $provider): void
    {
        $arguments = ['inst-test', 'seller-a', 'test', 'embedded-commerz', 'L1', 'op-1', 'charge', null, 1, self::digest('a'),
            'succeeded', 'RUB', 2000, 2000, 2000, 0, 'pa-1', 'ev-1', '{}'];
        $this->routine('record_observation', $arguments);
        $original = $this->observedRow();
        $arguments[8] = 2;
        $arguments[9] = self::digest('b');
        $arguments[11] = $currency;
        $arguments[16] = $provider;
        $arguments[17] = 'ev-2';
        $arguments[18] = '{"evidence":"contradiction"}';

        $recorded = $this->routine('record_observation', $arguments);
        self::assertSame('contradiction', self::arr($recorded['body'])['disposition']);
        self::assertFalse($recorded['replayed']);
        $replayed = $this->routine('record_observation', $arguments);
        self::assertSame('contradiction', self::arr($replayed['body'])['disposition']);
        self::assertTrue($replayed['replayed']);
        $arguments[9] = self::digest('c');
        $conflict = $this->routine('record_observation', $arguments);
        self::assertSame('conflict', self::arr($conflict['body'])['disposition']);
        self::assertTrue($conflict['replayed']);
        self::assertSame($original, $this->observedRow());
        $revision = $this->row('SELECT * FROM billium_observed_payment_revision WHERE revision = 2');
        self::assertSame([$currency, self::digest('b'), 'ev-2', '{"evidence": "contradiction"}'],
            [$revision['currency'], $revision['material_digest'], $revision['evidence_ref'], $revision['payload']]);
        self::assertSame(2, $this->rows('billium_observed_payment_revision'));
    }

    #[TestWith(['assign', 2])]
    #[TestWith(['assign', 3])]
    #[TestWith(['stale', 2])]
    #[TestWith(['stale', 1])]
    public function test_currency_contradictions_block_assign_and_stale_even_for_a_compatible_revision(string $mode, int $revision): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $appliedRevision = $mode === 'stale' ? 3 : 1;
        $this->observe(ObservationFactory::charge('op-1', $appliedRevision, 2000));
        $compatibleRevision = $mode === 'stale' ? 1 : 3;
        foreach ([2 => 'USD', $compatibleRevision => 'RUB'] as $number => $currency) {
            $this->routine('record_observation', ['inst-test', 'seller-a', 'test', 'embedded-commerz', 'L1', 'op-1', 'charge', null,
                $number, self::digest((string) $number), 'succeeded', $currency, 2000, 2000, 2000, 0, 'pa-1', 'ev-'.$number, '{}']);
        }
        $digest = self::digest((string) $revision);
        try {
            $this->routine('project_payment', [...$this->scopeArguments(), self::integer($this->observedRow()['id']), $revision, 'op-1',
                self::integer($this->value('SELECT id FROM billium_settlement_intent')), $mode, '[]', null, 'unsafe-projection', $digest]);
            self::fail('currency contradictions require hold mode');
        } catch (PDOException $exception) {
            self::assertStringContainsString('billium.observation.conflict', $exception->getMessage());
        }
        self::assertSame($appliedRevision, self::integer($this->paymentRow()['applied_revision']));
        self::assertSame(0, $this->rows('billium_allocation_receipt', "receipt_ref = 'unsafe-projection'"));
    }

    #[TestWith(['pending'])]
    #[TestWith(['succeeded'])]
    #[TestWith(['pending', 3000, 0])]
    #[TestWith(['pending', 4000, 500])]
    public function test_assign_mode_rejects_a_contradiction_anywhere_in_persisted_history(string $state, int $secondCaptured = 4000, int $firstRefunded = 0): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $firstState = $secondCaptured < 4000 || $firstRefunded > 0 ? 'pending' : 'succeeded';
        foreach ([1 => $firstState, 2 => 'pending', 3 => $state] as $revision => $revisionState) {
            $this->routine('record_observation', ['inst-test', 'seller-a', 'test', 'embedded-commerz', 'L1', 'op-1', 'charge', null,
                $revision, self::digest((string) $revision), $revisionState, 'RUB', 4000, 4000, $revision === 2 ? $secondCaptured : 4000,
                $revision === 2 ? 0 : $firstRefunded, 'pa-1', 'ev-'.$revision, '{}']);
        }
        $observed = self::integer($this->observedRow()['id']);
        $intent = self::integer($this->value('SELECT id FROM billium_settlement_intent'));

        try {
            $this->routine('project_payment', [...$this->scopeArguments(), $observed, 3, 'op-1', $intent, 'assign', '[]', null, 'new-assignment', self::digest('3')]);
            self::fail('all persisted revisions must constrain assignment');
        } catch (PDOException $exception) {
            self::assertStringContainsString('billium.observation.conflict', $exception->getMessage());
        }
        self::assertSame(0, $this->rows('billium_payment'));
        self::assertSame(0, $this->rows('billium_allocation_receipt', "receipt_ref = 'new-assignment'"));
        self::assertSame(0, $this->rows('billium_allocation'));
    }

    #[TestWith([false, 1, 'succeeded', 4000, 500, 'pending', 4000, 500, false])]
    #[TestWith([false, 1, 'failed', 4000, 500, 'succeeded', 4000, 500, false])]
    #[TestWith([false, 1, 'cancelled', 4000, 500, 'pending', 4000, 500, false])]
    #[TestWith([false, 1, 'pending', 4000, 500, 'pending', 3000, 500, false])]
    #[TestWith([false, 1, 'pending', 4000, 500, 'pending', 4000, 0, false])]
    #[TestWith([false, 3, 'succeeded', 4000, 500, 'failed', 4000, 500, false])]
    #[TestWith([false, 3, 'pending', 4000, 500, 'pending', 5000, 500, false])]
    #[TestWith([false, 3, 'pending', 4000, 500, 'pending', 4000, 1000, false])]
    #[TestWith([false, 1, 'pending', 3000, 0, 'succeeded', 4000, 500, true])]
    #[TestWith([false, 3, 'succeeded', 4000, 500, 'pending', 3000, 0, true])]
    #[TestWith([true, 3, 'succeeded', 4000, 500, 'pending', 4000, 500, false])]
    #[TestWith([true, 3, 'pending', 4000, 500, 'pending', 3000, 500, false])]
    #[TestWith([true, 3, 'pending', 4000, 500, 'pending', 4000, 0, false])]
    #[TestWith([true, 3, 'pending', 3000, 0, 'succeeded', 4000, 500, true])]
    public function test_assign_mode_checks_unapplied_observed_neighbors(bool $existing, int $neighborRevision, string $neighborState, int $neighborCaptured, int $neighborRefunded, string $state, int $captured, int $refunded, bool $compatible): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        if ($existing) {
            $this->observe(ObservationFactory::charge('op-1', 1, 1000, fields: ['state' => 'pending']));
        }
        $this->routine('record_observation', ['inst-test', 'seller-a', 'test', 'embedded-commerz', 'L1', 'op-1', 'charge', null, $neighborRevision, self::digest('c'),
            $neighborState, 'RUB', 6000, 6000, $neighborCaptured, $neighborRefunded, 'pa-1', 'ev-neighbor', '{}']);
        $observed = self::integer($this->observedRow()['id']);
        $intent = self::integer($this->value('SELECT id FROM billium_settlement_intent'));
        $revision = $existing ? 4 : 2;
        $this->routine('record_observation', ['inst-test', 'seller-a', 'test', 'embedded-commerz', 'L1', 'op-1', 'charge', null, $revision, self::digest('b'),
            $state, 'RUB', 6000, 6000, $captured, $refunded, 'pa-1', 'ev-incoming', '{}']);

        try {
            $this->routine('project_payment', [...$this->scopeArguments(), $observed, $revision, 'op-1', $intent, 'assign', '[]', null, 'new-assignment', self::digest('b')]);
            self::assertTrue($compatible, 'unapplied observed history must constrain assignments');
        } catch (PDOException $exception) {
            self::assertFalse($compatible);
            self::assertStringContainsString('billium.observation.conflict', $exception->getMessage());
        }
        self::assertSame($compatible ? 1 : 0, $this->rows('billium_allocation_receipt', "receipt_ref = 'new-assignment'"));
        self::assertSame(($existing ? 1 : 0) + ($compatible ? 1 : 0), $this->rows('billium_payment_revision'));
        self::assertSame($existing ? 1 : 0, $this->rows('billium_allocation'));
        self::assertSame($existing || $compatible ? 1 : 0, $this->rows('billium_payment'));
        if ($existing || $compatible) {
            self::assertSame($compatible ? $revision : 1, self::integer($this->paymentRow()['applied_revision']));
            self::assertSame($compatible ? $captured : 1000, self::integer($this->paymentRow()['captured_minor']));
            self::assertSame($compatible ? $refunded : 0, self::integer($this->paymentRow()['refunded_minor']));
            self::assertFalse($this->paymentRow()['financial_hold']);
        }
        self::assertSame($existing ? 3 : 2, $this->rows('billium_observed_payment_revision'));
        self::assertSame(0, $this->rows('billium_ledger_discrepancy'));
    }

    #[TestWith([2, 'pending', 'succeeded'])]
    #[TestWith([4, 'succeeded', 'pending'])]
    public function test_the_granted_routine_also_checks_previously_stale_neighbors(int $revision, string $neighborState, string $state): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, fields: ['state' => 'pending']));
        $this->observe(ObservationFactory::charge('op-1', 5, 5000, sequence: 2));
        $this->observe(ObservationFactory::charge('op-1', 3, 3000, sequence: 3, fields: ['state' => $neighborState]));
        $observed = self::integer($this->observedRow()['id']);
        $intent = self::integer($this->value('SELECT id FROM billium_settlement_intent'));
        $this->routine('record_observation', ['inst-test', 'seller-a', 'test', 'embedded-commerz', 'L1', 'op-1', 'charge', null, $revision, self::digest('b'),
            $state, 'RUB', 5000, 5000, 4000, 0, 'pa-1', 'ev-x', '{}']);

        try {
            $this->routine('project_payment', [...$this->scopeArguments(), $observed, $revision, 'op-1', $intent, 'stale', '[]', null, 'historical', self::digest('b')]);
            self::fail('stale neighbors must constrain the granted routine');
        } catch (PDOException $exception) {
            self::assertStringContainsString('billium.observation.conflict', $exception->getMessage());
        }
        self::assertSame(5, self::integer($this->paymentRow()['applied_revision']));
        self::assertFalse($this->paymentRow()['financial_hold']);
        self::assertSame(2, $this->rows('billium_payment_revision'));
        self::assertSame(0, $this->rows('billium_allocation_receipt', "receipt_ref = 'historical'"));
    }

    #[TestWith(['pending', 3000, 0, 6000, true])]
    #[TestWith(['succeeded', 5000, 0, 6000, true])]
    #[TestWith(['failed', 3000, 0, 6000, false])]
    #[TestWith(['pending', 6000, 0, 6000, false])]
    #[TestWith(['pending', 3000, 3500, 6000, false])]
    #[TestWith(['pending', 4000, 0, 3000, false])]
    public function test_stale_mode_checks_a_successor_without_a_predecessor(string $state, int $captured, int $refunded, int $authorized, bool $compatible): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 3, 5000));
        $observed = self::integer($this->observedRow()['id']);
        $intent = self::integer($this->value('SELECT id FROM billium_settlement_intent'));
        $this->routine('record_observation', ['inst-test', 'seller-a', 'test', 'embedded-commerz', 'L1', 'op-1', 'charge', null, 2, self::digest('b'),
            $state, 'RUB', 6000, $authorized, $captured, $refunded, 'pa-1', 'ev-x', '{}']);

        try {
            $this->routine('project_payment', [...$this->scopeArguments(), $observed, 2, 'op-1', $intent, 'stale', '[]', null, 'historical', self::digest('b')]);
            self::assertTrue($compatible);
        } catch (PDOException $exception) {
            self::assertFalse($compatible);
            self::assertStringContainsString('billium.observation.conflict', $exception->getMessage());
        }
        self::assertSame($compatible ? 1 : 0, $this->rows('billium_allocation_receipt', "receipt_ref = 'historical'"));
        self::assertSame(3, self::integer($this->paymentRow()['applied_revision']));
        self::assertFalse($this->paymentRow()['financial_hold']);
    }

    #[TestWith(['succeeded', 'pending', 4000, 500, false])]
    #[TestWith(['failed-terminal', 'unknown', 4000, 500, false])]
    #[TestWith(['cancelled', 'succeeded', 4000, 500, false])]
    #[TestWith(['pending', 'pending', 2000, 500, false])]
    #[TestWith(['pending', 'pending', 4000, 0, false])]
    #[TestWith(['pending', 'pending', 6000, 500, false])]
    #[TestWith(['pending', 'pending', 4000, 1500, false])]
    #[TestWith(['pending', 'failed', 4000, 500, false])]
    #[TestWith(['pending', 'pending', 4000, 500, true])]
    #[TestWith(['succeeded', 'succeeded', 4000, 500, true])]
    public function test_the_granted_routine_guards_both_neighbors_in_stale_mode(string $previous, string $state, int $captured, int $refunded, bool $compatible): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 3000, 500, fields: ['state' => $previous]));
        $next = $previous === 'pending' ? 'succeeded' : $previous;
        $this->observe(ObservationFactory::charge('op-1', 3, 5000, 1000, sequence: 2, fields: ['state' => $next]));
        $observed = self::integer($this->observedRow()['id']);
        $intent = self::integer($this->value('SELECT id FROM billium_settlement_intent'));
        $this->routine('record_observation', ['inst-test', 'seller-a', 'test', 'embedded-commerz', 'L1', 'op-1', 'charge', null, 2, self::digest('b'),
            $state, 'RUB', 6000, 6000, $captured, $refunded, 'pa-1', 'ev-x', '{}']);

        try {
            $this->routine('project_payment', [...$this->scopeArguments(), $observed, 2, 'op-1', $intent, 'stale', '[]', null, 'historical#2', self::digest('b')]);
            self::assertTrue($compatible, 'a contradictory historical revision must not bypass hold through stale mode');
        } catch (PDOException $exception) {
            self::assertFalse($compatible);
            self::assertStringContainsString('billium.observation.conflict', $exception->getMessage());
        }
        self::assertSame([3, 5000, 1000, 1000, false], [self::integer($this->paymentRow()['applied_revision']),
            self::integer($this->paymentRow()['captured_minor']), self::integer($this->paymentRow()['refunded_minor']),
            self::integer($this->paymentRow()['allocated_minor']), $this->paymentRow()['financial_hold']]);
        self::assertSame($compatible ? 1 : 0, $this->rows('billium_allocation_receipt', "receipt_ref = 'historical#2'"));
        self::assertSame(2, $this->rows('billium_payment_revision'));
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertSame(3, $this->rows('billium_observed_payment_revision'));
    }

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

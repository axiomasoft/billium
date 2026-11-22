<?php

declare(strict_types=1);

namespace Billium\Testkit\Faults;

use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Enums\EffectKind;
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Drivers\ProviderSettlement;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Oracles\IndependentFinancialOracle;
use Illuminate\Database\Connection;
use Packman\Attributes\Api;
use RuntimeException;

/**
 * Independent monetary conservation and provider-receipt count verifier for PostgreSQL fault suites.
 */
#[Api(since: '1.0')]
final readonly class ConservationVerifier
{
    /**
     * Verifies execution capacity, ledger allocation conservation, and provider effect/receipt alignment.
     *
     * @return array{
     *     providerCalls: int,
     *     providerLookups: int,
     *     providerRecords: int,
     *     providerSucceeded: int,
     *     attempts: int,
     *     providerFacts: int,
     *     oracle: array{authorized: int, captured: int, refunded: int, voided: int},
     *     capacity: array{authorized: int, captured: int, refunded: int, voided: int, heldCapture: int, heldVoid: int, heldRefund: int},
     *     payment: ?array{captured: int, refunded: int, allocated: int},
     *     obligation: ?array{amountDue: int, allocated: int, open: int},
     *     allocationReceipts: int
     * }
     */
    public function verify(
        Connection $db,
        DurableJournal $journal,
        FrozenBinding $binding,
        string $rootOperationRef,
        ?string $obligationRef = null,
        bool $expectFactsMatchSucceeded = true,
    ): array {
        $oracle = new IndependentFinancialOracle;
        $oracle->ingestProvider($journal);
        $businessRef = IndependentFinancialOracle::businessIdentity($binding);
        $oracleTotals = $oracle->totals($businessRef);

        $records = $journal->records();
        $providerCalls = 0;
        $providerLookups = 0;
        $providerRecords = 0;
        $providerSucceeded = 0;
        $hasAuthorizeRoot = false;

        foreach ($records as $record) {
            if ($record instanceof JournalEvent) {
                if ($record->kind === 'provider.call') {
                    $providerCalls++;
                } elseif ($record->kind === 'provider.lookup') {
                    $providerLookups++;
                }
            } elseif ($record instanceof ProviderRecord) {
                $providerRecords++;
                if ($record->kind === EffectKind::Authorize) {
                    $hasAuthorizeRoot = true;
                }
                $status = $record->status;
                foreach ($records as $settlement) {
                    if ($settlement instanceof ProviderSettlement && $settlement->identity === $record->identity) {
                        $status = $settlement->status;
                    }
                }
                if ($status === 'succeeded') {
                    $providerSucceeded++;
                }
            }
        }

        $capRow = $db->selectOne(
            'SELECT authorized_limit_minor, confirmed_capture_minor, confirmed_refund_minor, released_authorization_minor, held_capture_minor, held_void_minor, held_refund_minor FROM billium_execution_capacity WHERE root_operation_ref = ?',
            [$rootOperationRef],
        );
        if (! is_object($capRow)) {
            throw new RuntimeException('Missing execution capacity row for '.$rootOperationRef);
        }

        $capacity = [
            'authorized' => (int) $capRow->authorized_limit_minor,
            'captured' => (int) $capRow->confirmed_capture_minor,
            'refunded' => (int) $capRow->confirmed_refund_minor,
            'voided' => (int) $capRow->released_authorization_minor,
            'heldCapture' => (int) $capRow->held_capture_minor,
            'heldVoid' => (int) $capRow->held_void_minor,
            'heldRefund' => (int) $capRow->held_refund_minor,
        ];

        // Core monetary conservation bounds on execution capacity.
        if (min($capacity) < 0) {
            throw new RuntimeException('Negative execution capacity value observed');
        }
        if ($capacity['authorized'] > 0 && $capacity['authorized'] < $capacity['captured'] + $capacity['heldCapture'] + $capacity['voided'] + $capacity['heldVoid']) {
            throw new RuntimeException('Execution authorization conservation violated');
        }
        if ($capacity['captured'] < $capacity['refunded'] + $capacity['heldRefund']) {
            throw new RuntimeException('Execution refund conservation violated');
        }

        // Cross-check confirmed DB totals against independent provider-journal oracle.
        $oracle->capacities($businessRef, $capacity['heldCapture'], $capacity['heldRefund'], $capacity['heldVoid']);
        if ($expectFactsMatchSucceeded) {
            if ($capacity['captured'] !== $oracleTotals['captured']
                || $capacity['refunded'] !== $oracleTotals['refunded']
                || $capacity['voided'] !== $oracleTotals['voided']
                || ($hasAuthorizeRoot && $capacity['authorized'] !== $oracleTotals['authorized'])) {
                throw new RuntimeException('Execution capacity totals diverge from independent provider oracle');
            }
        }

        $attempts = (int) $db->scalar('SELECT count(*) FROM billium_execution_attempt');
        $providerFacts = (int) $db->scalar('SELECT count(*) FROM billium_execution_provider_fact');
        $allocationReceipts = (int) $db->scalar("SELECT count(*) FROM billium_allocation_receipt WHERE kind = 'payment_observation'");

        if ($providerCalls > $attempts) {
            throw new RuntimeException('Provider calls exceed persisted attempts');
        }
        if ($expectFactsMatchSucceeded && $providerFacts !== $providerSucceeded) {
            throw new RuntimeException("Provider fact count ({$providerFacts}) diverges from succeeded provider effects ({$providerSucceeded})");
        }

        $payment = null;
        $paymentRow = $db->selectOne(
            'SELECT id, captured_minor, refunded_minor, allocated_minor FROM billium_payment WHERE operation_ref = ?',
            [$rootOperationRef],
        );
        if (is_object($paymentRow)) {
            $payment = [
                'captured' => (int) $paymentRow->captured_minor,
                'refunded' => (int) $paymentRow->refunded_minor,
                'allocated' => (int) $paymentRow->allocated_minor,
            ];
            if ($payment['captured'] > $capacity['captured']
                || $payment['refunded'] > $payment['captured']
                || $payment['allocated'] < 0
                || $payment['allocated'] > $payment['captured']) {
                throw new RuntimeException('Ledger payment conservation violated');
            }
            $sumAllocations = (int) $db->scalar(
                'SELECT COALESCE(SUM(amount_minor), 0) FROM billium_allocation WHERE payment_id = ?',
                [(int) $paymentRow->id],
            );
            if ($sumAllocations !== $payment['allocated']) {
                throw new RuntimeException('Sum of billium_allocation rows diverges from billium_payment.allocated_minor');
            }
        }

        $obligation = null;
        if ($obligationRef !== null) {
            $obRow = $db->selectOne(
                'SELECT amount_due_minor, adjusted_minor, allocated_minor, credited_minor, open_minor FROM billium_obligation WHERE obligation_ref = ?',
                [$obligationRef],
            );
            if (! is_object($obRow)) {
                throw new RuntimeException('Missing obligation row for '.$obligationRef);
            }
            $expectedOpen = (int) $obRow->amount_due_minor + (int) $obRow->adjusted_minor - (int) $obRow->allocated_minor - (int) $obRow->credited_minor;
            $open = (int) $obRow->open_minor;
            if ($open < 0 || $open !== $expectedOpen) {
                throw new RuntimeException('Ledger obligation conservation violated');
            }
            $obligation = [
                'amountDue' => (int) $obRow->amount_due_minor,
                'allocated' => (int) $obRow->allocated_minor,
                'open' => $open,
            ];
        }

        return [
            'providerCalls' => $providerCalls,
            'providerLookups' => $providerLookups,
            'providerRecords' => $providerRecords,
            'providerSucceeded' => $providerSucceeded,
            'attempts' => $attempts,
            'providerFacts' => $providerFacts,
            'oracle' => $oracleTotals,
            'capacity' => $capacity,
            'payment' => $payment,
            'obligation' => $obligation,
            'allocationReceipts' => $allocationReceipts,
        ];
    }
}

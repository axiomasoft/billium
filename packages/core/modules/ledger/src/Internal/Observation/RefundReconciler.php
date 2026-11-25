<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Observation;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Ledger\Internal\Commands\AllocateLockPlan;
use Billium\Ledger\Internal\Receipts\AllocationReceiptWriter;
use Billium\Ledger\Internal\Repositories\LedgerRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Internal;

/**
 * D48 reconcile job of the D41 refund basis: after the configured consistency window the ordinary charge basis
 * (payment.refunded_minor, assigned only from the charge refundedTotal) is compared with the succeeded refund details
 * linked to that charge. The window is a heuristic, not proof that the source is complete; the comparison therefore
 * stores its basis digest (charge revision AND the ordered contributing refund revisions/amounts): an identical basis
 * dedupes, a changed mismatch under the same charge revision is a new visible row, later agreement appends resolution.
 * One root Tx per comparison under the Funds lock of that payment; the ledger and any financial hold never change here.
 */
#[Internal]
final readonly class RefundReconciler
{
    public const string BASIS = 'refund-basis/1';

    public function __construct(
        private ParkedDrainer $roots,
        private CallerContext $callers,
        private OperationRunner $runner,
        private DatabaseManager $databases,
        private LedgerRepository $routines,
        private AllocationReceiptWriter $writer,
        private BilliumOptions $options,
    ) {}

    /** @param  int|null  $afterSeconds  consistency window; null uses billium.ledger.refund_reconcile_after_seconds */
    public function reconcile(?int $afterSeconds = null, int $limit = 100): RefundReconcileReport
    {
        $window = $afterSeconds ?? $this->options->ledger->refundReconcileAfterSeconds;
        if ($window < 0 || $limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('The consistency window is nonnegative and the limit between 1 and 1000');
        }
        $caller = $this->callers->current();
        $candidates = $this->roots->connection()->select(<<<'SQL'
            SELECT p.id FROM public.billium_payment p
            LEFT JOIN public.billium_refund_reconcile_progress progress ON progress.payment_id = p.id
            WHERE p.seller_ref = ? AND p.environment = ? AND p.kind = 'charge' AND (
                p.refunded_minor > 0
                OR EXISTS (SELECT 1 FROM public.billium_observed_payment r WHERE r.installation_ref = p.installation_ref AND r.seller_ref = p.seller_ref AND r.environment = p.environment
                    AND r.currency = p.currency AND r.billing_account_ref = p.billing_account_ref
                    AND r.kind = 'refund' AND r.original_ref = p.payment_ref AND r.source_authority = p.source_authority)
                OR EXISTS (SELECT 1 FROM public.billium_ledger_discrepancy d JOIN public.billium_observed_payment c ON c.id = d.observed_payment_id
                    WHERE d.seller_ref = p.seller_ref AND d.environment = p.environment AND d.reason = 'refund_basis_mismatch'
                      AND c.operation_ref = p.operation_ref AND c.source_authority = p.source_authority
                      AND NOT EXISTS (SELECT 1 FROM public.billium_discrepancy_event e WHERE e.discrepancy_id = d.id AND e.kind = 'resolved')))
              AND greatest(
                  (SELECT max(v.created_at) FROM public.billium_payment_revision v WHERE v.seller_ref = p.seller_ref AND v.environment = p.environment AND v.payment_id = p.id),
                  (SELECT max(v.received_at) FROM public.billium_observed_payment r JOIN public.billium_observed_payment_revision v
                       ON v.seller_ref = r.seller_ref AND v.environment = r.environment AND v.observed_payment_id = r.id
                   WHERE r.installation_ref = p.installation_ref AND r.seller_ref = p.seller_ref AND r.environment = p.environment
                     AND r.currency = p.currency AND r.billing_account_ref = p.billing_account_ref AND r.kind = 'refund' AND r.original_ref = p.payment_ref
                     AND r.source_authority = p.source_authority),
                  p.created_at) <= now() - make_interval(secs => ?)
            ORDER BY progress.compared_at NULLS FIRST, p.id LIMIT ?
            SQL, [$caller->sellerRef, $caller->environment, $window, $limit]);
        $report = new RefundReconcileReport;
        foreach ($candidates as $candidate) {
            $payment = is_object($candidate) && is_int($candidate->id ?? null) ? $candidate->id : throw new LogicException('Expected a payment id');
            $body = $this->roots->root(fn (TransactionContext $context): array => $this->compare($context, $payment), 'ledger.refund_reconcile', commitKind: null);
            $report = $report->with(($body['recorded'] ?? false) === true, is_array($body['resolved'] ?? null) ? count($body['resolved']) : 0);
        }

        return $report;
    }

    /**
     * The comparison of one charge in its own root under the payment lock (Funds, rank 90).
     *
     * @return array<string, mixed>
     */
    public function compare(TransactionContext $context, int $paymentId): array
    {
        $this->runner->assertCurrent($context, 'ledger');
        $this->routines->assertFinancialSession($context, [ModuleOperationClass::ObservedFactRecording, ModuleOperationClass::UnknownResolution]);
        $caller = $context->caller();
        $db = $this->databases->connection($context->connectionName());
        new AllocateLockPlan(payments: [$paymentId])->lock($context);
        $payment = $db->table('billium_payment')->where('id', $paymentId)->where('seller_ref', $caller->sellerRef)->where('environment', $caller->environment)
            ->where('installation_ref', $caller->installationRef)
            ->first(['id', 'payment_ref', 'source_authority', 'applied_revision', 'refunded_minor', 'billing_account_ref', 'currency'])
            ?? throw new LogicException('Unknown charge for refund reconcile');
        $paymentRef = self::text($payment->payment_ref);
        $contributing = [];
        $detail = 0;
        $overflow = false;
        $latest = null;
        // Only a validated detail receipt admits a revision to the basis. Latest observed facts can be quarantined
        // or held; neither may replace the last consistent refund detail or resolve an existing mismatch.
        // Restrict revisions to this charge's refunds up front: DISTINCT ON keeps the join from pushing the charge
        // filter down, so otherwise every revision and refund detail of the seller is joined on each comparison.
        $refunds = $db->table('billium_observed_payment')->select('id')
            ->where('installation_ref', $caller->installationRef)->where('seller_ref', $caller->sellerRef)->where('environment', $caller->environment)
            ->where('kind', 'refund')->where('original_ref', $paymentRef)->where('source_authority', $payment->source_authority)
            ->where('currency', $payment->currency)->where('billing_account_ref', $payment->billing_account_ref);
        $validated = $db->table('billium_observed_payment_revision as rev')
            ->join('billium_allocation_receipt as rc', static fn (JoinClause $join): JoinClause => $join->on('rc.semantic_digest', '=', 'rev.material_digest')
                ->on('rc.seller_ref', '=', 'rev.seller_ref')->on('rc.environment', '=', 'rev.environment'))
            ->where('rev.seller_ref', $caller->sellerRef)->where('rev.environment', $caller->environment)->whereIn('rev.observed_payment_id', $refunds)
            ->where('rc.installation_ref', $caller->installationRef)->where('rc.seller_ref', $caller->sellerRef)->where('rc.environment', $caller->environment)
            ->where('rc.kind', 'refund_detail')->whereRaw("rc.body::jsonb ->> 'mode' = ?", ['refund_detail'])
            ->whereRaw("rc.body::jsonb ->> 'original_payment_ref' = ?", [$paymentRef])
            ->where('rc.billing_account_ref', $payment->billing_account_ref)->where('rc.currency', $payment->currency)
            ->selectRaw('DISTINCT ON (rev.observed_payment_id) rev.observed_payment_id, rev.revision, rev.captured_minor, rev.state')
            ->orderBy('rev.observed_payment_id')->orderByDesc('rev.revision');
        foreach ($db->table('billium_observed_payment as o')->joinSub($validated, 'r', 'r.observed_payment_id', '=', 'o.id')
            ->where('o.installation_ref', $caller->installationRef)->where('o.seller_ref', $caller->sellerRef)->where('o.environment', $caller->environment)
            ->where('o.kind', 'refund')->where('o.original_ref', $paymentRef)->where('o.source_authority', $payment->source_authority)
            ->where('o.currency', $payment->currency)->where('o.billing_account_ref', $payment->billing_account_ref)->where('r.state', 'succeeded')
            ->orderBy('o.operation_ref')->orderBy('o.id')->get(['o.operation_ref', 'r.revision', 'r.captured_minor']) as $refund) {
            $contributing[] = ['operationRef' => self::text($refund->operation_ref), 'revision' => self::int($refund->revision), 'captured' => (string) self::int($refund->captured_minor)];
            $captured = self::int($refund->captured_minor);
            if ($detail > PHP_INT_MAX - $captured) {
                $overflow = true;
                $detail = PHP_INT_MAX;
            } else {
                $detail += $captured;
            }
            $latest = max($latest ?? 0, self::int($refund->revision));
        }
        $chargeRevision = self::int($payment->applied_revision);
        $chargeRefunded = self::int($payment->refunded_minor);
        $basis = (new Codec)->digest((object) ['normalization' => self::BASIS, 'paymentRef' => $paymentRef, 'chargeRevision' => $chargeRevision,
            'chargeRefunded' => (string) $chargeRefunded, 'refunds' => $contributing], 'basisDigest');
        if ($overflow && $detail === $chargeRefunded) {
            // A saturated sum must still mismatch a charge whose refund total is itself PHP_INT_MAX.
            $detail = PHP_INT_MAX - 1;
        }
        $body = $this->routines->call($db, 'reconcile_refund_basis', [
            $caller->installationRef, $caller->sellerRef, $caller->environment, $paymentId, $chargeRevision, $basis, $latest, $chargeRefunded, $detail, 'reconcile:'.$basis,
        ], 'ledger.refund_reconcile')->body();
        if (($body['recorded'] ?? false) === true) {
            $this->writer->event($context, 'discrepancy.recorded.v1', $paymentRef, $chargeRevision, [
                'fingerprint' => $body['fingerprint'] ?? null,
                'reason' => 'refund_basis_mismatch',
                'payment_ref' => $paymentRef,
                'billing_account_ref' => self::text($payment->billing_account_ref),
                'currency' => self::text($payment->currency),
                'charge_revision' => $chargeRevision,
                'expected_minor' => (string) $chargeRefunded,
                'actual_minor' => (string) $detail,
                'comparison_basis_digest' => $basis,
                'financial_hold' => false,
            ]);
        }

        return $body;
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : throw new LogicException('Expected a PostgreSQL bigint');
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new LogicException('Expected a text value');
    }
}

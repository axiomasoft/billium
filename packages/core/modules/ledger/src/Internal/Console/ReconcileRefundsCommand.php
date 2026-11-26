<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Console;

use Billium\Ledger\Internal\Observation\RefundReconciler;
use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use Throwable;

/** D48 reconcile job: compares the D41 charge refund basis with refund details after the consistency window. */
#[Internal]
final class ReconcileRefundsCommand extends Command
{
    protected $signature = 'billium:ledger:reconcile-refunds {--limit=100 : Charges to compare} {--after= : Consistency window in seconds (default: config)}';

    protected $description = 'Record refund basis discrepancies after the consistency window; the ledger is not changed (D41/D48)';

    public function handle(RefundReconciler $reconciler): int
    {
        $limit = $this->option('limit');
        $after = $this->option('after');
        if (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 1000
            || ($after !== null && ! ctype_digit($after))) {
            $this->error('limit must be between 1 and 1000 and after a nonnegative number of seconds');

            return self::FAILURE;
        }
        try {
            $report = $reconciler->reconcile($after === null ? null : (int) $after, (int) $limit);
            $this->line("compared={$report->compared} recorded={$report->recorded} resolved={$report->resolved}");

            return self::SUCCESS;
        } catch (Throwable $error) {
            report($error);
            $this->error('Reconcile unavailable ('.$error::class.')');

            return self::FAILURE;
        }
    }
}

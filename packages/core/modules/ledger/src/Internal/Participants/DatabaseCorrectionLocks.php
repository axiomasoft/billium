<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Participants;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Ledger\Contracts\CorrectionLocks;
use Billium\Ledger\Data\Views\LedgerView;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseCorrectionLocks implements CorrectionLocks
{
    public function __construct(private OperationRunner $runner, private DatabaseManager $databases) {}

    public function acquireParent(TransactionContext $context, string $obligationRef, string $accountRef): void
    {
        $this->runner->assertCurrent(context: $context, moduleId: 'ledger');
        $caller = $context->caller();
        $db = $this->databases->connection($context->connectionName());
        $read = static fn () => $db->table('billium_obligation')->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)->where('installation_ref', $caller->installationRef)
            ->where('billing_account_ref', $accountRef)->where('obligation_ref', $obligationRef)->first(['id', 'billed_invoice_id']);
        $before = $read() ?? throw new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown correction obligation');
        $context->lock(new LockRequest(rank: LockRank::Obligation, table: 'billium_obligation', keys: [LedgerView::integer($before->id)], guardedRoutine: 'billium_lock_obligation'));
        $after = $read();
        if ($before->id !== $after->id || $before->billed_invoice_id !== $after->billed_invoice_id) {
            throw new LockPlanChanged('Correction obligation invoice changed');
        }
        if ($after->billed_invoice_id !== null) {
            $context->lock(new LockRequest(rank: LockRank::Invoice, table: 'billium_invoice',
                keys: [LedgerView::integer($after->billed_invoice_id)], guardedRoutine: 'billium_lock_invoice'));
        }
    }
}

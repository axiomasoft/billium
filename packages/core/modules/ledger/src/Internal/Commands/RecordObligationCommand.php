<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Core\Contracts\TransactionContext;
use Billium\Ledger\Data\LedgerReceipt;
use Billium\Ledger\Data\RecordObligation;
use Billium\Ledger\Internal\Repositories\LedgerRepository;
use Billium\Platform\Contracts\Outbox;
use Billium\Platform\Data\OutboxEvent;
use DateTimeImmutable;
use Illuminate\Support\Str;
use LogicException;

final readonly class RecordObligationCommand
{
    public function __construct(private LedgerRepository $repository, private Outbox $outbox) {}

    public function execute(TransactionContext $context, RecordObligation $command): LedgerReceipt
    {
        $receipt = $this->repository->execute($context, $command);
        if (! $receipt->replayed) {
            $revision = $receipt->body()['revision'] ?? null;
            if (! is_int($revision)) {
                throw new LogicException('Missing obligation revision');
            }
            $this->outbox->record($context, new OutboxEvent((string) Str::ulid(), 'obligation.created.v1', 'billium.event.v1',
                $command->obligationRef, $revision, new DateTimeImmutable, [
                    'obligation_ref' => $command->obligationRef,
                    'billing_account_ref' => $command->scope->billingAccountRef,
                    'amount_minor' => (string) $command->money->minor,
                    'currency' => $command->money->currency->code,
                ]));
        }

        return $receipt;
    }
}

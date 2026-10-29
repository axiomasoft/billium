<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Core\Contracts\TransactionContext;
use Billium\Ledger\Data\AmendObligationDuePolicy;
use Billium\Ledger\Data\LedgerReceipt;
use Billium\Ledger\Internal\Repositories\LedgerRepository;
use Billium\Platform\Contracts\Outbox;
use Billium\Platform\Data\OutboxEvent;
use DateTimeImmutable;
use Illuminate\Support\Str;
use LogicException;

final readonly class AmendObligationDuePolicyCommand
{
    public function __construct(private LedgerRepository $repository, private Outbox $outbox) {}

    public function execute(TransactionContext $context, AmendObligationDuePolicy $command): LedgerReceipt
    {
        $receipt = $this->repository->execute($context, $command);
        if (! $receipt->replayed) {
            $body = $receipt->body();
            $revision = $body['revision'] ?? null;
            if (! is_int($revision)) {
                throw new LogicException('Missing obligation revision');
            }
            $this->outbox->record($context, new OutboxEvent((string) Str::ulid(), 'obligation.adjusted.v1', 'billium.event.v1',
                $command->obligationRef, $revision, new DateTimeImmutable, $body));
        }

        return $receipt;
    }
}

<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Adapters;

use Billium\Core\Contracts\TransactionContext;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Internal\Ledger\ObligationCommandMapper;
use LogicException;

final readonly class LedgerAdapter
{
    public function __construct(private ObligationCommandMapper $mapper, private ?LedgerParticipants $ledger = null) {}

    public function record(TransactionContext $context, PreparedRating $prepared): string
    {
        $command = $this->mapper->map($prepared);
        $receipt = ($this->ledger ?? throw new LogicException('Ledger capability absent'))->recordObligation(context: $context, command: $command);
        $ref = $receipt->body()['obligation_ref'] ?? null;
        if ($ref !== $command->obligationRef) {
            throw new LogicException('Ledger returned an unrelated obligation');
        }

        return $ref;
    }
}

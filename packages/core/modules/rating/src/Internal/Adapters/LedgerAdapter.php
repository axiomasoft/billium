<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Adapters;

use Billium\Contracts\Data\Money;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Exceptions\Modules\ModuleAdmissionRefused;
use Billium\Ledger\Contracts\CorrectionCreditParticipants;
use Billium\Ledger\Contracts\CorrectionLocks;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Data\Invoices\IssueCreditNote;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Rating\Data\Rating\PreparedRating;
use LogicException;
use Packman\Attributes\Internal;

/** Rating's only route to the optional ledger capability; every call refuses with a ledger module error when it is not installed. */
#[Internal]
final readonly class LedgerAdapter
{
    public function __construct(private ObligationCommandMapper $mapper, private ?LedgerParticipants $ledger = null,
        private ?CorrectionLocks $locks = null, private ?CorrectionCreditParticipants $credits = null,
        private ?LedgerQueries $queries = null) {}

    public function available(): bool
    {
        return $this->ledger !== null && $this->queries !== null;
    }

    public function record(TransactionContext $context, PreparedRating $prepared): string
    {
        $command = $this->mapper->map($prepared);
        $receipt = $this->participants()->recordObligation(context: $context, command: $command);
        $ref = $receipt->body()['obligation_ref'] ?? null;
        if ($ref !== $command->obligationRef) {
            throw new LogicException('Ledger returned an unrelated obligation');
        }

        return $ref;
    }

    /** Books a positive correction delta, serialized behind the parent obligation when the original was booked. */
    public function recordCorrection(TransactionContext $context, RecordObligation $command, ?string $parentObligationRef): void
    {
        if ($parentObligationRef !== null) {
            ($this->locks ?? self::absent())->acquireParent(context: $context, obligationRef: $parentObligationRef,
                accountRef: $command->scope->billingAccountRef);
        }
        $this->participants()->recordObligation(context: $context, command: $command);
    }

    /** @param non-empty-list<IssueCreditNote> $commands one credit note per invoice; several go through the grouped participant */
    public function issueCreditNotes(TransactionContext $context, array $commands): void
    {
        if (count($commands) === 1) {
            $this->participants()->issueCreditNote(context: $context, command: $commands[0]);

            return;
        }
        ($this->credits ?? self::absent())->issue(context: $context, commands: $commands);
    }

    public function obligation(string $obligationRef): LedgerObligation
    {
        $view = ($this->queries ?? self::absent())->obligation($obligationRef);
        $invoice = $view['billedInvoiceRef'] ?? null;
        $status = $view['billedInvoiceStatus'] ?? null;

        return new LedgerObligation(
            openMinor: Money::fromWire($view['open'] ?? null)->minor,
            originalDueMinor: Money::fromWire($view['originalDue'] ?? null)->minor,
            adjustedMinor: Money::fromWire($view['adjusted'] ?? null)->minor,
            creditedMinor: Money::fromWire($view['credited'] ?? null)->minor,
            billedInvoiceRef: is_string($invoice) ? $invoice : null,
            billedInvoiceStatus: is_string($status) ? $status : null,
        );
    }

    private function participants(): LedgerParticipants
    {
        return $this->ledger ?? self::absent();
    }

    private static function absent(): never
    {
        throw new ModuleAdmissionRefused(moduleId: 'ledger', reason: 'module_not_installed');
    }
}

<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Observation;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\CallerContext;
use Billium\Ledger\Contracts\ObservationApplier;
use Billium\Ledger\Data\AllocationReceipt;
use Billium\Platform\Contracts\CustodyHandler;
use Billium\Platform\Data\Inbox\CustodyDisposition;

/**
 * applyPaymentObservation facade (ObservationApplier). Decode and authentication refuse before any custody; a RawCustody
 * root then claims the delivery (it survives a sealed financial session); the economic apply of the next sequence and
 * every follow-up sequence or unblocked parked revision runs in its own financial root (ObservationHandler). The answer
 * reflects the durable disposition of THIS delivery: its AllocationReceipt, or — after commit — parked,
 * recovery-required or conflict (D31). Receipts are read on the configured Billium connection.
 */
final readonly class ObservationIntake implements ObservationApplier
{
    public function __construct(
        private ParkedDrainer $roots,
        private CustodyHandler $handler,
        private CallerContext $callers,
    ) {}

    public function apply(string $json): AllocationReceipt
    {
        $envelope = (new Codec)->decode($json);
        if (! $envelope instanceof PaymentObservation) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'expected payment.observation.v1');
        }
        $raw = $envelope->raw;
        // 1. Raw custody in its own RawCustody root: it commits even while the financial session is sealed (D60).
        $result = $this->roots->custody($raw, $this->handler);
        if ($result->disposition === CustodyDisposition::Conflict) {
            throw new ProblemException(ProblemCode::ObservationConflict, 'the event identity carries another content digest');
        }
        // 2. Economic application in separate financial roots, one event each. A refused financial admission (sealed or
        //    recovery session) or a failed apply surfaces here; the committed custody stays for a later admitted drain.
        $this->roots->follow($result, $this->handler);
        // Only a delivery already applied before this follow-up is an economic replay.
        $replayed = $result->disposition === CustodyDisposition::Duplicate && $result->status === 'applied';
        if (in_array($result->status, ['ready', 'parked_gap', 'parked_dependency'], true)) {
            // The follow-up may have applied it; a duplicate custody call never invokes the handler.
            $result = $this->roots->custody($raw, $this->handler);
        }

        return match ($result->status) {
            'applied' => $this->receipt($result->resultRef ?? '', $replayed),
            'quarantined' => throw new ProblemException(ProblemCode::ObservationConflict, 'the observation is quarantined'),
            'held_recovery' => throw new ProblemException(ProblemCode::RecoveryRequired, 'the source partition requires recovery'),
            default => throw new ProblemException(ProblemCode::ObservationParked, 'the observation is in custody and waits ('.($result->status ?? 'unknown').')'),
        };
    }

    private function receipt(string $ref, bool $replayed): AllocationReceipt
    {
        $caller = $this->callers->current();
        $row = $this->roots->connection()->table('billium_allocation_receipt')->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)->where('receipt_ref', $ref)->first(['kind', 'body']);
        if ($row === null || ! is_string($row->kind) || ! is_string($row->body)) {
            throw new ProblemException(ProblemCode::ObservationParked, 'the observation has no business receipt yet');
        }
        $body = json_decode($row->body, true, 64, JSON_THROW_ON_ERROR);
        $typed = [];
        foreach (is_array($body) ? $body : [] as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return new AllocationReceipt($ref, $row->kind, $typed, $replayed);
    }
}

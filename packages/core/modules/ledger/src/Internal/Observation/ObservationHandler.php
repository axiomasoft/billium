<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Observation;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\TransactionContext;
use Billium\Platform\Contracts\BootstrapItemHandler;
use Billium\Platform\Contracts\CustodyHandler;
use Billium\Platform\Data\Inbox\BootstrapItem;
use Billium\Platform\Data\Inbox\HandlerOutcome;
use Billium\Platform\Data\Inbox\InboxRow;
use stdClass;

/**
 * The ledger's economic handler of payment.observation.v1 (INV-08): platform custody calls it for exactly one accounted
 * delivery (or one verified bootstrap snapshot item) inside that event's root Tx, after the partition lock. The stored
 * envelope is decoded again (contentDigest included) and applied by PaymentProjection with the same economic dedupe for
 * live and bootstrap input; a snapshot item must name the operation, revision and material digest of its payload.
 */
final readonly class ObservationHandler implements BootstrapItemHandler, CustodyHandler
{
    public function __construct(private PaymentProjection $projection) {}

    public function handle(TransactionContext $context, InboxRow $row, stdClass $payload): HandlerOutcome
    {
        $observation = self::decode($payload);
        if ($observation === null || $observation->operationRef !== $row->operationRef || $observation->operationRevision !== $row->operationRevision) {
            return HandlerOutcome::conflict(ProblemCode::ObservationConflict->value);
        }

        return $this->projection->apply($context, $observation, $row->id);
    }

    public function apply(TransactionContext $context, BootstrapItem $item): HandlerOutcome
    {
        $observation = self::decode($item->payload);
        if ($observation === null || $observation->operationRef !== $item->operationRef || $observation->operationRevision !== $item->revision
            || ! hash_equals($item->materialDigest, EconomicDigest::of($observation))) {
            return HandlerOutcome::conflict(ProblemCode::ObservationConflict->value); // a recorded discrepancy disposition of the item
        }

        return $this->projection->apply($context, $observation);
    }

    public static function decode(stdClass $payload): ?PaymentObservation
    {
        try {
            $codec = new Codec;
            $envelope = $codec->decode($codec->canonicalize($payload));
        } catch (ProblemException) {
            return null;
        }

        return $envelope instanceof PaymentObservation ? $envelope : null;
    }
}

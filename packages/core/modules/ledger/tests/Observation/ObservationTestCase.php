<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Observation;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Caller;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Contracts\ObservationApplier;
use Billium\Ledger\Data\AllocationReceipt;
use Billium\Ledger\Data\DeclareSettlementIntent;
use Billium\Ledger\Data\RecordObligation;
use Billium\Ledger\Internal\Commands\BootstrapPartitionCommand;
use Billium\Ledger\Internal\Observation\EconomicDigest;
use Billium\Ledger\Internal\Observation\ObservationHandler;
use Billium\Ledger\Internal\Observation\ParkedDrainer;
use Billium\Ledger\Internal\Observation\RefundReconciler;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use Billium\Platform\Data\Inbox\BootstrapItem;
use Billium\Platform\Data\Inbox\BootstrapPartition;
use stdClass;

/**
 * Committed-data base of the observation proofs (D50): a caller granted the three source authorities, obligations and
 * settlement intents through the public Ledger root, observations through the public ObservationApplier.
 */
abstract class ObservationTestCase extends LedgerTestCase
{
    public const array AUTHORITIES = ['embedded-commerz', 'remote-billium', 'standalone-billium'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', self::AUTHORITIES));
    }

    protected function applier(): ObservationApplier
    {
        return $this->application()->make(ObservationApplier::class);
    }

    protected function observe(stdClass $envelope): AllocationReceipt
    {
        return $this->applier()->apply(ObservationFactory::json($envelope));
    }

    /** The receipt, or the problem code the applier threw (after commit for parked/recovery/conflict). */
    protected function attempt(stdClass $envelope): AllocationReceipt|string
    {
        try {
            return $this->observe($envelope);
        } catch (ProblemException $problem) {
            return $problem->problem->value;
        }
    }

    protected function drainer(): ParkedDrainer
    {
        return $this->application()->make(ParkedDrainer::class);
    }

    protected function reconciler(): RefundReconciler
    {
        return $this->application()->make(RefundReconciler::class);
    }

    protected function bootstrapper(): BootstrapPartitionCommand
    {
        return $this->application()->make(BootstrapPartitionCommand::class);
    }

    protected function debt(string $ref, int $amount, string $account = 'account-a'): void
    {
        $this->ledger()->recordObligation(new RecordObligation('key-'.$ref, $this->account($account), $ref, 'basis-'.$ref, 'customer-a', 'component-'.$ref,
            new Money($amount, Currency::of('RUB')), self::digest()))->orThrow();
    }

    /** @param list<string> $obligations */
    protected function intent(string $ref, array $obligations, string $account = 'account-a'): void
    {
        $this->ledger()->declareSettlementIntent(new DeclareSettlementIntent('key-'.$ref, $this->account($account), $ref, 'RUB', $obligations))->orThrow();
    }

    /** @return array<string, mixed> */
    protected function paymentRow(string $ref = 'op-1'): array
    {
        return $this->row('SELECT * FROM billium_payment WHERE payment_ref = ?', [$ref]);
    }

    /** @return array<string, mixed> */
    protected function observedRow(string $operation = 'op-1'): array
    {
        return $this->row('SELECT * FROM billium_observed_payment WHERE operation_ref = ?', [$operation]);
    }

    protected function allocated(string $obligation): int
    {
        return self::integer($this->value('SELECT allocated_minor FROM billium_obligation WHERE obligation_ref = ?', [$obligation]));
    }

    protected function journalSum(?string $payment = null): int
    {
        return self::integer($this->value(
            'SELECT coalesce(sum(a.amount_minor), 0) FROM billium_allocation a JOIN billium_payment p ON p.id = a.payment_id WHERE (?::text IS NULL OR p.payment_ref = ?)',
            [$payment, $payment],
        ));
    }

    protected function inboxStatus(string $eventId): string
    {
        return self::text($this->value('SELECT status FROM billium_inbox_message WHERE external_event_id = ?', [$eventId]));
    }

    protected static function eventId(stdClass $envelope): string
    {
        return self::text($envelope->eventId ?? null);
    }

    /**
     * A complete verified source snapshot of one partition at `$cursor` (one item per envelope: operation, revision,
     * ledger material digest, the envelope as payload).
     *
     * @param  list<stdClass>  $envelopes
     */
    protected function snapshot(string $ref, string $lineage, int $cursor, array $envelopes, string $authority = 'embedded-commerz', string $partition = 'part-1'): BootstrapPartition
    {
        $items = [];
        foreach ($envelopes as $envelope) {
            $observation = ObservationHandler::decode($envelope) ?? self::fail('snapshot item is not a valid observation');
            $items[] = new BootstrapItem($observation->operationRef, $observation->operationRevision, EconomicDigest::of($observation), $envelope);
        }

        return new BootstrapPartition($ref, ParkedDrainer::CHANNEL, $authority, $lineage, 'seller-a', 'test', $partition, $cursor,
            BootstrapPartition::digestOf($cursor, $items, ['channel' => ParkedDrainer::CHANNEL, 'sourceAuthority' => $authority,
                'sourceLineage' => $lineage, 'sellerRef' => 'seller-a', 'environment' => 'test', 'partitionRef' => $partition]), 'lease-'.$ref, count($items), $items);
    }

    /** @return array<string, mixed> */
    protected function partition(string $partition = 'part-1'): array
    {
        return $this->row("SELECT * FROM billium_feed_partition WHERE state <> 'retired' AND partition_ref = ? ORDER BY id DESC LIMIT 1", [$partition]);
    }
}

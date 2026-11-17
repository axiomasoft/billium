<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Ledger\Internal\Observation\ObservationHandler;
use Billium\Ledger\Internal\Observation\ParkedDrainer;
use Billium\Platform\Contracts\Feed\FeedPartitions;
use Billium\Platform\Data\Inbox\BootstrapPartition;
use Billium\Platform\Data\Inbox\BootstrapStatus;
use Packman\Attributes\Internal;

/**
 * ledger.feed.bootstrap (PF-13, PF-14, ACC-RST-07): staged bootstrap of one observation partition over
 * FeedPartitions with the ledger ObservationHandler. The complete snapshot is verified and staged first (the live
 * partition holds its tail); every item then gets a durable economic, discrepancy or pending-allocation disposition in
 * its own root with the same economic dedupe as live deliveries, so an already applied revision is never applied twice;
 * only then one short root adopts the cursor, and the held tail (sequences > cursor) drains one root per sequence.
 * Every root, the tail drain included, runs in the requested financial mode, so a recovery-session bootstrap keeps
 * Recovery admission throughout. A crash or a repeat resumes: the same bootstrapRef continues the staged session, an
 * adopted session skips straight to adoption (idempotent) and the tail drain.
 */
#[Internal]
final readonly class BootstrapPartitionCommand
{
    public const string KIND = 'ledger.feed.bootstrap';

    public function __construct(private FeedPartitions $feed, private ParkedDrainer $roots, private ObservationHandler $handler) {}

    public function execute(BootstrapPartition $snapshot, FinancialMode $mode = FinancialMode::Active): BootstrapStatus
    {
        if ($snapshot->channel !== ParkedDrainer::CHANNEL) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'ledger bootstraps payment.observation partitions only');
        }
        $session = $this->roots->root(fn (TransactionContext $context): int => $this->feed->stageBootstrap($context, $snapshot), self::KIND, $mode, sourceAuthority: $snapshot->sourceAuthority);
        if ($this->status($session, $mode, $snapshot->sourceAuthority)->status !== 'adopted') {
            do {
                $remaining = $this->roots->root(fn (TransactionContext $context): int => $this->feed->applyBootstrapItem($context, $session, $this->handler), self::KIND, $mode, sourceAuthority: $snapshot->sourceAuthority);
            } while ($remaining > 0);
        }
        $partition = $this->roots->root(fn (TransactionContext $context): int => $this->feed->adoptBootstrap($context, $session), self::KIND, $mode, sourceAuthority: $snapshot->sourceAuthority);
        $this->roots->drainPartition($partition, $mode);

        return $this->status($session, $mode, $snapshot->sourceAuthority);
    }

    private function status(int $session, FinancialMode $mode, string $source): BootstrapStatus
    {
        return $this->roots->root(fn (TransactionContext $context): BootstrapStatus => $this->feed->bootstrapStatus($context, $session), self::KIND, $mode, null, $source);
    }
}

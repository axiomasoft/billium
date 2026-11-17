<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Ledger\Internal\Observation\ObservationHandler;
use Billium\Ledger\Internal\Observation\ObservationOwnership;
use Billium\Ledger\Internal\Observation\ParkedDrainer;
use Billium\Platform\Contracts\Feed\FeedPartitions;
use Billium\Platform\Contracts\Inbox\AuthenticatedObservationBootstrap;
use Billium\Platform\Data\Inbox\BootstrapPartition;
use Billium\Platform\Data\Inbox\BootstrapStatus;
use Billium\Platform\Data\Inbox\VerifiedObservationBootstrap;
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

    public function __construct(private FeedPartitions $feed, private ParkedDrainer $roots, private ObservationHandler $handler, private ?AuthenticatedObservationBootstrap $origins = null) {}

    public function execute(BootstrapPartition $snapshot, FinancialMode $mode = FinancialMode::Active): BootstrapStatus
    {
        return $this->bootstrap($snapshot, $mode, null);
    }

    public function executeQualified(BootstrapPartition $snapshot, FinancialMode $mode = FinancialMode::Active): BootstrapStatus
    {
        if ($this->origins === null) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Qualified bootstrap authentication unavailable');
        }

        return $this->origins->run($snapshot, fn (VerifiedObservationBootstrap $grant): BootstrapStatus => $this->bootstrap($snapshot, $mode, $grant));
    }

    private function bootstrap(BootstrapPartition $snapshot, FinancialMode $mode, ?VerifiedObservationBootstrap $grant): BootstrapStatus
    {
        if ($snapshot->channel !== ParkedDrainer::CHANNEL) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'ledger bootstraps payment.observation partitions only');
        }
        $authenticatedItems = [];
        if ($grant !== null) {
            // Decode independent copies while the verified complete manifest is current.
            foreach ($snapshot->items as $item) {
                $o = ObservationHandler::decode($item->payload);
                if ($o !== null && ObservationOwnership::hasLegacyRevision($this->roots->connection(), $o)) {
                    $authenticatedItems[] = $o;
                }
            }
        }
        $session = $this->roots->root(fn (TransactionContext $context): int => $grant === null ? $this->feed->stageBootstrap($context, $snapshot) : $this->feed->stageQualifiedBootstrap($context, $snapshot, $grant), self::KIND, $mode, sourceAuthority: $snapshot->sourceAuthority, scope: $grant?->scope);
        if ($grant !== null) {
            foreach ($authenticatedItems as $o) {
                $this->roots->root(function (TransactionContext $ctx) use ($o, $grant): void {
                    ObservationOwnership::prove($this->roots->connection(), $ctx, $o, $grant->proofDigest);
                }, self::KIND, $mode, commitKind: null, scope: $grant->scope);
            }
        }
        if ($this->status($session, $mode, $snapshot->sourceAuthority, $grant?->scope)->status !== 'adopted') {
            do {
                $remaining = $this->roots->root(fn (TransactionContext $context): int => $this->feed->applyBootstrapItem($context, $session, $this->handler), self::KIND, $mode, sourceAuthority: $snapshot->sourceAuthority, scope: $grant?->scope);
            } while ($remaining > 0);
        }
        $partition = $this->roots->root(fn (TransactionContext $context): int => $this->feed->adoptBootstrap($context, $session), self::KIND, $mode, sourceAuthority: $snapshot->sourceAuthority, scope: $grant?->scope);
        $this->roots->drainPartition($partition, $mode);

        return $this->status($session, $mode, $snapshot->sourceAuthority, $grant?->scope);
    }

    private function status(int $session, FinancialMode $mode, string $source, ?FinancialScope $scope): BootstrapStatus
    {
        return $this->roots->root(fn (TransactionContext $context): BootstrapStatus => $this->feed->bootstrapStatus($context, $session), self::KIND, $mode, null, $source, $scope);
    }
}

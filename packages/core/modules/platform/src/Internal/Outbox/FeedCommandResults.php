<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Outbox;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelope;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\Caller;
use Billium\Platform\Contracts\Idempotency\FinancialIdempotencyStore;
use Billium\Platform\Contracts\Idempotency\IdempotencyReceiptQueries;
use Billium\Platform\Contracts\Idempotency\IdempotencyStore;
use Billium\Platform\Data\Idempotency\PinnedCommand;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Closure;
use Illuminate\Database\Connection;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class FeedCommandResults
{
    public function __construct(
        private Connection $db,
        private IdempotencyStore $store,
        private IdempotencyReceiptQueries $receipts,
    ) {}

    /** @param Closure(): Envelope $run */
    public function once(PinnedCommand $command, Closure $run): Envelope
    {
        $codec = new Codec;
        $key = $codec->canonicalize([get_object_vars($command->scope), $command->kind(), $command->key]);
        // Bootstrap spans independent pin, snapshot and publication transactions. Serialize only this command key.
        if ($this->db->scalar('SELECT pg_try_advisory_lock(hashtextextended(?, 8304))', [$key], false) !== true) {
            throw new ProblemException(ProblemCode::IdempotencyInFlight);
        }
        try {
            $stored = $this->receipts->replayCommand($command->scope, $command->kind(), $command->key, $command->inputDigest);
            if ($stored === null) {
                return $run();
            }

            return $codec->decode($codec->canonicalize($stored->orThrow()->body));
        } finally {
            $this->db->selectOne('SELECT pg_advisory_unlock(hashtextextended(?, 8304))', [$key], false);
        }
    }

    /** @param array<string, mixed> $result */
    public function retain(Caller $caller, PinnedCommand $command, array $result): void
    {
        if (! $this->store instanceof FinancialIdempotencyStore) {
            throw new LogicException('The feed command store must persist a financial claim.');
        }
        $claim = $this->store->claimScoped($caller, $command->scope, $command->kind(), $command->key, $command->inputDigest, $command->inputDigest);
        if ($claim->claimId === null) {
            throw new LogicException('Feed command already completed while holding its key lock');
        }
        $this->store->complete($claim->claimId, StoredOutcome::accepted(new PlatformReceipt('platform.feed_command', $result)));
    }
}

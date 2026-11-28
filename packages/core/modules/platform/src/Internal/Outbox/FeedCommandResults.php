<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Outbox;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelope;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Database\RlsSessionContext;
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

    /** @param Closure(string, string): Envelope $run */
    public function once(PinnedCommand $command, Closure $run, int $ttlSeconds = 60): Envelope
    {
        $codec = new Codec;
        $leaseKey = $codec->digest([get_object_vars($command->scope), $command->kind(), $command->key]);
        $leaseToken = bin2hex(random_bytes(24));
        $host = gethostname();
        $ownerRef = substr(($host !== false ? $host : 'php').':'.(string) getmypid(), 0, 200);

        $acquired = $this->db->transaction(function () use (
            $leaseKey,
            $command,
            $leaseToken,
            $ownerRef,
            $ttlSeconds,
        ): mixed {
            RlsSessionContext::applyForValues(
                $this->db,
                $command->scope->installationRef,
                $command->scope->sellerRef ?? '',
                $command->scope->environment ?? '',
            );

            return $this->db->selectOne(<<<'SQL'
                INSERT INTO public.billium_feed_command_lease (
                    lease_key, installation_ref, seller_ref, environment,
                    lease_token, lease_epoch, owner_ref, acquired_at, lease_until
                )
                VALUES (
                    ?, ?, ?, ?,
                    ?, 1, ?, clock_timestamp(), clock_timestamp() + (? * interval '1 second')
                )
                ON CONFLICT (lease_key) DO UPDATE
                    SET lease_token = EXCLUDED.lease_token,
                        lease_epoch = public.billium_feed_command_lease.lease_epoch + 1,
                        owner_ref   = EXCLUDED.owner_ref,
                        acquired_at = EXCLUDED.acquired_at,
                        lease_until = EXCLUDED.lease_until
                    WHERE public.billium_feed_command_lease.lease_token IS NULL
                       OR public.billium_feed_command_lease.lease_until <= clock_timestamp()
                RETURNING lease_epoch
                SQL, [
                $leaseKey,
                $command->scope->installationRef,
                $command->scope->sellerRef,
                $command->scope->environment,
                $leaseToken,
                $ownerRef,
                max(1, $ttlSeconds),
            ], false);
        });

        if ($acquired === null) {
            throw new ProblemException(ProblemCode::IdempotencyInFlight);
        }

        try {
            $stored = $this->receipts->replayCommand($command->scope, $command->kind(), $command->key, $command->inputDigest);
            if ($stored === null) {
                return $run($leaseKey, $leaseToken);
            }

            return $codec->decode($codec->canonicalize($stored->orThrow()->body));
        } finally {
            $this->releaseLease(
                $leaseKey,
                $leaseToken,
                $command->scope->installationRef,
                $command->scope->sellerRef,
                $command->scope->environment,
            );
        }
    }

    public function verifyLeaseActive(string $leaseKey, string $leaseToken): void
    {
        $row = $this->db->selectOne(<<<'SQL'
            SELECT lease_epoch
            FROM public.billium_feed_command_lease
            WHERE lease_key = ?
              AND lease_token = ?
              AND lease_until > clock_timestamp()
            FOR UPDATE
            SQL, [$leaseKey, $leaseToken], false);

        if ($row === null) {
            throw new ProblemException(ProblemCode::IdempotencyInFlight, 'feed command lease lost before publication');
        }
    }

    public function releaseLease(
        string $leaseKey,
        string $leaseToken,
        ?string $installationRef = null,
        ?string $sellerRef = null,
        ?string $environment = null,
    ): void {
        $exec = function () use ($leaseKey, $leaseToken): void {
            $this->db->update(<<<'SQL'
                UPDATE public.billium_feed_command_lease
                SET lease_token = NULL,
                    owner_ref   = NULL,
                    lease_until = clock_timestamp()
                WHERE lease_key = ?
                  AND lease_token = ?
                SQL, [$leaseKey, $leaseToken]);
        };

        if ($this->db->transactionLevel() === 0 && $installationRef !== null && $sellerRef !== null && $environment !== null) {
            $this->db->transaction(function () use ($exec, $installationRef, $sellerRef, $environment): void {
                RlsSessionContext::applyForValues($this->db, $installationRef, $sellerRef, $environment);
                $exec();
            });

            return;
        }

        $exec();
    }

    /** @param array<string, mixed> $result */
    public function retain(
        Caller $caller,
        PinnedCommand $command,
        array $result,
        ?string $leaseKey = null,
        ?string $leaseToken = null,
    ): void {
        if (! $this->store instanceof FinancialIdempotencyStore) {
            throw new LogicException('The feed command store must persist a financial claim.');
        }
        $claim = $this->store->claimScoped($caller, $command->scope, $command->kind(), $command->key, $command->inputDigest, $command->inputDigest);
        if ($claim->claimId === null) {
            throw new LogicException('Feed command already completed while holding its key lock');
        }
        $this->store->complete($claim->claimId, StoredOutcome::accepted(new PlatformReceipt('platform.feed_command', $result)));
        if ($leaseKey !== null && $leaseToken !== null) {
            $this->releaseLease($leaseKey, $leaseToken);
        }
    }
}

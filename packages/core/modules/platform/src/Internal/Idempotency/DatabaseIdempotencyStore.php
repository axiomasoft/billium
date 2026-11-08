<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Idempotency;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Caller;
use Billium\Core\Data\StoredOutcome;
use Billium\Platform\Contracts\IdempotencyStore;
use Billium\Platform\Data\IdempotencyClaim;
use Billium\Platform\Internal\Installation\Rows;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use LogicException;

/**
 * INSERT … ON CONFLICT DO NOTHING with a bounded wait (D51): a concurrent claim of the same scope blocks on the unique
 * index until the first root Tx commits or rolls back. No check-then-insert (INV-05, M-53).
 */
final readonly class DatabaseIdempotencyStore implements IdempotencyStore
{
    public function __construct(private Connection $connection, private int $lockTimeoutMs) {}

    public function claim(Caller $caller, string $commandKind, string $commandKey, string $digest): IdempotencyClaim
    {
        $this->assertRootTransaction();
        $this->connection->statement("SET LOCAL lock_timeout = '{$this->lockTimeoutMs}ms'");
        try {
            $inserted = $this->connection->selectOne(<<<'SQL'
                INSERT INTO public.billium_idempotency_receipt (environment, seller_ref, command_kind, command_key, digest, outcome)
                VALUES (?, ?, ?, ?, ?, 'in_progress')
                ON CONFLICT (environment, seller_ref, command_kind, command_key) DO NOTHING
                RETURNING id
                SQL, [$caller->environment, $caller->sellerRef, $commandKind, $commandKey, $digest]);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '55P03') {
                throw new ProblemException(ProblemCode::IdempotencyInFlight, 'the same key is being processed');
            }

            throw $exception;
        }
        $this->connection->statement('SET LOCAL lock_timeout = 0');
        if (is_object($inserted)) {
            return IdempotencyClaim::claimed(Rows::int(Rows::field($inserted, 'id')));
        }

        $existing = $this->connection->selectOne(<<<'SQL'
            SELECT id, digest, outcome, result FROM public.billium_idempotency_receipt
            WHERE environment = ? AND seller_ref = ? AND command_kind = ? AND command_key = ?
            SQL, [$caller->environment, $caller->sellerRef, $commandKind, $commandKey]);
        if (! is_object($existing)) {
            throw new LogicException('Claim conflict without a committed row');
        }
        if (Rows::field($existing, 'digest') !== $digest) {
            // The same key with another payload: deterministic conflict, the original command is kept (COV-133).
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'the key was used with another payload');
        }
        $outcome = Rows::field($existing, 'outcome');
        $stored = Rows::field($existing, 'result');
        if (! in_array($outcome, ['accepted', 'refused'], true) || ! is_string($stored)) {
            throw new LogicException('A committed claim is never in progress: claim and complete share one root Tx');
        }
        // Decode nested objects as objects: associative decoding collapses {} and numeric-key objects into lists.
        $document = json_decode($stored, false, 64, JSON_THROW_ON_ERROR);
        $result = $document instanceof \stdClass ? get_object_vars($document) : [];
        if (($result['body'] ?? null) instanceof \stdClass) {
            $result['body'] = get_object_vars($result['body']);
        }

        return IdempotencyClaim::replay(StoredOutcome::fromResult($outcome, $result, Rows::int(Rows::field($existing, 'id')), true));
    }

    public function complete(int $claimId, StoredOutcome $outcome): void
    {
        $this->assertRootTransaction();
        $updated = $this->connection->update(<<<'SQL'
            UPDATE public.billium_idempotency_receipt SET outcome = ?, result = ?::jsonb, completed_at = now()
            WHERE id = ? AND outcome = 'in_progress'
            SQL, [$outcome->accepted ? 'accepted' : 'refused', json_encode($outcome->result(), JSON_THROW_ON_ERROR), $claimId]);
        if ($updated !== 1) {
            throw new LogicException("Claim {$claimId} is not in progress in this root Tx");
        }
    }

    private function assertRootTransaction(): void
    {
        if ($this->connection->transactionLevel() < 1) {
            throw new LogicException('Claim and complete run inside the runner root Tx (D51)');
        }
    }
}

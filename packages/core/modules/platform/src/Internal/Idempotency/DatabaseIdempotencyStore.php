<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Idempotency;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Platform\Contracts\Idempotency\FinancialIdempotencyStore;
use Billium\Platform\Data\Idempotency\IdempotencyClaim;
use Billium\Platform\Internal\Installation\Rows;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

/**
 * INSERT … ON CONFLICT DO NOTHING with a bounded wait: a concurrent claim of the same scope blocks on the unique
 * index until the first root Tx commits or rolls back. No check-then-insert.
 */
#[Internal]
final readonly class DatabaseIdempotencyStore implements FinancialIdempotencyStore
{
    public function __construct(private Connection $connection, private int $lockTimeoutMs) {}

    public function claim(Caller $caller, string $commandKind, string $commandKey, string $digest): IdempotencyClaim
    {
        return $this->claimFor($caller, null, $commandKind, $commandKey, $digest, null, null);
    }

    public function claimScoped(Caller $caller, FinancialScope $scope, string $commandKind, string $commandKey, string $digest, ?string $inputDigest = null, ?string $originalExecutionBindingRef = null): IdempotencyClaim
    {
        if ($scope->installationRef !== $caller->installationRef || $scope->sellerRef !== $caller->sellerRef
            || $scope->environment !== $caller->environment
            || ($scope->intentOwnerNamespace !== null && ! in_array($scope->intentOwnerNamespace, $caller->intentOwnerNamespaces, true))
            || ($scope->sourceAuthority !== null && ! in_array($scope->sourceAuthority, $caller->sourceAuthorities, true))) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Claim scope differs from the authenticated selection');
        }

        return $this->claimFor($caller, $scope, $commandKind, $commandKey, $digest, $inputDigest, $originalExecutionBindingRef);
    }

    private function claimFor(Caller $caller, ?FinancialScope $scope, string $commandKind, string $commandKey, string $digest, ?string $inputDigest, ?string $originalExecutionBindingRef): IdempotencyClaim
    {
        $this->assertRootTransaction();
        $scopeJson = $scope === null ? null : json_encode($scope, JSON_THROW_ON_ERROR);
        $this->connection->statement("SET LOCAL lock_timeout = '{$this->lockTimeoutMs}ms'");
        try {
            $inserted = $this->connection->selectOne(<<<'SQL'
                INSERT INTO public.billium_idempotency_receipt (environment, seller_ref, command_kind, command_key, digest, outcome, financial_scope, input_digest, original_execution_binding_ref)
                VALUES (?, ?, ?, ?, ?, 'in_progress', ?::jsonb, ?, ?)
                ON CONFLICT (environment, seller_ref, command_kind, command_key) DO NOTHING
                RETURNING id
                SQL, [$caller->environment, $caller->sellerRef, $commandKind, $commandKey, $digest, $scopeJson, $inputDigest, $originalExecutionBindingRef]);
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
            SELECT id, digest, outcome, result, financial_scope, input_digest, original_execution_binding_ref,
                (financial_scope = ?::jsonb) AS scope_matches FROM public.billium_idempotency_receipt
            WHERE environment = ? AND seller_ref = ? AND command_kind = ? AND command_key = ?
            SQL, [$scopeJson, $caller->environment, $caller->sellerRef, $commandKind, $commandKey]);
        if (! is_object($existing)) {
            throw new LogicException('Claim conflict without a committed row');
        }
        if (Rows::field($existing, 'digest') !== $digest) {
            // The same key with another payload: deterministic conflict, the original command is kept.
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'the key was used with another payload');
        }
        if (Rows::field($existing, 'financial_scope') !== null && Rows::field($existing, 'scope_matches') !== true) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'The retained claim has another authenticated selection');
        }
        $retainedInput = Rows::nullableString(Rows::field($existing, 'input_digest'));
        if ($retainedInput !== null && $inputDigest !== null && $retainedInput !== $inputDigest) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'The retained claim has different command input');
        }
        $retainedBinding = Rows::nullableString(Rows::field($existing, 'original_execution_binding_ref'));
        if ($retainedBinding !== null && $originalExecutionBindingRef !== null && $retainedBinding !== $originalExecutionBindingRef) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'The retained claim has a different original execution binding');
        }
        $outcome = Rows::field($existing, 'outcome');
        $stored = Rows::field($existing, 'result');
        if (! in_array($outcome, ['accepted', 'refused'], true) || ! is_string($stored)) {
            throw new LogicException('A committed claim is never in progress: claim and complete share one root Tx');
        }
        // Decode nested objects as objects: associative decoding collapses {} and numeric-key objects into lists.
        $document = json_decode($stored, false, 64, JSON_THROW_ON_ERROR);
        $result = $document instanceof stdClass ? get_object_vars($document) : [];
        if (($result['body'] ?? null) instanceof stdClass) {
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
            throw new LogicException('Claim and complete run inside the runner root Tx');
        }
    }
}

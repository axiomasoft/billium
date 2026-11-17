<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Idempotency;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Platform\Contracts\Idempotency\IdempotencyReceiptQueries;
use Billium\Platform\Data\Idempotency\StoredCommandReceipt;
use Billium\Platform\Internal\Installation\Rows;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class DatabaseIdempotencyReceiptQueries implements IdempotencyReceiptQueries
{
    /** @param Closure():CallerContext $callers */
    public function __construct(private Connection $connection, private Closure $callers) {}

    public function byCommandKey(FinancialScope $scope, string $schema, string $qualifiedKey): ?StoredCommandReceipt
    {
        $query = $this->authorized($scope);
        if ($schema === '' || $qualifiedKey === '' || strlen($schema) > 200 || strlen($qualifiedKey) > 200) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'A bounded command schema and qualified key are required');
        }

        return $this->receipt($query->where('command_kind', $schema)->where('command_key', $qualifiedKey)->first());
    }

    public function byReceiptRef(FinancialScope $scope, string $class, string $receiptRef): ?StoredCommandReceipt
    {
        $query = $this->authorized($scope);
        if ($class === '' || $receiptRef === '' || strlen($class) > 200 || strlen($receiptRef) > 200) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'A bounded receipt class and opaque reference are required');
        }

        // Several command keys may legitimately replay the same original receipt. The first retained claim wins.
        return $this->receipt($query->where('outcome', 'accepted')->whereRaw("result->>'class' = ?", [$class])
            ->whereRaw("result#>>'{body,receiptRef}' = ?", [$receiptRef])->orderBy('created_at')->orderBy('id')->first());
    }

    private function authorized(FinancialScope $scope): Builder
    {
        if ($this->connection->transactionLevel() !== 0 || $this->connection->getPdo()->inTransaction()) {
            throw new LogicException('Public receipt queries authenticate outside database transactions');
        }
        $caller = ($this->callers)()->current();
        if ($scope->installationRef !== $caller->installationRef || $scope->sellerRef !== $caller->sellerRef
            || $scope->environment !== $caller->environment
            || ($scope->intentOwnerNamespace !== null && ! in_array($scope->intentOwnerNamespace, $caller->intentOwnerNamespaces, true))
            || ($scope->sourceAuthority !== null && ! in_array($scope->sourceAuthority, $caller->sourceAuthorities, true))) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Receipt scope differs from the authenticated selection');
        }

        return $this->connection->table('billium_idempotency_receipt')->where('seller_ref', $scope->sellerRef)
            ->where('environment', $scope->environment)->whereRaw('financial_scope = ?::jsonb', [json_encode($scope, JSON_THROW_ON_ERROR)])
            ->whereIn('outcome', ['accepted', 'refused']);
    }

    private function receipt(?stdClass $row): ?StoredCommandReceipt
    {
        if ($row === null) {
            return null;
        }
        $stored = Rows::field($row, 'result');
        if (! is_string($stored)) {
            throw new LogicException('A completed receipt has a stored result');
        }
        $document = json_decode($stored, false, 64, JSON_THROW_ON_ERROR);
        $result = $document instanceof stdClass ? get_object_vars($document) : [];
        if (($result['body'] ?? null) instanceof stdClass) {
            $result['body'] = get_object_vars($result['body']);
        }
        $outcome = StoredOutcome::fromResult(Rows::string(Rows::field($row, 'outcome')), $result, Rows::int(Rows::field($row, 'id')), false);

        return new StoredCommandReceipt($outcome, Rows::string(Rows::field($row, 'command_kind')),
            Rows::string(Rows::field($row, 'command_key')), Rows::string(Rows::field($row, 'digest')),
            new DateTimeImmutable(Rows::string(Rows::field($row, 'created_at'))), new DateTimeImmutable(Rows::string(Rows::field($row, 'completed_at'))),
            Rows::nullableString(Rows::field($row, 'input_digest')), Rows::string(Rows::field($row, 'canonicalization_version')),
            ($expiry = Rows::nullableString(Rows::field($row, 'expires_at'))) === null ? null : new DateTimeImmutable($expiry),
            Rows::nullableString(Rows::field($row, 'original_execution_binding_ref')));
    }
}

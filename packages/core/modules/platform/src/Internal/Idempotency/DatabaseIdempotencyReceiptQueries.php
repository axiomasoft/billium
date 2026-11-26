<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Idempotency;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\FindReceipt;
use Billium\Contracts\Data\Envelopes\ReceiptResult;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Platform\Contracts\Idempotency\IdempotencyReceiptQueries;
use Billium\Platform\Contracts\Idempotency\ReceiptAbsenceAuthority;
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

    public function replayCommand(FinancialScope $scope, string $schema, string $key, string $inputDigest): ?StoredOutcome
    {
        $lookup = $this->withinGrants($scope);
        if ($schema === '' || $key === '' || strlen($schema) > 200 || strlen($key) > 200
            || preg_match('/\Asha256:[0-9a-f]{64}\z/', $inputDigest) !== 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid);
        }
        $receipt = $this->receipt($lookup->where('command_kind', $schema)->where('command_key', $key)->first());
        if ($receipt === null) {
            return null;
        }
        if ($receipt->inputDigest === null || ! hash_equals($receipt->inputDigest, $inputDigest)) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'The retained claim has different command input');
        }

        return $receipt->outcome->asReplay();
    }

    public function byScopedReceiptRef(FinancialScope $scope, string $receiptRef): ?StoredCommandReceipt
    {
        $lookup = $this->withinGrants($scope);
        if ($receiptRef === '' || strlen($receiptRef) > 200 || str_contains($receiptRef, "\0")) {
            throw new ProblemException(ProblemCode::ValidationInvalid);
        }
        $matches = $lookup->where('outcome', 'accepted')->whereRaw("result#>>'{body,receiptRef}' = ?", [$receiptRef])
            ->orderBy('id')->get();
        $first = $matches->first();
        if ($first !== null && $matches->contains(static fn (stdClass $row): bool => $row->financial_scope !== $first->financial_scope
            || Rows::field($row, 'command_kind') !== Rows::field($first, 'command_kind'))) {
            throw new ProblemException(ProblemCode::LookupUnavailable, 'Receipt reference is ambiguous in the authenticated selection');
        }

        return $this->receipt($first);
    }

    public function find(FinancialScope $scope, FindReceipt $query, ?ReceiptAbsenceAuthority $authority = null): ReceiptResult
    {
        $codec = new Codec;
        $query = $codec->decode($codec->encode($query));
        assert($query instanceof FindReceipt);
        if ($query->installationRef !== $scope->installationRef || $query->sellerRef !== $scope->sellerRef
            || $query->environment !== $scope->environment) {
            throw new ProblemException(ProblemCode::AuthDenied);
        }
        $stored = $this->byScopedReceiptRef($scope, $query->receiptRef);
        $body = ['schema' => FindReceipt::SCHEMA, 'installationRef' => $query->installationRef,
            'sellerRef' => $query->sellerRef, 'environment' => $query->environment, 'receiptRef' => $query->receiptRef];
        if ($stored === null) {
            $certificate = $authority?->confirmAbsent(($this->callers)()->current(), $scope, $query->receiptRef);
            if ($certificate === null || get_object_vars($certificate) === []) {
                throw new ProblemException(ProblemCode::LookupUnavailable);
            }
            $body += ['status' => 'absent', 'absenceCertificate' => $certificate];
        } else {
            $original = $stored->outcome;
            $payload = $original->body['wireReceipt'] ?? $original->body;
            $schema = is_array($payload) ? ($payload['schema'] ?? null) : null;
            if (! is_string($schema)) {
                throw new ProblemException(ProblemCode::LookupUnavailable);
            }
            $body += ['status' => 'found', 'receiptSchema' => $schema, 'body' => (object) $payload];
        }
        $body['digest'] = $codec->digest($body);
        $result = $codec->decode($codec->canonicalize($body));
        assert($result instanceof ReceiptResult);

        return $result;
    }

    private function withinGrants(FinancialScope $scope): Builder
    {
        $query = $this->authenticated($scope);
        $caller = ($this->callers)()->current();
        foreach (['intentOwnerNamespace' => $caller->intentOwnerNamespaces, 'sourceAuthority' => $caller->sourceAuthorities] as $field => $grants) {
            $selector = $scope->{$field};
            $column = "financial_scope->>'".$field."'";
            if ($selector !== null) {
                $query->whereRaw($column.' = ?', [$selector]);
            } else {
                $query->where(static fn (Builder $allowed): Builder => $allowed->whereRaw($column.' IS NULL')->orWhereIn($query->getConnection()->raw($column), $grants));
            }
        }

        return $query;
    }

    private function authorized(FinancialScope $scope): Builder
    {
        return $this->authenticated($scope)->whereRaw('financial_scope = ?::jsonb', [json_encode($scope, JSON_THROW_ON_ERROR)]);
    }

    private function authenticated(FinancialScope $scope): Builder
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

        return $this->connection->table('billium_idempotency_receipt')->useWritePdo()->where('seller_ref', $scope->sellerRef)
            ->where('environment', $scope->environment)->whereRaw("financial_scope->>'installationRef' = ?", [$scope->installationRef])
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

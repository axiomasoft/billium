<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Identity;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\AuthorizePayment;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\OriginDecision;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final class PaymentIdentity
{
    public const string VERSION = 'execution.intent.v1';

    public static function select(Caller $caller, ExecutionScope $scope, string $profile): FinancialScope
    {
        if ($caller->installationRef !== $scope->installationRef || $caller->sellerRef !== $scope->sellerRef
            || $caller->environment !== $scope->environment || ! in_array($scope->intentOwnerNamespace, $caller->intentOwnerNamespaces, true)
            || ! in_array($profile, ['remote-billium', 'standalone-billium'], true) || ! in_array($profile, $caller->sourceAuthorities, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Payment scope or execution profile is outside the authenticated grant');
        }

        return new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, $profile);
    }

    public static function commandKey(ExecutionScope $scope, string $kind, string $sourceContext, string $key): string
    {
        return self::digest([
            'schema' => 'billium.execution.command-key.v1',
            'installationRef' => $scope->installationRef, 'sellerRef' => $scope->sellerRef, 'environment' => $scope->environment,
            'intentOwnerNamespace' => $scope->intentOwnerNamespace,
            'operationKind' => $kind,
            'sourceContext' => $sourceContext,
            'commandKey' => $key,
        ]);
    }

    public static function semantic(ExecutionScope $scope, ExecutePayment|AuthorizePayment $command, MerchantRevision $merchant, OriginDecision $origin): string
    {
        return self::digest([
            'schema' => 'billium.execution.root-semantics.v1',
            'scope' => get_object_vars($scope),
            'operationRef' => $command->operationRef,
            'schemaId' => $command::SCHEMA,
            'logicalKind' => $command->operationKind,
            'source' => $command->source,
            'executionProfile' => $command->executionProfile,
            'routingEpoch' => (string) $command->routingEpoch,
            'money' => $command->money->toWire(),
            'merchantRevisionRef' => $merchant->revisionRef,
            'merchantRevisionDigest' => $merchant->digest,
            'merchantAccountRef' => $merchant->merchantAccountRef,
            'credentialVersionRef' => $merchant->credentialVersionRef,
            'driverId' => $merchant->driverId,
            'driverContractVersion' => $merchant->driverContractVersion,
            'capabilityDigest' => $merchant->capabilityDigest,
            'returnContextRef' => $command->returnContextRef,
            'collectionAuthorizationRef' => $origin->authorizationRef,
            'commercialBasisDigest' => $origin->basisDigest,
            'policyVersion' => $origin->policyVersion,
            'periodRef' => $origin->periodRef,
        ]);
    }

    public static function reference(ExecutionScope $scope, string $operationRef, string $purpose): string
    {
        return $purpose.':'.substr(self::digest(['schema' => 'billium.execution.reference.v1', 'scope' => get_object_vars($scope),
            'operationRef' => $operationRef, 'purpose' => $purpose]), strlen('sha256:'));
    }

    /** @param array<string,mixed> $fields */
    public static function digest(array $fields): string
    {
        return 'sha256:'.hash('sha256', (new Codec)->canonicalize($fields));
    }

    /**
     * Command terms that define its meaning: the envelope minus its transport identity (key, timestamp, digest).
     *
     * @return array<mixed> JSON object members; numeric member names decode to integer keys
     */
    public static function terms(stdClass $raw): array
    {
        $terms = get_object_vars($raw);
        unset($terms['commandKey'], $terms['requestedAt'], $terms['digest']);

        return $terms;
    }

    /** Identities are stored as PostgreSQL text, which cannot carry a NUL byte. */
    public static function assertText(string $refusal, string ...$fields): void
    {
        foreach ($fields as $field) {
            if (str_contains($field, "\0")) {
                throw new ProblemException(ProblemCode::ValidationInvalid, $refusal);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Reconciliation;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use DateTimeImmutable;
use Packman\Attributes\Internal;

#[Internal]
final readonly class RecoveryWindow
{
    public function __construct(
        public ExecutionScope $scope,
        public string $source,
        public string $ref,
        public MerchantRevision $merchant,
        public string $currency,
        public DateTimeImmutable $from,
        public DateTimeImmutable $through,
        public ?string $attempt = null,
    ) {
        if (trim($ref) === '' || strlen($ref) > 200 || $through < $from || ($attempt === null && ($through <= $from || $through > new DateTimeImmutable))
            || ! in_array($currency, $merchant->currencyCodes, true) || get_object_vars($scope) !== get_object_vars($merchant->scope)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid recovery horizon, account or currency');
        }
    }

    public function routeDigest(string $actualCredentialVersion): string
    {
        return PaymentIdentity::digest(['schema' => 'billium.execution.inventory-route.v1', 'installationRef' => $this->scope->installationRef,
            'sellerRef' => $this->scope->sellerRef, 'environment' => $this->scope->environment, 'ownerNamespace' => $this->scope->intentOwnerNamespace,
            'sourceAuthority' => $this->source, 'providerId' => $this->merchant->providerId, 'merchantAccountRef' => $this->merchant->merchantAccountRef,
            'merchantRevisionRef' => $this->merchant->revisionRef, 'merchantRevisionDigest' => $this->merchant->digest,
            'credentialVersionRef' => $actualCredentialVersion, 'currency' => $this->currency]);
    }

    /** @return array<string,string> */
    public function scopeFields(): array
    {
        return ['installation_ref' => $this->scope->installationRef, 'seller_ref' => $this->scope->sellerRef, 'environment' => $this->scope->environment];
    }

    /** @return array<string,string|null> */
    public function bindingFields(): array
    {
        return ['owner_namespace' => $this->scope->intentOwnerNamespace, 'source_authority' => $this->source,
            'revision_ref' => $this->merchant->revisionRef, 'credential_version_ref' => $this->merchant->credentialVersionRef,
            'provider_id' => $this->merchant->providerId, 'currency' => $this->currency, 'workflow' => $this->attempt === null ? 'inventory' : 'targeted',
            'attempt_ref' => $this->attempt];
    }

    /** @param list<string> $refs */
    public function receipt(string $state, array $refs = []): ExecutionReceipt
    {
        return new ExecutionReceipt($this->ref, $this->ref, $this->attempt ?? $this->ref,
            ExecutionCodec::digest([$this->scopeFields(), $this->bindingFields(), $this->from, $this->through]), $state, false, $refs);
    }
}

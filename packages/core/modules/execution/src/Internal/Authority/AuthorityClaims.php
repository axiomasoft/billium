<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Authority;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Money;
use Billium\Core\Data\Runtime\FinancialAdmissionToken;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Data\DispatchRequest;
use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Enums\EffectKind;
use Packman\Attributes\Internal;

/** Explicit canonical maps shared by local checks; no PHP object serialization is an identity. */
#[Internal]
final class AuthorityClaims
{
    public static function bindingDigest(FrozenBinding $binding): string
    {
        $map = [
            'schema' => 'billium.execution.binding.v1',
            'scope' => get_object_vars($binding->scope),
            'executionProfile' => $binding->executionProfile,
            'businessIntentRef' => $binding->businessIntentRef,
            'rootOperationRef' => $binding->rootOperationRef,
            'merchantRevisionRef' => $binding->merchantRevisionRef,
            'merchantAccountRef' => $binding->merchantAccountRef,
            'driverId' => $binding->driverId,
            'driverContractVersion' => $binding->driverContractVersion,
            'capabilityDigest' => $binding->capabilityDigest,
            'semanticDigest' => $binding->semanticDigest,
            'canonicalizationVersion' => $binding->canonicalizationVersion,
            'collectionAuthorizationRef' => $binding->collectionAuthorizationRef,
            'sourceContext' => $binding->sourceContext,
        ];
        $origin = $binding->origin;
        if ($origin !== null) {
            $map['schema'] = 'billium.execution.binding.v2';
            $map['origin'] = [
                'scope' => ['installationRef' => $origin->scope->installationRef, 'sellerRef' => $origin->scope->sellerRef,
                    'environment' => $origin->scope->environment, 'intentOwnerNamespace' => $origin->scope->intentOwnerNamespace],
                'operationRef' => $origin->operationRef, 'businessIntentRef' => $origin->businessIntentRef,
                'sourceContext' => $origin->sourceContext, 'executionProfile' => $origin->executionProfile,
                'routingEpoch' => (string) $origin->routingEpoch, 'authorizationRef' => $origin->authorizationRef,
                'basisDigest' => $origin->basisDigest, 'policyVersion' => $origin->policyVersion, 'periodRef' => $origin->periodRef,
            ];
        }

        if ($binding->sourceOrderRef !== null) {
            $map['schema'] = 'billium.execution.binding.v3';
            $map['sourceOrderRef'] = $binding->sourceOrderRef;
        }

        return self::digest($map);
    }

    public static function immutableDriverRequestDigest(FrozenBinding $binding, string $operationRef, string $attemptRef, string $providerReference, EffectKind $kind, Money $amount): string
    {
        return self::digest([
            'schema' => 'billium.execution.driver-request.v1', 'bindingDigest' => self::bindingDigest($binding),
            'operationRef' => $operationRef, 'attemptRef' => $attemptRef, 'providerReference' => $providerReference,
            'kind' => $kind->value, 'amount' => $amount->toWire(), 'merchantAccountRef' => $binding->merchantAccountRef,
            'driverId' => $binding->driverId, 'driverContractVersion' => $binding->driverContractVersion,
            'capabilityDigest' => $binding->capabilityDigest,
        ]);
    }

    public static function sourceLineage(FinancialAdmissionToken $token, int $localSessionEpoch): string
    {
        return 'execution-lineage:'.hash('sha256', (new Codec)->canonicalize([
            'schema' => 'billium.execution.source-lineage.v1',
            'installationRef' => $token->installationRef,
            'ownerSessionRef' => $token->ownerSessionRef,
            'deploymentGeneration' => (string) $token->deploymentGeneration,
            'attachmentGeneration' => (string) $token->attachmentGeneration,
            'authorityEpoch' => (string) $token->authorityEpoch,
            'localSessionEpoch' => (string) $localSessionEpoch,
        ]));
    }

    public static function requestDigest(DispatchRequest $request): string
    {
        return self::digest([
            'schema' => 'billium.execution.dispatch-request.v1',
            'scope' => get_object_vars($request->scope),
            'operationRef' => $request->operationRef, 'attemptRef' => $request->attemptRef,
            'providerReference' => $request->providerReference, 'kind' => $request->kind->value,
            'amount' => $request->amount->toWire(), 'bindingDigest' => $request->bindingDigest,
            'capabilityDigest' => $request->capabilityDigest, 'ownerSessionRef' => $request->ownerSessionRef,
            'localSessionEpoch' => (string) $request->localSessionEpoch, 'authorityEpoch' => (string) $request->authorityEpoch,
            'deploymentGeneration' => (string) $request->deploymentGeneration, 'attachmentGeneration' => (string) $request->attachmentGeneration,
            'leaseFence' => (string) $request->leaseFence, 'audience' => $request->audience,
        ]);
    }

    public static function ticketDigest(DispatchTicket $ticket): string
    {
        return self::digest([
            'requestDigest' => self::requestDigest($ticket->claims), 'ticketRef' => $ticket->ticketRef,
            'issuerRef' => $ticket->issuerRef, 'issuedAt' => $ticket->issuedAt->format('U.u'),
            'expiresAt' => $ticket->expiresAt->format('U.u'), 'nonce' => $ticket->nonce,
            'egressFenceRef' => $ticket->egressFenceRef, 'signatureDigest' => $ticket->signatureDigest,
        ]);
    }

    public static function tokenDigest(FinancialAdmissionToken $token): string
    {
        return self::digest([
            'installationRef' => $token->installationRef,
            'ownerSessionRef' => $token->ownerSessionRef,
            'deploymentGeneration' => (string) $token->deploymentGeneration,
            'attachmentGeneration' => (string) $token->attachmentGeneration,
            'authorityEpoch' => (string) $token->authorityEpoch,
            'processRef' => $token->processRef,
            'nonce' => $token->nonce,
            'permittedModes' => array_map(static fn (FinancialMode $mode): string => $mode->value, $token->permittedModes),
            'expiresAt' => $token->expiresAt->format('U.u'),
        ]);
    }

    /** @param array<string,mixed> $map */
    private static function digest(array $map): string
    {
        return 'sha256:'.hash('sha256', (new Codec)->canonicalize($map));
    }
}

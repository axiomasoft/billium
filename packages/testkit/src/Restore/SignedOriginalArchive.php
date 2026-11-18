<?php

declare(strict_types=1);

namespace Billium\Testkit\Restore;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\CapturePayment;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Data\Envelopes\RequestRefund;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Data\Runtime\FinancialAdmissionToken;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Contracts\OriginalIdentityArchive;
use Billium\Execution\Data\ArchivedExecutionIdentity;
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\OriginDecision;
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessIdentity;
use DateTimeImmutable;
use LogicException;
use Packman\Attributes\Api;

/** Fixture host with two independently signed, append-only external journals and a process-local recovery authorization cache. */
#[Api(since: '1.0')]
final class SignedOriginalArchive implements OriginalIdentityArchive
{
    /** @var array<string,ArchivedExecutionIdentity> */
    private array $cache = [];

    public function __construct(private readonly string $path, private readonly string $key, private readonly FinancialAuthorityEvidence $authority) {}

    public function recordSource(ExecutePayment|CapturePayment|RequestRefund $command): void
    {
        $this->record('source', $command->operationRef, $command);
    }

    public function recordDispatch(ProviderRecord $record, OperationRecord $op, AttemptRecord $attempt, MerchantRevision $merchant, string $lineage): void
    {
        $req = $record->request;
        if (serialize($op->binding) !== serialize($req->binding) || $op->requested->toWire() !== $req->amount->toWire() || $attempt->immutableRequestDigest !== $req->permit->immutableRequestDigest
            || $attempt->startedAt === null || $attempt->ownerSessionRef === null || $attempt->requestDeadlineAt === null) {
            throw new LogicException('Fixture dispatch manifest disagrees with the accepted provider request');
        }
        $this->record('dispatch', $attempt->providerReference, new DispatchManifest($op, $attempt, $merchant, $lineage, $req->permit, $req->capabilities->details->lookupNamespace, $record->providerOperationId));
    }

    private function record(string $kind, string $ref, object $value): void
    {
        $data = base64_encode(serialize($value));
        $mac = hash_hmac('sha256', serialize([$kind, $ref, $data]), $this->key);
        new DurableJournal($this->path.'.'.$kind)->append(new JournalEvent($kind, ['reference' => $ref, 'payload' => $data, 'mac' => $mac], ProcessIdentity::current()));
    }

    /** @return array{object,string}|null */
    private function read(string $kind, string $ref): ?array
    {
        $found = null;
        foreach (new DurableJournal($this->path.'.'.$kind)->records() as $entry) {
            if (! $entry instanceof JournalEvent || $entry->kind !== $kind || ($entry->data['reference'] ?? null) !== $ref) {
                continue;
            }
            $payload = $entry->data['payload'] ?? null;
            $mac = $entry->data['mac'] ?? null;
            if (! is_string($payload) || ! is_string($mac) || ! hash_equals(hash_hmac('sha256', serialize([$kind, $ref, $payload]), $this->key), $mac)
                || ($found !== null && $found[1] !== 'sha256:'.$mac)) {
                throw new LogicException('Forged or conflicting independent archive record');
            }
            $decoded = unserialize((string) base64_decode($payload, true));
            if (! is_object($decoded)) {
                throw new LogicException('Invalid signed archive payload');
            }
            $found = [$decoded, 'sha256:'.$mac];
        }

        return $found;
    }

    public function authenticate(ExecutionScope $scope, string $account, string $reference, FinancialAdmissionToken $token, int $epoch): ?ArchivedExecutionIdentity
    {
        $key = hash('sha256', serialize([$scope, $account, $reference, $token, $epoch]));
        unset($this->cache[$key]);
        $host = $this->authority->process($scope->installationRef, $token->processRef, $token->nonce);
        if ($host === null || serialize($host->token) !== serialize($token) || ! $token->permits(FinancialMode::Recovery) || $token->expired(new DateTimeImmutable)) {
            return null;
        }
        $manifest = $this->read('dispatch', $reference);
        if ($manifest === null) {
            return null;
        }
        [$m, $md] = $manifest;
        if (! $m instanceof DispatchManifest) {
            return null;
        }
        $op = $m->op;
        $a = $m->attempt;
        if ($a->ownerSessionRef === null || $a->startedAt === null || $a->requestDeadlineAt === null) {
            return null;
        }
        $b = $op->binding;
        $source = $this->read('source', $op->operationRef);
        if ($source === null || get_object_vars($b->scope) !== get_object_vars($scope) || $b->merchantAccountRef !== $account || $a->providerReference !== $reference) {
            return null;
        }
        [$command, $sd] = $source;
        if ((! $command instanceof ExecutePayment && ! $command instanceof CapturePayment && ! $command instanceof RequestRefund) || $command->money->toWire() !== $op->requested->toWire()
            || ! is_string($command->source->intentRef ?? null) || $command->source->context !== $b->sourceContext || $b->origin === null || $command->operationRef !== $op->operationRef) {
            return null;
        }
        if ($command instanceof CapturePayment || $command instanceof RequestRefund) {
            $kind = $command instanceof CapturePayment ? 'capture' : 'refund';
            if ($command->originalOperationRef !== $b->rootOperationRef || $op->kind->value !== $kind) {
                return null;
            }
            $terms = get_object_vars($command->raw);
            unset($terms['commandKey'], $terms['requestedAt'], $terms['digest']);
            $semantic = $command instanceof CapturePayment
                ? self::canonicalDigest(['schema' => 'billium.execution.authorization-child.v1', 'rootBinding' => $b->semanticDigest, 'scope' => get_object_vars($scope), 'command' => $terms])
                : self::canonicalDigest(['schema' => 'billium.execution.refund-semantics.v1', 'scope' => get_object_vars($scope), 'originalBinding' => $b->semanticDigest, 'terms' => $terms]);
        } else {
            if ($command->merchantAccountRef !== $account || $command->bindingRevision !== $b->merchantRevisionRef || $command->source->intentRef !== $b->businessIntentRef) {
                return null;
            }
            $semantic = self::rootSemanticDigest($scope, $command, $m->merchant, $b->origin);
        }
        if ($semantic !== $op->economicDigest) {
            return null;
        }

        return $this->cache[$key] = new ArchivedExecutionIdentity($b, $op->kind, $op->requested, $command->source->intentRef, $op->operationRef,
            $semantic, $command->commandKey, self::commandKeyDigest($scope, $op->kind->value, $b->sourceContext, $command->commandKey),
            self::commandInputDigest($command), $a->attemptRef, $reference, $m->merchant->providerId, $m->merchant->digest, $m->namespace,
            $m->merchant->credentialVersionRef, $a->immutableRequestDigest, $m->permit->leaseFence, $a->ownerSessionRef, $m->permit->sessionEpoch,
            $m->lineage, $m->permit->ticketRef, $m->permit->collectionDispatchPermitRef, $op->createdAt, $a->startedAt, $a->requestDeadlineAt, $md, $sd, $command instanceof RequestRefund ? $command->reasonCode : null,
            $command instanceof RequestRefund ? $command->category : null, $m->providerOperationId);
    }

    public function cached(ExecutionScope $scope, string $account, string $reference, FinancialAdmissionToken $token, int $epoch): ?ArchivedExecutionIdentity
    {
        return $this->cache[hash('sha256', serialize([$scope, $account, $reference, $token, $epoch]))] ?? null;
    }

    /** @param array<string, mixed> $fields */
    private static function canonicalDigest(array $fields): string
    {
        return 'sha256:'.hash('sha256', (new Codec)->canonicalize($fields));
    }

    private static function commandKeyDigest(ExecutionScope $scope, string $kind, string $sourceContext, string $key): string
    {
        return self::canonicalDigest([
            'schema' => 'billium.execution.command-key.v1',
            'installationRef' => $scope->installationRef,
            'sellerRef' => $scope->sellerRef,
            'environment' => $scope->environment,
            'intentOwnerNamespace' => $scope->intentOwnerNamespace,
            'operationKind' => $kind,
            'sourceContext' => $sourceContext,
            'commandKey' => $key,
        ]);
    }

    private static function rootSemanticDigest(ExecutionScope $scope, ExecutePayment $command, MerchantRevision $merchant, OriginDecision $origin): string
    {
        return self::canonicalDigest([
            'schema' => 'billium.execution.root-semantics.v1',
            'scope' => get_object_vars($scope),
            'operationRef' => $command->operationRef,
            'schemaId' => ExecutePayment::SCHEMA,
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

    private static function commandInputDigest(ExecutePayment|CapturePayment|RequestRefund $command): string
    {
        $input = get_object_vars($command->raw);
        unset($input['requestedAt'], $input['commandKey']);
        $input['money'] = $command->money->toWire();

        return (new Codec)->digest($input);
    }
}

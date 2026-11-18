<?php

declare(strict_types=1);

namespace Billium\Testkit\Restore;

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
use Billium\Execution\Internal\Admission\CommandInputReplay;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Storage\ExecutionCodec;
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
        if (ExecutionCodec::encode($op->binding) !== ExecutionCodec::encode($req->binding) || $op->requested->toWire() !== $req->amount->toWire() || $attempt->immutableRequestDigest !== $req->permit->immutableRequestDigest
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
                ? PaymentIdentity::digest(['schema' => 'billium.execution.authorization-child.v1', 'rootBinding' => $b->semanticDigest, 'scope' => get_object_vars($scope), 'command' => $terms])
                : PaymentIdentity::digest(['schema' => 'billium.execution.refund-semantics.v1', 'scope' => get_object_vars($scope), 'originalBinding' => $b->semanticDigest, 'terms' => $terms]);
        } else {
            if ($command->merchantAccountRef !== $account || $command->bindingRevision !== $b->merchantRevisionRef || $command->source->intentRef !== $b->businessIntentRef) {
                return null;
            }
            $semantic = PaymentIdentity::semantic($scope, $command, $m->merchant, $b->origin);
        }
        if ($semantic !== $op->economicDigest) {
            return null;
        }

        return $this->cache[$key] = new ArchivedExecutionIdentity($b, $op->kind, $op->requested, $command->source->intentRef, $op->operationRef,
            $semantic, $command->commandKey, PaymentIdentity::commandKey($scope, $op->kind->value, $b->sourceContext, $command->commandKey),
            CommandInputReplay::digest($command), $a->attemptRef, $reference, $m->merchant->providerId, $m->merchant->digest, $m->namespace,
            $m->merchant->credentialVersionRef, $a->immutableRequestDigest, $m->permit->leaseFence, $a->ownerSessionRef, $m->permit->sessionEpoch,
            $m->lineage, $m->permit->ticketRef, $m->permit->collectionDispatchPermitRef, $op->createdAt, $a->startedAt, $a->requestDeadlineAt, $md, $sd, $command instanceof RequestRefund ? $command->reasonCode : null,
            $command instanceof RequestRefund ? $command->category : null, $m->providerOperationId);
    }

    public function cached(ExecutionScope $scope, string $account, string $reference, FinancialAdmissionToken $token, int $epoch): ?ArchivedExecutionIdentity
    {
        return $this->cache[hash('sha256', serialize([$scope, $account, $reference, $token, $epoch]))] ?? null;
    }
}

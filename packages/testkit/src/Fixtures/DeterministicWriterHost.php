<?php

declare(strict_types=1);

namespace Billium\Testkit\Fixtures;

use Billium\Contracts\Contracts\Codec;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Data\DispatchRequest;
use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\DriverRequest;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\ProviderLookupResult;
use Closure;
use Packman\Attributes\Api;
use RuntimeException;

/** Simulated host boundary; never production fencing or authority qualification. */
#[Api(since: '1.0')]
final readonly class DeterministicWriterHost implements DispatchEgress, WriterAdmission
{
    /** @param Closure():int $transactionLevel */
    public function __construct(public DurableJournal $journal, private FixtureClock $clock, private Closure $transactionLevel) {}

    public static function bindingDigest(FrozenBinding $binding): string
    {
        $map = [
            'schema' => 'billium.execution.binding.v1',
            'sourceContext' => $binding->sourceContext,
            'scope' => ['installationRef' => $binding->scope->installationRef, 'sellerRef' => $binding->scope->sellerRef, 'environment' => $binding->scope->environment, 'intentOwnerNamespace' => $binding->scope->intentOwnerNamespace],
            'executionProfile' => $binding->executionProfile, 'businessIntentRef' => $binding->businessIntentRef, 'rootOperationRef' => $binding->rootOperationRef,
            'merchantRevisionRef' => $binding->merchantRevisionRef, 'merchantAccountRef' => $binding->merchantAccountRef,
            'driverId' => $binding->driverId, 'driverContractVersion' => $binding->driverContractVersion,
            'capabilityDigest' => $binding->capabilityDigest, 'semanticDigest' => $binding->semanticDigest,
            'canonicalizationVersion' => $binding->canonicalizationVersion, 'collectionAuthorizationRef' => $binding->collectionAuthorizationRef,
        ];
        if ($binding->origin !== null) {
            $o = $binding->origin;
            $map['schema'] = 'billium.execution.binding.v2';
            $map['origin'] = [
                'scope' => ['installationRef' => $o->scope->installationRef, 'sellerRef' => $o->scope->sellerRef, 'environment' => $o->scope->environment,
                    'intentOwnerNamespace' => $o->scope->intentOwnerNamespace],
                'operationRef' => $o->operationRef, 'businessIntentRef' => $o->businessIntentRef, 'sourceContext' => $o->sourceContext,
                'executionProfile' => $o->executionProfile, 'routingEpoch' => (string) $o->routingEpoch, 'authorizationRef' => $o->authorizationRef,
                'basisDigest' => $o->basisDigest, 'policyVersion' => $o->policyVersion, 'periodRef' => $o->periodRef,
            ];
        }

        return 'sha256:'.hash('sha256', (new Codec)->canonicalize($map));
    }

    private function outsideTransaction(): void
    {
        if (($this->transactionLevel)() !== 0) {
            throw new RuntimeException('Host call inside transaction');
        }
    }

    private function session(DispatchRequest $request): string
    {
        return hash('sha256', serialize([$request->scope, $request->ownerSessionRef, $request->localSessionEpoch, $request->authorityEpoch, $request->deploymentGeneration, $request->attachmentGeneration, $request->audience]));
    }

    /** Explicit simulated authenticated host setup; not inferred from restored DB state. */
    public function openSession(DispatchRequest $request): void
    {
        $this->outsideTransaction();
        $this->journal->append(new JournalEvent('host.open', ['session' => $this->session($request), 'scope' => hash('sha256', (new Codec)->canonicalize(get_object_vars($request->scope)))], ProcessIdentity::current()));
    }

    public function retire(DispatchRequest $request, string $reason): void
    {
        $this->outsideTransaction();
        $this->journal->append(new JournalEvent('host.retire', ['session' => $this->session($request), 'scope' => hash('sha256', (new Codec)->canonicalize(get_object_vars($request->scope))), 'reason' => $reason], ProcessIdentity::current()));
    }

    public function revoke(DispatchTicket $ticket): void
    {
        $this->outsideTransaction();
        $this->journal->append(new JournalEvent('host.revoke', ['ticket' => $ticket->ticketRef], ProcessIdentity::current()));
    }

    /** @param list<object> $records */
    private function active(array $records, DispatchRequest $request): void
    {
        $open = false;
        foreach ($records as $record) {
            if ($record instanceof JournalEvent && ($record->data['session'] ?? null) === $this->session($request)) {
                if ($record->kind === 'host.retire') {
                    throw new RuntimeException('Retired simulated session');
                }
                if ($record->kind === 'host.open') {
                    $open = true;
                }
            }
        }
        if (! $open) {
            throw new RuntimeException('No independently granted simulated session');
        }
    }

    public function ticket(DispatchRequest $request): DispatchTicket
    {
        $this->outsideTransaction();

        return $this->journal->transact(function (array $records) use ($request): array {
            $this->active($records, $request);
            $nonce = bin2hex(random_bytes(16));
            $ticket = new DispatchTicket('simulated-ticket:'.$nonce, $request, 'simulated-writer-host.v1', $this->clock->now, $this->clock->now->modify('+30 seconds'), $nonce, 'simulated-fence:'.$this->session($request), hash('sha256', $nonce.serialize($request)));

            return ['value' => $ticket, 'append' => [$ticket]];
        });
    }

    /** @param list<object> $records */
    private function registered(array $records, DispatchTicket $ticket, DispatchRequest $request): void
    {
        $this->active($records, $request);
        $issued = false;
        foreach ($records as $record) {
            if ($record instanceof DispatchTicket && FixtureValue::same($record, $ticket)) {
                $issued = true;
            }
            if ($record instanceof JournalEvent && $record->kind === 'host.revoke' && ($record->data['ticket'] ?? null) === $ticket->ticketRef) {
                throw new RuntimeException('Revoked simulated ticket');
            }
        }
        if (! $issued || ! FixtureValue::same($ticket->claims, $request) || $ticket->expiresAt <= $this->clock->now || $ticket->issuedAt > $this->clock->now) {
            throw new RuntimeException('Unissued, changed or expired simulated ticket');
        }
    }

    public function verify(DispatchTicket $ticket, DispatchRequest $request): void
    {
        $this->outsideTransaction();
        $this->registered($this->journal->records(), $ticket, $request);
    }

    public function invoke(DispatchTicket $ticket, DriverRequest $request, Closure $send): ProviderLookupResult
    {
        $this->outsideTransaction();
        $result = null;
        $this->journal->transact(function (array $records) use ($ticket, $request): array {
            $this->registered($records, $ticket, $ticket->claims);
            $claims = $ticket->claims;
            if (! FixtureValue::same($claims->scope, $request->binding->scope) || $claims->operationRef !== $request->permit->operationRef || $claims->attemptRef !== $request->permit->attemptRef || $claims->leaseFence !== $request->permit->leaseFence || $claims->localSessionEpoch !== $request->permit->sessionEpoch || $ticket->ticketRef !== $request->permit->ticketRef || $ticket->egressFenceRef !== $request->permit->egressFenceRef || $claims->providerReference !== $request->providerReference || ! FixtureValue::same($claims->amount, $request->amount) || $claims->capabilityDigest !== $request->capabilities->digest || $claims->bindingDigest !== self::bindingDigest($request->binding)) {
                throw new RuntimeException('Simulated egress request differs from issued claims');
            }
            foreach ($records as $record) {
                if ($record instanceof JournalEvent && $record->kind === 'egress.send' && ($record->data['ticket'] ?? null) === $ticket->ticketRef) {
                    throw new RuntimeException('Simulated ticket already consumed');
                }
            }

            // Persist send intent before invoking transport; a crash must not reopen it.
            return ['value' => null, 'append' => [new JournalEvent('egress.send', ['ticket' => $ticket->ticketRef, 'operation' => $claims->operationRef], ProcessIdentity::current())]];
        }, static function () use ($send, &$result): void {
            $result = $send();
        });
        if (! $result instanceof ProviderLookupResult) {
            throw new RuntimeException('Simulated send returned no result');
        }

        return $result;
    }
}

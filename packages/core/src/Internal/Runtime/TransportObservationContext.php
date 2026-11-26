<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Runtime;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\AuthenticatedObservationContext;
use Billium\Core\Contracts\Runtime\IncomingCommandAuthenticator;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FactualCommandBinding;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Data\Runtime\SourceAuthorityGrant;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;
use WeakMap;

#[Internal]
final readonly class TransportObservationContext implements AuthenticatedObservationContext
{
    /** @var WeakMap<Caller,array{scope:FinancialScope,payloadDigest:string,proofDigest:string,commandRef:string,bindingDigest:string,expiresAt:DateTimeImmutable}> */
    private WeakMap $active;

    /** @param Closure():IncomingCommandAuthenticator $authenticator */
    public function __construct(private Closure $authenticator, private DatabaseManager $databases)
    {
        $this->active = new WeakMap;
    }

    /**
     * @param  array<string,mixed>  $message
     * @param  Closure(SourceAuthorityGrant,Caller):void  $consume
     */
    public function run(string $destination, array $message, Closure $consume): void
    {
        foreach ($this->databases->getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
                throw new LogicException('Observation authentication requires transaction level zero');
            }
        }
        $grant = ($this->authenticator)()->authenticate($destination, $message);
        $owner = $grant->intentOwnerNamespace;
        $caller = new Caller($grant->sellerRef, $grant->environment, $grant->installationRef, $grant->issuerRef, [$grant->sourceAuthority], $owner === null ? [] : [$owner]);
        if ($owner === null) {
            $consume($grant, $caller);

            return;
        }
        $scope = new FinancialScope($grant->installationRef, $grant->sellerRef, $grant->environment, $owner, $grant->sourceAuthority);
        $body = $message['payload'] ?? null;
        if (! is_string($message['commandRef'] ?? null) || $grant->expiresAt <= new DateTimeImmutable || $grant->audience !== $destination
            || $grant->commandBindingDigest !== FactualCommandBinding::digest($destination, $message, $scope)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Detached verification does not bind this command');
        }
        $decoded = json_decode((new Codec)->canonicalize($body), false, 64, JSON_THROW_ON_ERROR);
        if (! $decoded instanceof stdClass || ($decoded->sourceLineage ?? null) !== $grant->sourceLineage) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Detached verification does not bind this lineage');
        }
        $this->active[$caller] = ['scope' => $scope, 'payloadDigest' => 'sha256:'.hash('sha256', (new Codec)->canonicalize($decoded)), 'proofDigest' => $grant->proofDigest,
            'commandRef' => $message['commandRef'], 'bindingDigest' => $grant->commandBindingDigest, 'expiresAt' => $grant->expiresAt];
        try {
            $consume($grant, $caller);
        } finally {
            unset($this->active[$caller]);
        }
    }

    public function assertOrigin(Caller $caller, FinancialScope $scope, stdClass $payload, string $proofDigest): array
    {
        $entry = $this->active[$caller] ?? null;
        if ($entry === null || $entry['expiresAt'] <= new DateTimeImmutable || get_object_vars($entry['scope']) !== get_object_vars($scope) || $entry['proofDigest'] !== $proofDigest
            || $entry['payloadDigest'] !== 'sha256:'.hash('sha256', (new Codec)->canonicalize($payload))) {
            throw new ProblemException(ProblemCode::AuthDenied, 'No matching authenticated command invocation');
        }

        return ['commandRef' => $entry['commandRef'], 'bindingDigest' => $entry['bindingDigest']];
    }
}

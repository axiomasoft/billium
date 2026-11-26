<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Feed;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\ScopedCallerContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Platform\Contracts\Inbox\AuthenticatedObservationBootstrap;
use Billium\Platform\Contracts\Inbox\ObservationBootstrapAuthenticator;
use Billium\Platform\Data\Inbox\BootstrapPartition;
use Billium\Platform\Data\Inbox\VerifiedObservationBootstrap;
use Closure;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;
use WeakMap;

#[Internal]
final readonly class AuthenticatedBootstrapContext implements AuthenticatedObservationBootstrap
{
    /** @var WeakMap<Caller,VerifiedObservationBootstrap> */
    private WeakMap $active;

    /**
     * @param  Closure():ObservationBootstrapAuthenticator  $authenticator
     * @param  Closure():ScopedCallerContext  $callers
     */
    public function __construct(private Closure $authenticator, private Closure $callers, private DatabaseManager $databases)
    {
        $this->active = new WeakMap;
    }

    public function run(BootstrapPartition $snapshot, Closure $consume): mixed
    {
        foreach ($this->databases->getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
                throw new LogicException('Bootstrap authentication requires transaction level zero');
            }
        }
        $grant = ($this->authenticator)()->authenticate($snapshot);
        $grant->assertMatches($snapshot);
        $scope = $grant->scope;
        if ($scope->intentOwnerNamespace === null) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Missing original owner of bootstrap');
        }
        $caller = new Caller($snapshot->sellerRef, $snapshot->environment, $scope->installationRef, $grant->issuerRef, [$snapshot->sourceAuthority], [$scope->intentOwnerNamespace]);
        $this->active[$caller] = $grant;
        try {
            return ($this->callers)()->run($caller, fn () => $consume($grant));
        } finally {
            unset($this->active[$caller]);
        }
    }

    public function assertCurrent(Caller $caller, BootstrapPartition $snapshot, VerifiedObservationBootstrap $grant): void
    {
        if (($this->active[$caller] ?? null) !== $grant) {
            throw new ProblemException(ProblemCode::AuthDenied, 'No matching authenticated bootstrap invocation');
        }
        $grant->assertMatches($snapshot);
    }
}

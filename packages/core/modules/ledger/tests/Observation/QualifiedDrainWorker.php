<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Observation;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Contracts\Runtime\IncomingCommandAuthenticator;
use Billium\Core\Contracts\Runtime\InternalCommandReceiver;
use Billium\Core\Contracts\Runtime\QualifiedFactualCommandPublisher;
use Billium\Core\Contracts\Runtime\ScopedCallerContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FactualCommandBinding;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Data\Runtime\SourceAuthorityGrant;
use Billium\Core\Data\Runtime\VerifiedProcessAdmission;
use Billium\Core\Data\Runtime\VerifiedSessionAuthorization;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Execution\Laravel\ExecutionServiceProvider;
use Billium\Ledger\Internal\Observation\ParkedDrainer;
use Billium\Platform\Internal\CommandOutbox\CommandOutboxRelay;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use LogicException;

/** A new PHP process joins the fixture's external authority after the original consumer lost its token. */
final readonly class QualifiedDrainWorker implements Scenario
{
    public function run(Application $app, int $index, array $args): array
    {
        $db = $app->make(DatabaseManager::class)->connection('pgsql');
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new LogicException('Acquire process and certificate outside transactions');
        }
        $serialized = $args['session'];
        $key = getenv('BILLIUM_SIMULATED_AUTHORITY_KEY');
        if (! is_string($serialized) || ! is_string($key) || ! is_string($args['signature']) || ! hash_equals(hash_hmac('sha256', $serialized, $key), $args['signature'])) {
            throw new LogicException('Independent fixture authority signature required');
        }
        $session = unserialize(base64_decode($serialized, true));
        if (! $session instanceof VerifiedSessionAuthorization) {
            throw new LogicException('Invalid verified fixture certificate');
        }
        $caller = new Caller('seller-a', 'test', 'inst-test', 'new-queue-process', ['remote-billium'], ['source-a']);
        $app->instance(CallerContext::class, new FixedCallerContext($caller));
        $token = FixedSessionAdmission::token('inst-test', processRef: 'cold-'.getmypid());
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission($token));
        $independent = SimulatedFinancialAuthorityEvidence::install($app);
        if ($args['admitted'] === true) {
            $independent->issueProcess($token, [new FinancialScope('inst-test', 'seller-a', 'test', 'source-a', 'remote-billium')]);
        }
        $app->instance(FinancialAuthorityEvidence::class, new readonly class($independent, $session) implements FinancialAuthorityEvidence
        {
            public function __construct(private SimulatedFinancialAuthorityEvidence $issuer, private VerifiedSessionAuthorization $session) {}

            public function process(string $installationRef, string $processRef, string $nonce): ?VerifiedProcessAdmission
            {
                return $this->issuer->process($installationRef, $processRef, $nonce);
            }

            public function transition(string $installationRef, string $transitionRef): ?VerifiedSessionAuthorization
            {
                return $installationRef === $this->session->owner->installationRef && $transitionRef === $this->session->transitionRef ? $this->session : null;
            }
        });
        if (isset($args['origin'])) {
            return $this->relay($app, $db, $index, $args, $key, $caller);
        }
        $result = $app->make(ParkedDrainer::class)->drainPartition($args['partition']);

        return ['statuses' => array_column($result, 'status'), 'php' => getmypid(), 'pg' => $db->scalar('SELECT pg_backend_pid()')];
    }

    private function relay(Application $app, Connection $db, int $index, array $args, string $key, Caller $caller): array
    {
        if (! hash_equals(hash_hmac('sha256', $args['origin'], $key), $args['originSignature'])) {
            throw new LogicException('Independent signed command origin required');
        }
        $origin = unserialize(base64_decode($args['origin'], true));
        if (! $origin instanceof SourceAuthorityGrant) {
            throw new LogicException('Invalid origin journal entry');
        }
        $app['config']->set('billium.modules.enabled', ['platform', 'ledger', 'execution']);
        $app->forgetInstance(BilliumOptions::class);
        $app->register(ExecutionServiceProvider::class);
        $callers = new class($caller) implements ScopedCallerContext
        {
            public function __construct(private Caller $caller) {}

            public function current(): Caller
            {
                return $this->caller;
            }

            public function run(Caller $caller, Closure $body): mixed
            {
                $prior = $this->caller;
                $this->caller = $caller;
                try {
                    return $body();
                } finally {
                    $this->caller = $prior;
                }
            }
        };
        $app->instance(CallerContext::class, $callers);
        $app->instance(ScopedCallerContext::class, $callers);
        $app->instance(IncomingCommandAuthenticator::class, new readonly class($origin) implements IncomingCommandAuthenticator
        {
            public function __construct(private SourceAuthorityGrant $origin) {}

            public function authenticate(string $destinationRef, array $message): SourceAuthorityGrant
            {
                $o = $this->origin;
                $scope = new FinancialScope($o->installationRef, $o->sellerRef, $o->environment, $o->intentOwnerNamespace, $o->sourceAuthority);
                if ($o->commandBindingDigest !== FactualCommandBinding::digest($destinationRef, $message, $scope)) {
                    throw new LogicException('Origin differs');
                }

                return $o;
            }
        });
        $publisher = new readonly class($app->make(InternalCommandReceiver::class), $origin, $db, $index, $args['exitBeforeAck']) implements QualifiedFactualCommandPublisher
        {
            public function __construct(private InternalCommandReceiver $receiver, private SourceAuthorityGrant $origin, private Connection $db, private int $index, private bool $exitBeforeAck) {}

            public function publish(string $destinationRef, array $message): void
            {
                throw new LogicException('Unexpected legacy transport');
            }

            public function publishQualified(string $destinationRef, array $message, FinancialScope $scope): void
            {
                if ($this->db->transactionLevel() !== 0 || $this->origin->commandBindingDigest !== FactualCommandBinding::digest($destinationRef, $message, $scope)) {
                    throw new LogicException('Command lacks its independently retained origin');
                }
                $this->receiver->receive($destinationRef, $message);
                if ($this->exitBeforeAck) {
                    // Only the subprocess harness receives this checkpoint. The database delivery remains in-flight.
                    fwrite(STDOUT, json_encode(['index' => $this->index, 'ok' => ['checkpoint' => 'applied_before_ack', 'php' => getmypid(),
                        'pg' => $this->db->scalar('SELECT pg_backend_pid()')]], JSON_THROW_ON_ERROR).PHP_EOL);
                    exit(0);
                }
            }
        };
        $report = new CommandOutboxRelay($db, $publisher, claimSeconds: 1, budget: 3)->relay();

        return ['delivered' => $report->delivered, 'failed' => $report->failed, 'php' => getmypid(), 'pg' => $db->scalar('SELECT pg_backend_pid()')];
    }
}

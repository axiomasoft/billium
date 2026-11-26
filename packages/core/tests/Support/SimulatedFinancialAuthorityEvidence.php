<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Support;

use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runtime\ActivationCertificate;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialAdmissionToken;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Data\Runtime\NamespaceDisposition;
use Billium\Core\Data\Runtime\OwnerSessionIdentity;
use Billium\Core\Data\Runtime\RecoveryCoverage;
use Billium\Core\Data\Runtime\RetirementProof;
use Billium\Core\Data\Runtime\VerifiedProcessAdmission;
use Billium\Core\Data\Runtime\VerifiedSessionAuthorization;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use LogicException;

/**
 * SIMULATION ONLY. Independent signed host journal, outside the financial DB and inherited by child processes.
 * Issuance/authentication occurs explicitly at Tx0. FixedSessionAdmission changes never update this journal.
 * Its HMAC key and external state are fixture inputs, not production retirement/egress/restore qualification.
 */
final class SimulatedFinancialAuthorityEvidence implements FinancialAuthorityEvidence
{
    /** @var array<string, VerifiedProcessAdmission> */
    private array $processes = [];

    /** @var array<string, VerifiedSessionAuthorization> */
    private array $transitions = [];

    /** @var array<string, array{owner: OwnerSessionIdentity, epoch: int, retirement: RetirementProof}> */
    private array $owners = [];

    public int $acquisitions = 0;

    public int $cachedReads = 0;

    private function __construct(private Application $app, private string $key) {}

    public static function reset(): void
    {
        putenv('BILLIUM_SIMULATED_AUTHORITY_KEY='.bin2hex(random_bytes(32)));
        putenv('BILLIUM_SIMULATED_AUTHORITY_JOURNAL');
    }

    public static function install(Application $app): self
    {
        if ($app->bound(self::class)) {
            return $app->make(self::class);
        }
        $key = getenv('BILLIUM_SIMULATED_AUTHORITY_KEY');
        if (! is_string($key) || $key === '') {
            self::reset();
            $key = (string) getenv('BILLIUM_SIMULATED_AUTHORITY_KEY');
        }
        $self = new self($app, $key);
        $self->assertTx0();
        $journal = getenv('BILLIUM_SIMULATED_AUTHORITY_JOURNAL');
        if (is_string($journal) && $journal !== '') {
            [$mac, $payload] = explode(':', $journal, 2);
            if (! hash_equals(hash_hmac('sha256', $payload, $key), $mac)) {
                throw new LogicException('Forged simulated host journal');
            }
            [$self->transitions, $self->owners] = unserialize(base64_decode($payload, true));
        }
        $app->instance(self::class, $self);
        $app->instance(FinancialAuthorityEvidence::class, $self);

        return $self;
    }

    public function process(string $installationRef, string $processRef, string $nonce): ?VerifiedProcessAdmission
    {
        $this->cachedReads++;

        return $this->processes[$installationRef.'|'.$processRef.'|'.$nonce] ?? null;
    }

    public function transition(string $installationRef, string $transitionRef): ?VerifiedSessionAuthorization
    {
        $this->cachedReads++;

        return $this->transitions[$installationRef.'|'.$transitionRef] ?? null;
    }

    /** @param list<FinancialScope>|null $scopes Explicitly signed wildcard fixture scope by default. */
    public function issueProcess(FinancialAdmissionToken $token, ?array $scopes = null): void
    {
        $this->assertTx0();
        $this->acquisitions++;
        $scopes ??= [new FinancialScope($token->installationRef, null, null, null)];
        $payload = serialize([$token, $scopes]);
        $signature = hash_hmac('sha256', $payload, $this->key);
        $this->acceptProcess($token, $scopes, $signature);
    }

    /** Verify a simulated transport signature before caching. @param list<FinancialScope> $scopes */
    public function acceptProcess(FinancialAdmissionToken $token, array $scopes, string $signature): void
    {
        $this->assertTx0();
        if (! hash_equals(hash_hmac('sha256', serialize([$token, $scopes]), $this->key), $signature)) {
            throw new LogicException('Forged simulated process signature');
        }
        $this->processes[$token->installationRef.'|'.$token->processRef.'|'.$token->nonce] = new VerifiedProcessAdmission(
            $token, 'billium.financial_session.v1', $scopes, 'simulated-host', 'sha256:'.$signature, new DateTimeImmutable,
        );
    }

    public function forgetProcess(FinancialAdmissionToken $token): void
    {
        $this->assertTx0();
        unset($this->processes[$token->installationRef.'|'.$token->processRef.'|'.$token->nonce]);
    }

    /** Explicit fixture boundary: independently issue the currently selected token before a public financial call. */
    public static function admitCurrent(Application $app): void
    {
        $authority = self::install($app);
        $caller = $app->make(CallerContext::class)->current();
        $token = $app->make(FinancialSessionAdmission::class)->currentToken($caller->installationRef);
        if ($token !== null) {
            $authority->issueProcess($token);
        }
    }

    /** @param list<int|string> $args */
    public function issueTransition(string $action, string $ref, array $args, ?Caller $caller = null): VerifiedSessionAuthorization
    {
        $this->assertTx0();
        $this->acquisitions++;
        $caller ??= $this->app->make(CallerContext::class)->current();
        $digest = 'sha256:'.hash('sha256', json_encode([$action, $caller->installationRef, $caller->actorRef,
            $caller->sellerRef, $caller->environment, $caller->sourceAuthorities, ...$args,
            ...($caller->intentOwnerNamespaces === [] ? [] : [['intentOwnerNamespaces.v1' => $caller->intentOwnerNamespaces]]),
        ], JSON_THROW_ON_ERROR));
        $key = $caller->installationRef.'|'.$ref;
        if (isset($this->transitions[$key])) {
            if ($this->transitions[$key]->requestDigest !== $digest) {
                throw new LogicException('A simulated authority transition reference cannot be rebound');
            }

            return $this->transitions[$key];
        }
        $prior = $this->owners[$caller->installationRef] ?? null;
        if ($action === 'open') {
            $owner = new OwnerSessionIdentity($caller->installationRef, $args[0], $args[1], $args[2], $args[3]);
            $retirement = $this->retirement($prior['owner'] ?? $owner, $ref);
            $epoch = ($prior['epoch'] ?? 0) + 1;
            $evidence = $args[4];
            $this->owners[$caller->installationRef] = ['owner' => $owner, 'epoch' => $epoch, 'retirement' => $retirement];
        } else {
            if ($prior === null) {
                throw new LogicException('No independently adopted fixture owner');
            }
            $owner = $prior['owner'];
            $retirement = $prior['retirement'];
            $epoch = $prior['epoch'];
            $evidence = $action === 'activate' ? $args[3] : 'simulated:'.$ref;
        }
        $nonce = bin2hex(random_bytes(16));
        $expires = new DateTimeImmutable('+1 hour');
        $certificate = null;
        if ($action === 'activate') {
            $coverage = new RecoveryCoverage('simulated-backup', new DateTimeImmutable('-1 day'), [],
                $this->sign('complete-empty-dispatch'), $this->sign('complete-source-journal'), $this->sign('complete-tombstones'),
                ['simulated-projection-validation'], ['simulated-downstream-validation'], [],
                [new NamespaceDisposition(new FinancialScope($caller->installationRef, null, null, null), 'active', $this->sign('complete-ledger-only-coverage'))]);
            $certificate = new ActivationCertificate($args[1], $owner, $args[0], $retirement->signatureDigest,
                $coverage, $caller->actorRef, 'activate', 'explicit simulated reconciliation', $expires, $nonce, $this->sign($ref.'|certificate'));
        }
        $authorization = new VerifiedSessionAuthorization($ref, $action, $owner, $prior['epoch'] ?? null, $digest, $evidence,
            $certificate, $retirement, $action === 'activate' ? $args[2] : 'simulated-admission:'.$ref,
            'simulated-host', 'billium.financial_session_control.v1', $expires, $nonce, $this->sign($digest));
        $this->transitions[$key] = $authorization;
        $payload = base64_encode(serialize([$this->transitions, $this->owners]));
        putenv('BILLIUM_SIMULATED_AUTHORITY_JOURNAL='.hash_hmac('sha256', $payload, $this->key).':'.$payload);

        return $authorization;
    }

    private function retirement(OwnerSessionIdentity $old, string $ref): RetirementProof
    {
        return new RetirementProof('simulated-retirement:'.$ref, $old, $this->sign('producers-stopped'),
            $this->sign('workers-terminated'), $this->sign('egress-revoked'), $this->sign('complete-empty-inflight'),
            'simulated-host', new DateTimeImmutable, $this->sign($ref.'|retirement'));
    }

    private function sign(string $value): string
    {
        return 'sha256:'.hash_hmac('sha256', $value, $this->key);
    }

    private function assertTx0(): void
    {
        foreach ($this->app->make(DatabaseManager::class)->getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0) {
                throw new LogicException('Simulated authority acquisition/verification must run before the transaction');
            }
        }
    }
}

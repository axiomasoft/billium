<?php

declare(strict_types=1);

namespace Billium\Server\Tests\Integration;

use Billium\Contracts\Data\Money;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\ExecutionOriginResolver;
use Billium\Execution\Contracts\ExecutionReadiness;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Server\Contracts\Authenticator;
use Billium\Server\Internal\Operations\ServerReadinessCheck;
use Billium\Server\Tests\Support\MinimalHost;
use BilliumMinimalHost\Database;
use BilliumMinimalHost\Host;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use LogicException;
use Packman\Doctor\Data\DoctorContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('pg')]
#[Group('server')]
final class ReadinessTest extends TestCase
{
    private Host $host;

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        MinimalHost::load();
        $this->host = new Host('readiness-test-secret');
        $this->database = Database::create();
        SimulatedFinancialAuthorityEvidence::reset();
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            Database::drop($this->database);
        }
        SimulatedFinancialAuthorityEvidence::reset();
        parent::tearDown();
    }

    public function test_readiness_requires_host_authority_for_each_worker_and_rejects_copied_active_state(): void
    {
        $app = $this->application();
        self::assertSame(200, $this->host->request($app, '/billium/live')->getStatusCode());
        self::assertSame([], $app->make(DatabaseManager::class)->getConnections());
        $this->assertReadiness($app, 'billium.server.modules_incompatible');
        Host::migrate($app, ['platform', 'ledger']);
        $this->assertReadiness($app, 'billium.server.session_quarantined');
        $installation = $this->activate($app);
        $app->make(Ledger::class)->recordObligation(new RecordObligation(
            'restore-fixture-command',
            new BillingAccountScope(Host::SELLER, 'test', 'restore-fixture-account'),
            'restore-fixture-obligation',
            'restore-fixture-basis',
            'restore-fixture-customer',
            'restore-fixture-component',
            Money::parse('10.00', 'RUB'),
            'sha256:'.str_repeat('a', 64),
        ))->orThrow();

        for ($i = 1; $i <= 3; $i++) {
            $worker = $this->application();
            $worker->make(Kernel::class)->bootstrap();
            $token = FixedSessionAdmission::token(Host::INSTALLATION, processRef: 'restarted-worker-'.$i);
            $worker->instance(FinancialSessionAdmission::class, new FixedSessionAdmission($token));
            $this->assertReadiness($worker, 'billium.server.session_quarantined');
            SimulatedFinancialAuthorityEvidence::install($worker)->issueProcess($token, [new FinancialScope(Host::INSTALLATION, Host::SELLER, 'test', null)]);
            $this->assertReadiness($worker, null);
            self::assertSame(1, $worker->make(Installation::class)->currentSession(Host::INSTALLATION)?->epoch);
            $worker->make(DatabaseManager::class)->disconnect('server_runtime');
        }
        self::assertSame('active', $installation->currentSession(Host::INSTALLATION)?->state);

        $manager = $app->make(DatabaseManager::class);
        $manager->disconnect('server_owner');
        $manager->disconnect('server_runtime');
        $control = $manager->build(Database::connection(getenv('DB_DATABASE') ?: 'billium_test', owner: true));
        $copy = 'billium_server_'.bin2hex(random_bytes(8)).'_test';
        $control->statement('CREATE DATABASE "'.$copy.'" TEMPLATE "'.$this->database.'"');
        try {
            $clone = $this->application(database: $copy);
            $this->assertReadiness($clone, 'billium.server.session_quarantined');
            self::assertSame('active', $clone->make(Installation::class)->currentSession(Host::INSTALLATION)?->state);
            $query = json_encode([
                'schema' => 'billium.query.v1', 'installationRef' => Host::INSTALLATION, 'environment' => 'test',
                'sellerRef' => Host::SELLER, 'query' => 'obligation', 'selector' => ['obligationRef' => 'restore-fixture-obligation'],
            ], JSON_THROW_ON_ERROR);
            $history = $this->host->request($clone, '/billium/v1/sellers/'.Host::SELLER.'/queries', $query);
            self::assertSame(200, $history->getStatusCode());
            $body = json_decode((string) $history->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['amount' => '10.00', 'currency' => 'RUB'], $body['result']['originalDue']);
            $token = FixedSessionAdmission::token(Host::INSTALLATION, attachmentGeneration: 2);
            $clone->instance(FinancialSessionAdmission::class, new FixedSessionAdmission($token));
            SimulatedFinancialAuthorityEvidence::install($clone)->issueProcess($token);
            $this->assertReadiness($clone, 'billium.server.session_quarantined');

            $console = $clone->make(Kernel::class);
            self::assertSame(0, $console->call('billium:server:metrics'));
            $metrics = json_decode($console->output(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(1, $metrics['session_quarantined']);
            self::assertSame(0, $metrics['parked_custody']);
            self::assertArrayNotHasKey('attempts_unknown', $metrics);
            self::assertStringNotContainsString(Host::INSTALLATION, $console->output());
        } finally {
            Database::drop($copy);
        }
    }

    public function test_remote_readiness_refuses_missing_ports_and_never_enables_outgoing(): void
    {
        $app = $this->application('remote-billium');
        foreach ([AdapterQualification::class, ExecutionOriginResolver::class, MerchantCredentials::class, ProviderAbsenceVerifier::class] as $port) {
            $app->instance($port, $this->createStub($port));
        }
        $driver = $this->createMock(PaymentDrivers::class);
        $driver->expects(self::never())->method('resolve');
        $app->instance(PaymentDrivers::class, $driver);
        $egress = $this->createMock(DispatchEgress::class);
        $egress->expects(self::never())->method('invoke');
        $app->instance(DispatchEgress::class, $egress);
        $policy = $this->createMock(ExecutionReadiness::class);
        $policy->expects(self::never())->method('assertConfigured');
        $app->instance(ExecutionReadiness::class, $policy);
        $this->host->request($app, '/billium/live');
        Host::migrate($app, ['platform', 'ledger', 'execution']);
        $this->activate($app);

        $app->forgetInstance(Authenticator::class);
        $this->assertReadiness($app, 'billium.server.auth_unavailable');
        $app->instance(Authenticator::class, $this->host);
        $this->assertReadiness($app, 'billium.server.execution_ports_unavailable');
        $writer = $this->createMock(WriterAdmission::class);
        $writer->expects(self::never())->method('ticket');
        $app->instance(WriterAdmission::class, $writer);
        $this->assertReadiness($app, 'billium.server.outgoing_disabled');
        $app->forgetInstance(PaymentDrivers::class);
        $this->assertReadiness($app, 'billium.server.execution_ports_unavailable');

        $console = $app->make(Kernel::class);
        self::assertSame(0, $console->call('billium:server:metrics'));
        $metrics = json_decode($console->output(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $metrics['accepted_unknown']);
        self::assertSame(0, $metrics['attempts_unknown']);
    }

    public function test_owner_connection_is_refused_even_with_complete_schema(): void
    {
        $app = $this->application();
        $this->host->request($app, '/billium/live');
        Host::migrate($app, ['platform', 'ledger']);
        $owner = $this->application();
        $owner->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
            $app['config']->set('billium.connection', 'server_owner');
        });
        $this->assertReadiness($owner, 'billium.server.database_role_invalid');
    }

    public function test_configured_execution_probe_rechecks_the_host_dossier_without_dispatch(): void
    {
        $app = $this->application('remote-billium');
        $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
            $app['config']->set('billium.execution', [
                'outgoing_enabled' => true,
                'release_evidence_ref' => 'fixture-release',
                'retention_policy_ref' => 'fixture-retention',
            ]);
        });
        foreach ([AdapterQualification::class, DispatchEgress::class, ExecutionOriginResolver::class,
            MerchantCredentials::class, PaymentDrivers::class, ProviderAbsenceVerifier::class] as $port) {
            $app->instance($port, $this->createStub($port));
        }
        $writer = $this->createMock(WriterAdmission::class);
        $writer->expects(self::never())->method('ticket');
        $app->instance(WriterAdmission::class, $writer);
        $policy = $this->createMock(ExecutionReadiness::class);
        $accepted = true;
        $policy->expects(self::atLeastOnce())->method('assertConfigured')->with('fixture-release', 'fixture-retention')
            ->willReturnCallback(static function () use (&$accepted): void {
                if (! $accepted) {
                    throw new LogicException('private merchant credentials in host exception');
                }
            });
        $app->instance(ExecutionReadiness::class, $policy);
        $this->host->request($app, '/billium/live');
        Host::migrate($app, ['platform', 'ledger', 'execution']);
        $this->activate($app);
        $this->assertReadiness($app, null);
        $accepted = false;
        $this->assertReadiness($app, 'billium.server.execution_unqualified');
    }

    private function application(string $profile = 'ledger-only', ?string $database = null): Application
    {
        $modules = $profile === 'remote-billium' ? ['platform', 'ledger', 'execution'] : ['platform', 'ledger'];

        return MinimalHost::application($this->host, $database ?? $this->database, $profile, $modules);
    }

    private function activate(Application $app): Installation
    {
        $app->instance(CallerContext::class, new FixedCallerContext(new Caller(Host::SELLER, 'test', Host::INSTALLATION, 'readiness-operator', ['embedded-commerz'])));
        $token = FixedSessionAdmission::token(Host::INSTALLATION);
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission($token));
        $authority = SimulatedFinancialAuthorityEvidence::install($app);
        $installation = $app->make(Installation::class);
        $installation->init(Host::INSTALLATION);
        $open = ['owner-1', 1, 1, 1, 'restore-barrier'];
        $authority->issueTransition('open', 'open', $open);
        $installation->openSession('open', ...$open);
        $activation = [1, 'certificate', 'admission', 'reconciled'];
        $authority->issueTransition('activate', 'activate', $activation);
        $installation->activateSession('activate', ...$activation);
        $authority->issueProcess($token);

        return $installation;
    }

    private function assertReadiness(Application $app, ?string $failure): void
    {
        $response = $this->host->request($app, '/billium/ready');
        $findings = $app->make(ServerReadinessCheck::class)->run(new DoctorContext);
        self::assertCount(1, $findings);
        self::assertSame($failure ?? ServerReadinessCheck::KEY, $findings[0]->code);
        self::assertSame([], $findings[0]->evidence);
        self::assertSame($failure === null ? 200 : 503, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }
}

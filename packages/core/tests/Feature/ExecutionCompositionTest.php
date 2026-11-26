<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Contracts\Runtime\AuthenticatedObservationContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Contracts\Runtime\IncomingCommandAuthenticator;
use Billium\Core\Contracts\Runtime\QualifiedFactualCommandPublisher;
use Billium\Core\Diagnostics\BilliumCompatibilityDoctorCheck;
use Billium\Core\Tests\Support\CoreBootTestCase;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\ExecutionOriginResolver;
use Billium\Execution\Contracts\ExecutionQueries;
use Billium\Execution\Contracts\ExecutionReadiness;
use Billium\Execution\Contracts\MerchantActionGate;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\PaymentObservationSink;
use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Internal\Publication\PlatformPaymentObservationSink;
use Billium\Execution\Laravel\ExecutionServiceProvider;
use Billium\Ledger\Contracts\Ledger;
use Billium\Platform\Contracts\Inbox\AuthenticatedObservationBootstrap;
use Billium\Platform\Contracts\Inbox\ObservationBootstrapAuthenticator;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Override;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Packman\Doctor\Enums\CheckStatus;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

final class ExecutionCompositionTest extends CoreBootTestCase
{
    private bool $forbiddenProbe = false;

    private ?string $outgoingProbe = null;

    private ?ExecutionReadiness $startupVerifier = null;

    private ?Application $startupApplication = null;

    #[Override]
    protected function getPackageProviders($app): array
    {
        [$profile, $execution] = $this->providedData();
        self::assertIsString($profile);
        self::assertIsBool($execution);
        $config = $app->make(Repository::class);
        $config->set('billium.modules.composition', $profile);
        $config->set('billium.modules.enabled', $execution || $this->forbiddenProbe ? ['platform', 'ledger', 'execution'] : ['platform', 'ledger']);
        if ($this->outgoingProbe !== null) {
            $this->startupApplication = $app;
            $config->set('billium.execution.outgoing_enabled', true);
            $config->set('billium.execution.release_evidence_ref', $this->outgoingProbe === 'release' ? null : 'fixture-release');
            $config->set('billium.execution.retention_policy_ref', $this->outgoingProbe === 'retention' ? null : 'fixture-retention');
        }
        if ($this->startupVerifier !== null) {
            $app->instance(ExecutionReadiness::class, $this->startupVerifier);
        } else {
            $app->beforeResolving(ExecutionReadiness::class, static function (): never {
                self::fail('Boot or doctor tried to resolve an unconfigured readiness verifier');
            });
        }

        // A probe binds neither an outgoing writer nor a provider implementation.
        foreach ([PaymentDrivers::class, DispatchEgress::class, MerchantCredentials::class, WriterAdmission::class,
            QualifiedFactualCommandPublisher::class, IncomingCommandAuthenticator::class, ObservationBootstrapAuthenticator::class] as $port) {
            $app->beforeResolving($port, static function () use ($port): never {
                self::fail('Boot or doctor tried to resolve '.$port);
            });
        }

        return [BilliumCoreServiceProvider::class];
    }

    /** @return array<string, array{string, bool}> */
    public static function offlineProfiles(): array
    {
        return [
            'embedded Commerz' => ['embedded-commerz', false],
            'ledger only' => ['ledger-only', false],
            'standalone with execution off' => ['standalone-billium', false],
        ];
    }

    /** @return array<string, array{string, bool}> */
    public static function executionProfiles(): array
    {
        return [
            'embedded standalone' => ['standalone-billium', true],
            'server owner' => ['remote-billium', true],
        ];
    }

    /** @return array<string, array{string, bool}> */
    public static function forbiddenExecutionProfiles(): array
    {
        return ['Commerz' => ['embedded-commerz', false], 'ledger' => ['ledger-only', false]];
    }

    /** @return array<string, array{string, bool, string, ?ProblemCode, ?string}> */
    public static function outgoingCases(): array
    {
        return [
            'missing release dossier' => ['remote-billium', true, 'release', null, 'billium.execution.release_evidence_ref'],
            'missing retention dossier' => ['remote-billium', true, 'retention', null, 'billium.execution.retention_policy_ref'],
            'missing host verifier' => ['remote-billium', true, 'verifier', null, 'requires ExecutionReadiness'],
            'unqualified adapter' => ['remote-billium', true, 'qualification', ProblemCode::ValidationInvalid, ProblemCode::ValidationInvalid->value],
            'unverified authority' => ['remote-billium', true, 'authority', ProblemCode::AuthDenied, ProblemCode::AuthDenied->value],
            'unverified hard fence' => ['remote-billium', true, 'fence', ProblemCode::RecoveryRequired, ProblemCode::RecoveryRequired->value],
            'explicitly approved fixture' => ['standalone-billium', true, 'approved', null, null],
        ];
    }

    #[DataProvider('outgoingCases')]
    public function test_outgoing_startup_requires_host_verified_dossiers_before_registering_execution(string $profile, bool $enabled, string $probe, ?ProblemCode $rejection, ?string $diagnostic): void
    {
        self::assertTrue($enabled);
        $this->outgoingProbe = $probe;
        if ($probe !== 'verifier') {
            $verifier = $this->createMock(ExecutionReadiness::class);
            $verifier->expects(self::never())->method('assertReady');
            if (in_array($probe, ['release', 'retention'], true)) {
                $verifier->expects(self::never())->method('assertConfigured');
            } else {
                $verifier->expects(self::once())->method('assertConfigured')->with('fixture-release', 'fixture-retention')
                    ->willReturnCallback(static function () use ($rejection, $probe): void {
                        if ($rejection !== null) {
                            throw new ProblemException($rejection, 'unverified fixture '.$probe);
                        }
                    });
            }
            $this->startupVerifier = $verifier;
        }

        try {
            $this->refreshApplication();
            self::assertNull($diagnostic, 'Outgoing startup accepted unverified configuration');
        } catch (InvalidConfiguration $failure) {
            self::assertNotNull($diagnostic);
            self::assertStringContainsString($diagnostic, $failure->getMessage());
        }
        $app = $this->startupApplication ?? self::fail('The fresh application was not observed');
        self::assertSame($profile, $app->make(Repository::class)->get('billium.modules.composition'));
        self::assertSame($diagnostic === null, $app->bound(RootPaymentCommands::class));
        self::assertCount($diagnostic === null ? 1 : 0, $app->getProviders(ExecutionServiceProvider::class));
        $this->assertNoOpenDatabaseConnection($app);
    }

    #[DataProvider('forbiddenExecutionProfiles')]
    public function test_forbidden_execution_composition_refuses_before_registering_payment_services(string $profile, bool $execution): void
    {
        self::assertFalse($execution);
        $this->forbiddenProbe = true;
        try {
            $this->refreshApplication();
            self::fail('Forbidden execution profile was accepted');
        } catch (InvalidConfiguration $error) {
            self::assertStringContainsString('billium.modules.composition', $error->getMessage());
            self::assertStringContainsString('execution requires', $error->getMessage());
        }
        $app = $this->application();
        self::assertSame($profile, $app->make(Repository::class)->get('billium.modules.composition'));
        self::assertSame([], $app->getProviders(ExecutionServiceProvider::class));
        self::assertFalse($app->bound(RootPaymentCommands::class));
        $this->assertNoOpenDatabaseConnection();
    }

    #[DataProvider('offlineProfiles')]
    public function test_ledger_compositions_boot_without_provider_services(string $profile, bool $execution): void
    {
        $app = $this->application();
        self::assertFalse($execution);
        self::assertSame($profile, $app->make(Repository::class)->get('billium.modules.composition'));
        self::assertSame([], $app->getProviders(ExecutionServiceProvider::class));
        foreach ([WriterAdmission::class, RootPaymentCommands::class, AttemptWorker::class, CallbackCustody::class, ExecutionQueries::class] as $contract) {
            self::assertFalse($app->bound($contract), $contract);
        }
        self::assertInstanceOf(Ledger::class, $app->make(Ledger::class));
        $fixture = file_get_contents(dirname(__DIR__, 4).'/fixtures/vectors/payment/execute/execute-valid.json');
        self::assertIsString($fixture);
        /** @var array{script: list<array{args: array{json_text: string}}> } $vector */
        $vector = json_decode($fixture, true, flags: JSON_THROW_ON_ERROR);
        $codec = new Codec;
        $envelope = $codec->decode($vector['script'][0]['args']['json_text']);
        self::assertInstanceOf(ExecutePayment::class, $envelope);
        self::assertSame($codec->encode($envelope), $codec->encode($codec->decode($codec->encode($envelope))));

        $missing = $this->doctor();
        $this->assertMissingPort($missing, 'FinancialSessionAdmission', 'ledger');
        $this->assertMissingPort($missing, 'FinancialAuthorityEvidence', 'platform');
        $this->bindFinancialPorts();
        $findings = $this->doctor();
        self::assertCount(1, $findings);
        self::assertSame(CheckStatus::Ok, $findings[0]->status);
        $this->assertNoOpenDatabaseConnection();
    }

    #[DataProvider('executionProfiles')]
    public function test_execution_readiness_names_each_missing_host_port_without_loading_the_host(string $profile, bool $execution): void
    {
        $app = $this->application();
        self::assertTrue($execution);
        self::assertSame($profile, $app->make(Repository::class)->get('billium.modules.composition'));
        $findings = $this->doctor();
        $this->assertMissingPort($findings, 'FinancialSessionAdmission', 'ledger');
        $this->assertMissingPort($findings, 'FinancialAuthorityEvidence', 'platform');
        foreach ([PaymentDrivers::class, WriterAdmission::class, DispatchEgress::class, MerchantCredentials::class,
            ProviderAbsenceVerifier::class, ExecutionOriginResolver::class, AdapterQualification::class, ExecutionReadiness::class] as $contract) {
            self::assertFalse($app->bound($contract), $contract);
            $this->assertMissingPort($findings, class_basename($contract), 'execution');
        }
        $messages = implode("\n", array_map(static fn (DoctorFinding $finding): string => $finding->message, $findings));
        self::assertStringNotContainsString('PaymentObservationSink', $messages);
        self::assertStringNotContainsString('CallbackPayloadArchive', $messages);
        self::assertStringNotContainsString('CollectionExecutionGuard', $messages);
        self::assertStringNotContainsString('CollectionConsentGuard', $messages);
        self::assertInstanceOf(PlatformPaymentObservationSink::class, $app->make(PaymentObservationSink::class));
        self::assertSame($app->make(MerchantRegistry::class), $app->make(MerchantActionGate::class));
        self::assertSame($app->make(AuthenticatedObservationContext::class), $app->make(AuthenticatedObservationContext::class));
        self::assertSame($app->make(AuthenticatedObservationBootstrap::class), $app->make(AuthenticatedObservationBootstrap::class));
        $this->assertNoOpenDatabaseConnection();
    }

    private function application(): Application
    {
        return $this->app ?? self::fail('Testbench application is not booted');
    }

    private function bindFinancialPorts(): void
    {
        $app = $this->application();
        $app->instance(CallerContext::class, FixedCallerContext::default());
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission);
        $app->instance(FinancialAuthorityEvidence::class, self::createStub(FinancialAuthorityEvidence::class));
    }

    private function assertNoOpenDatabaseConnection(?Application $app = null): void
    {
        foreach (($app ?? $this->application())->make(DatabaseManager::class)->getConnections() as $connection) {
            self::assertNotInstanceOf(PDO::class, $connection->getRawPdo());
            self::assertNotInstanceOf(PDO::class, $connection->getRawReadPdo());
        }
    }

    /** @return list<DoctorFinding> */
    private function doctor(): array
    {
        return $this->application()->make(BilliumCompatibilityDoctorCheck::class)->run(new DoctorContext(production: false));
    }

    /** @param list<DoctorFinding> $findings */
    private function assertMissingPort(array $findings, string $port, string $module): void
    {
        $matches = array_values(array_filter($findings, static fn (DoctorFinding $finding): bool => $finding->code === 'billium.modules.module.port_missing'
            && $finding->slice === $module && str_contains($finding->message, $port)));
        self::assertCount(1, $matches, $port);
        self::assertSame(CheckStatus::Error, $matches[0]->status, $port);
        self::assertNotEmpty($matches[0]->remediation, $port);
    }
}

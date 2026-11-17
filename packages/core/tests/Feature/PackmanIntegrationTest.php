<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Errors\BilliumErrorCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Diagnostics\BilliumCompatibilityDoctorCheck;
use Billium\Core\Errors\BilliumCoreErrorCode;
use Billium\Core\Exceptions\Modules\ModuleAdmissionRefused;
use Billium\Core\Exceptions\Runner\LockOrderViolation;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Platform\Exceptions\TransitionRefused;
use Override;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigSectionRegistration;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Enums\CheckStatus;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Packman\Testkit\PackageTestCase;

final class PackmanIntegrationTest extends PackageTestCase
{
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [BilliumCoreServiceProvider::class];
    }

    public function test_problem_registry_registers_contracts_and_core_error_codes(): void
    {
        $registry = $this->app->make(ProblemRegistry::class);

        $this->assertAllErrorCodesRegistered($registry, BilliumErrorCode::class);
        $this->assertAllErrorCodesRegistered($registry, BilliumCoreErrorCode::class);

        $this->assertCodedExceptionRegistered($registry, new ProblemException(ProblemCode::ValidationInvalid, 'bad field'), 422);
        $this->assertCodedExceptionRegistered($registry, new ProblemException(ProblemCode::ReferenceUnknown, 'missing'), 404);
        $registry->assertOccurrence((new LockOrderViolation('out of order'))->errorDescriptor(), 500);
        $this->assertCodedExceptionRegistered($registry, new LockPlanChanged('changed'), 409);
        $this->assertCodedExceptionRegistered($registry, new ModuleAdmissionRefused('ledger', 'retired'), 503);
        $this->assertCodedExceptionRegistered($registry, new TransitionRefused('blocked', ['pending_operations']), 409);
    }

    public function test_config_section_ownership_enforces_closed_billium_schema(): void
    {
        $billium = config('billium');
        self::assertIsArray($billium);

        ConfigSectionRegistration::assertKnownKeys($this->app, ['billium' => $billium]);

        try {
            ConfigSectionRegistration::assertKnownKeys($this->app, ['billium' => ['runnr' => []]]);
            self::fail('Unknown key inside billium section must fail');
        } catch (InvalidConfiguration $exception) {
            self::assertStringContainsString('did you mean "runner"', $exception->getMessage());
        }

        $this->expectException(InvalidConfiguration::class);
        ConfigSectionRegistration::assertKnownKeys($this->app, ['unknown_root' => []]);
    }

    public function test_doctor_check_reports_compatibility_and_configuration_readiness(): void
    {
        $this->app->instance(CallerContext::class, FixedCallerContext::default());
        $this->app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token('inst-test')));

        $check = $this->app->make(BilliumCompatibilityDoctorCheck::class);
        $ok = $check->run(new DoctorContext(production: false));

        self::assertCount(1, $ok);
        self::assertSame(CheckStatus::Ok, $ok[0]->status);
        self::assertSame('billium.modules.compatibility', $ok[0]->code);

        config(['billium.modules.composition' => 'nonexistent-composition']);
        $bad = $check->run(new DoctorContext(production: true));
        self::assertNotEmpty($bad);
        self::assertSame(CheckStatus::Error, $bad[0]->status);
    }

    public function test_schema_sets_declare_platform_and_ledger_central_streams(): void
    {
        $sets = $this->app->make(SchemaSets::class);

        $platform = $sets->get('axiomasoft/billium-core:billium-platform');
        $ledger = $sets->get('axiomasoft/billium-core:billium-ledger');

        self::assertSame(SchemaScope::Central, $platform->scope);
        self::assertSame(SchemaLifecycle::Platform, $platform->lifecycle);
        self::assertSame(['pgsql'], $platform->drivers);
        self::assertSame(SchemaScope::Central, $ledger->scope);
        self::assertSame(SchemaLifecycle::Platform, $ledger->lifecycle);
        self::assertSame(['pgsql'], $ledger->drivers);
    }
}

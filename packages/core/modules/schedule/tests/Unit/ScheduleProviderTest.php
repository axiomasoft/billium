<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Unit;

use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Internal\Modules\ModuleCatalog;
use Billium\Schedule\Contracts\AgreementRevisionCommit;
use Billium\Schedule\Contracts\AgreementRevisionGuard;
use Billium\Schedule\Contracts\CommercialPeriods;
use Billium\Schedule\Contracts\IntervalResolver;
use Billium\Schedule\Internal\ScheduleServiceProvider;
use Billium\Schedule\Internal\UnboundAgreementRevisionGuard;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class ScheduleProviderTest extends TestCase
{
    public function test_disabled_schedule_does_not_enroll_provider_or_resolve_ports(): void
    {
        $app = new Application(dirname(__DIR__, 6));
        $app->instance('config', new Repository);
        $app->register(BilliumCoreServiceProvider::class);
        self::assertFalse($app->bound(IntervalResolver::class));
        self::assertFalse($app->bound(AgreementRevisionCommit::class));
        self::assertArrayNotHasKey(ScheduleServiceProvider::class, $app->getLoadedProviders());
        self::assertFalse($app['config']->get('billium.schedule.enabled'));
        Container::setInstance(null);
    }

    public function test_enabled_available_provider_binds_ports_without_installing_or_booting(): void
    {
        $app = new Application(dirname(__DIR__, 6));
        $app->instance('config', new Repository(['billium' => [
            'modules' => ['enabled' => ['platform', 'ledger', 'rating', 'schedule']], 'schedule' => ['enabled' => true],
        ]]));
        $app->register(BilliumCoreServiceProvider::class);
        foreach ([IntervalResolver::class, AgreementRevisionCommit::class, CommercialPeriods::class] as $contract) {
            self::assertTrue($app->bound($contract));
        }
        self::assertInstanceOf(UnboundAgreementRevisionGuard::class, $app->make(AgreementRevisionGuard::class));
        self::assertArrayHasKey(ScheduleServiceProvider::class, $app->getLoadedProviders());
        self::assertFalse($app->isBooted());
        $descriptor = ModuleCatalog::packaged()->descriptor('schedule');
        self::assertSame(1, $descriptor->currentSchemaVersion);
        self::assertCount(4, $descriptor->ownedTables);
        self::assertCount(1, $descriptor->migrationIdentities);
        self::assertSame(0, ModuleCatalog::packaged()->descriptor('rating')->currentSchemaVersion);
        Container::setInstance(null);
    }
}

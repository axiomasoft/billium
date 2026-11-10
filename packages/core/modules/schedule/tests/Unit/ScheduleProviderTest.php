<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Unit;

use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Internal\Modules\ModuleCatalog;
use Billium\Schedule\Contracts\AgreementRevisionCommit;
use Billium\Schedule\Contracts\AgreementRevisionGuard;
use Billium\Schedule\Contracts\CommercialPeriods;
use Billium\Schedule\Contracts\CorrectionParticipants;
use Billium\Schedule\Contracts\IntervalResolver;
use Billium\Schedule\Contracts\OccurrenceLocks;
use Billium\Schedule\Contracts\OccurrenceParticipants;
use Billium\Schedule\Contracts\OccurrenceService;
use Billium\Schedule\Contracts\ScheduleQueries;
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
        self::assertFalse($app->make(\Illuminate\Contracts\Config\Repository::class)->get('billium.schedule.enabled'));
        Container::setInstance(null);
    }

    public function test_enabled_available_provider_binds_ports_without_installing_or_booting(): void
    {
        $app = new Application(dirname(__DIR__, 6));
        $app->instance('config', new Repository(['billium' => ['modules' => ['enabled' => ['platform', 'ledger', 'rating', 'schedule']], 'schedule' => ['enabled' => true]]]));
        $app->register(BilliumCoreServiceProvider::class);
        foreach ([IntervalResolver::class, AgreementRevisionCommit::class, CommercialPeriods::class, OccurrenceService::class, OccurrenceParticipants::class, CorrectionParticipants::class, OccurrenceLocks::class, ScheduleQueries::class] as $contract) {
            self::assertTrue($app->bound($contract));
        }
        self::assertInstanceOf(UnboundAgreementRevisionGuard::class, $app->make(AgreementRevisionGuard::class));
        self::assertArrayHasKey(ScheduleServiceProvider::class, $app->getLoadedProviders());
        self::assertFalse($app->isBooted());
        $descriptor = ModuleCatalog::packaged()->descriptor('schedule');
        self::assertNotNull($descriptor);
        self::assertSame(3, $descriptor->currentSchemaVersion);
        self::assertCount(11, $descriptor->ownedTables);
        self::assertCount(3, $descriptor->migrationIdentities);
        $rating = ModuleCatalog::packaged()->descriptor('rating');
        self::assertNotNull($rating);
        self::assertSame(2, $rating->currentSchemaVersion);
        Container::setInstance(null);
    }
}

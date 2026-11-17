<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit\Modules;

use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Enums\Modules\CompatibilityFindingCode as Code;
use Billium\Rating\Contracts\RatingQuoter;
use Billium\Schedule\Internal\ScheduleServiceProvider;
use Billium\Usage\Contracts\Usage;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;

final class P4ModuleCompositionTest extends ModuleTestCase
{
    public function test_a_through_d_preserve_authority_and_never_fall_back_to_a_local_executor(): void
    {
        $cases = [
            'embedded-commerz' => [['platform', 'ledger'], self::LEDGER_PORTS],
            'ledger-only' => [['platform', 'ledger', 'rating', 'schedule'], self::LEDGER_PORTS],
            'standalone-billium' => [['platform', 'ledger', 'rating', 'schedule', 'execution'], [...self::LEDGER_PORTS, ...self::EXECUTION_PORTS]],
            'remote-billium' => [['platform', 'ledger', 'execution'], [...self::LEDGER_PORTS, ...self::EXECUTION_PORTS]],
        ];
        foreach ($cases as $profile => [$modules, $ports]) {
            $report = self::catalog()->check(self::input(composition: $profile, enabled: $modules, ports: $ports));
            self::assertTrue($report->isReady(), $profile.': '.json_encode($report->findings, JSON_THROW_ON_ERROR));
        }
        self::assertTrue(self::catalog()->check(self::input(composition: 'embedded-commerz', enabled: ['platform', 'ledger', 'execution']))->has(Code::CompositionForbidden, 'execution'));
        self::assertTrue(self::catalog()->check(self::input(composition: 'ledger-only', enabled: ['platform', 'ledger'], ports: [...self::LEDGER_PORTS, 'WriterAdmission']))->has(Code::PortForbidden));
        self::assertTrue(self::catalog()->check(self::input(composition: 'remote-billium', enabled: ['platform', 'ledger']))->has(Code::CompositionRequired, 'execution'));
        self::assertTrue(self::catalog()->check(self::input(composition: 'remote-billium', enabled: ['platform', 'ledger', 'execution'], ports: [...self::LEDGER_PORTS, 'PaymentDrivers']))->has(Code::PortMissing, 'execution'));
    }

    public function test_optional_modules_need_both_host_enable_signals_and_quote_needs_no_database(): void
    {
        $app = new Application(dirname(__DIR__, 5));
        try {
            $app->instance('config', new Repository(['billium' => ['modules' => ['enabled' => ['platform', 'rating', 'usage']],
                'rating' => ['enabled' => true], 'usage' => ['enabled' => false], 'schedule' => ['enabled' => true]]]));
            $app->register(BilliumCoreServiceProvider::class);
            self::assertFalse($app->bound(Usage::class));
            self::assertArrayNotHasKey(ScheduleServiceProvider::class, $app->getLoadedProviders());
            self::assertInstanceOf(RatingQuoter::class, $app->make(RatingQuoter::class));
        } finally {
            Container::setInstance(null);
        }
    }
}

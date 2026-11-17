<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Integration\UsageMigration;

use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\Integration\CompositionTestCase;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Usage\Contracts\Usage;
use Billium\Usage\Contracts\UsageAttribution;
use Billium\Usage\Tests\Support\UsageFixtures;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class P4ModuleCompositionTest extends CompositionTestCase
{
    protected function modules(): array
    {
        return ['platform', 'usage'];
    }

    public function test_usage_alone_boots_migrates_and_accepts_facts_without_commercial_or_ledger_tables(): void
    {
        $this->application()->instance(CallerContext::class, new FixedCallerContext(new Caller(sellerRef: 'seller-a', environment: 'test',
            installationRef: 'inst-test', actorRef: 'test-host', sourceAuthorities: ['tariffler'])));
        $this->application()->instance(UsageAttribution::class, new UsageFixtures);
        $usage = $this->application()->make(Usage::class);
        $usage->registerMetric(UsageFixtures::metric());
        $usage->submitUsageFact(UsageFixtures::fact());
        self::assertSame(1, $this->owner()->table('billium_usage_fact')->count());
        foreach (['billium_rating_run', 'billium_schedule_billing_occurrence', 'billium_obligation'] as $table) {
            self::assertNull($this->owner()->scalar("SELECT to_regclass('public.{$table}')::text"));
        }
        self::assertSame(['platform', 'usage'], $this->owner()->table('billium_module_state')->orderBy('module_id')->pluck('module_id')->all());
    }
}

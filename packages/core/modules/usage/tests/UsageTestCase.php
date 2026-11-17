<?php

declare(strict_types=1);

namespace Billium\Usage\Tests;

use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Platform\Tests\PlatformTestCase;
use Billium\Usage\Contracts\Usage;
use Billium\Usage\Contracts\UsageAttribution;
use Billium\Usage\Laravel\UsageServiceProvider;
use Billium\Usage\Tests\Support\UsageFixtures;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;

abstract class UsageTestCase extends PlatformTestCase
{
    protected UsageFixtures $mappings;

    /** @param Application $app */
    protected function getPackageProviders($app): array
    {
        // Testbench applies defineEnvironment after registration; a real host supplies config before discovery.
        return [...parent::getPackageProviders($app), UsageServiceProvider::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->make(Repository::class)->set('billium.modules.enabled', ['platform', 'ledger', 'usage']);
        $app->make(Repository::class)->set('billium.usage.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->callers->set(new Caller(sellerRef: 'seller-a', environment: 'test', installationRef: self::INSTALLATION, actorRef: 'usage-test', sourceAuthorities: ['tariffler']));
        $this->application()->instance(CallerContext::class, $this->callers);
        $this->mappings = new UsageFixtures;
        $this->application()->instance(UsageAttribution::class, $this->mappings);
        $this->installation()->init(self::INSTALLATION);
        $this->usage()->registerMetric(UsageFixtures::metric());
    }

    protected function usage(): Usage
    {
        return $this->application()->make(Usage::class);
    }
}

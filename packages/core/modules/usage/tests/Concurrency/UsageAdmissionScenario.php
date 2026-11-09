<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Concurrency;

use Billium\Core\Contracts\CallerContext;
use Billium\Core\Data\Caller;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Usage\Contracts\Usage;
use Billium\Usage\Contracts\UsageAttribution;
use Billium\Usage\Internal\UsageServiceProvider;
use Billium\Usage\Tests\Support\UsageFixtures;
use Illuminate\Foundation\Application;
use stdClass;

final class UsageAdmissionScenario implements Scenario
{
    /** @param array<string,mixed> $args */
    public function __construct(Application $app, int $index, array $args)
    {
        $app->instance(CallerContext::class, new FixedCallerContext(new Caller(
            sellerRef: 'seller-a', environment: 'test', installationRef: 'inst-test',
            actorRef: 'courier-'.$index, sourceAuthorities: ['tariffler'],
        )));
        $app->register(UsageServiceProvider::class);
        $app->instance(UsageAttribution::class, new UsageFixtures);
    }

    /** @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function run(Application $app, int $index, array $args): array
    {
        $fact = UsageFixtures::fact(function (stdClass $raw) use ($args, $index): void {
            if ($args['conflict'] ?? false) {
                $raw->value->quantity = (string) ($index + 1);
            }
        });
        $receipt = $app->make(Usage::class)->submitUsageFact($fact);

        return ['receipt' => $receipt->receiptRef, 'digest' => $receipt->digest,
            'pid' => $app['db']->connection('pgsql')->scalar('SELECT pg_backend_pid()')];
    }
}

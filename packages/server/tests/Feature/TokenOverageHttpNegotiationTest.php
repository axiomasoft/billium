<?php

declare(strict_types=1);

namespace Billium\Server\Tests\Feature;

use Billium\Server\Contracts\AuthorizationVerdict;
use Billium\Server\Enums\Verdict;
use Billium\Server\Tests\Support\MinimalHost;
use Billium\Usage\Contracts\UsageAttribution;
use Billium\Usage\Tests\Support\UsageFixtures;
use BilliumMinimalHost\Database;
use BilliumMinimalHost\Host;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('pg')]
#[Group('server')]
final class TokenOverageHttpNegotiationTest extends TestCase
{
    public function test_capabilities_v1_is_pinned_and_v2_negotiates_usage_facts_v2(): void
    {
        MinimalHost::load();
        $database = Database::create();
        try {
            $host = new Host('token-http-test');
            $modules = ['platform', 'ledger', 'usage', 'rating', 'schedule'];
            $app = MinimalHost::application($host, $database, 'standalone-billium', $modules);
            $app->instance(UsageAttribution::class, new UsageFixtures);
            $app->instance(AuthorizationVerdict::class, $this->createConfiguredStub(AuthorizationVerdict::class, ['authorize' => Verdict::Allow]));
            $host->request($app, '/billium/live');
            Host::migrate($app, $modules);

            $v1Res = $host->request($app, '/billium/v1/capabilities');
            self::assertSame(200, $v1Res->getStatusCode());
            $v1 = json_decode((string) $v1Res->getContent(), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame('billium.capabilities.v1', $v1['schema']);
            self::assertSame('2026-10', $v1['specRevision']);
            self::assertArrayNotHasKey('supportedUnits', $v1);

            $v2Res = $host->request($app, '/billium/v1/capabilities/v2');
            self::assertSame(200, $v2Res->getStatusCode());
            $v2 = json_decode((string) $v2Res->getContent(), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame('billium.capabilities.v2', $v2['schema']);
            self::assertSame('2026-12', $v2['specRevision']);
            self::assertContains('token', $v2['supportedUnits']);
            self::assertContains('usage.fact.v2', $v2['usageIngressSchemas']);
            self::assertContains('per_unit@2', $v2['ratingStrategyVersions']);

            // Legacy /usage/facts v1 refuses token even when token metric is registered
            $v1Fact = json_encode([
                'schema' => 'usage.fact.v1',
                'sellerRef' => 'seller-acme',
                'source' => 'tariffler',
                'factId' => 'f-v1-token',
                'revision' => 1,
                'metricRef' => 'ai_tokens',
                'metricVersion' => '1',
                'unit' => 'token',
                'quantity' => '10',
                'semantics' => 'additive',
                'subjectRef' => 'subject-1',
                'interval' => ['start' => '2026-10-01T00:00:00Z', 'end' => '2026-11-01T00:00:00Z'],
                'eventAt' => '2026-10-15T12:00:00Z',
                'provenance' => ['collector' => 'test', 'version' => '1'],
            ], JSON_THROW_ON_ERROR);
            $legacyRes = $host->request($app, '/billium/v1/sellers/seller-acme/usage/facts', $v1Fact);
            self::assertSame(400, $legacyRes->getStatusCode());
        } finally {
            Database::drop($database);
        }
    }

    public function test_disabled_usage_omits_v2_ingress_and_returns_404(): void
    {
        MinimalHost::load();
        $database = Database::create();
        try {
            $host = new Host('token-no-usage-test');
            $modules = ['platform', 'ledger'];
            $app = MinimalHost::application($host, $database, 'ledger-only', $modules);
            $app->instance(AuthorizationVerdict::class, $this->createConfiguredStub(AuthorizationVerdict::class, ['authorize' => Verdict::Allow]));
            $host->request($app, '/billium/live');
            Host::migrate($app, $modules);

            $v2Res = $host->request($app, '/billium/v1/capabilities/v2');
            self::assertSame(200, $v2Res->getStatusCode());
            $v2 = json_decode((string) $v2Res->getContent(), true, 32, JSON_THROW_ON_ERROR);
            self::assertNotContains('token', $v2['supportedUnits']);
            self::assertNotContains('usage.fact.v2', $v2['usageIngressSchemas']);

            $postRes = $host->request($app, '/billium/v1/sellers/seller-acme/usage/facts/v2', '{}');
            self::assertSame(404, $postRes->getStatusCode());
        } finally {
            Database::drop($database);
        }
    }
}

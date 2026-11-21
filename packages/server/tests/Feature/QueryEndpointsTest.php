<?php

declare(strict_types=1);

namespace Billium\Server\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\Capabilities;
use Billium\Contracts\Data\Envelopes\FeedBootstrap;
use Billium\Contracts\Data\Envelopes\FeedPage;
use Billium\Contracts\Data\Envelopes\FeedSelection;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Server\Contracts\AuthorizationVerdict;
use Billium\Server\Enums\Verdict;
use Billium\Server\Tests\Support\MinimalHost;
use BilliumMinimalHost\Database;
use BilliumMinimalHost\Host;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

#[Group('pg')]
#[Group('server')]
final class QueryEndpointsTest extends TestCase
{
    private const array PROFILES = [
        'ledger-only' => ['platform', 'ledger'],
        'remote-billium' => ['platform', 'ledger', 'usage', 'rating', 'schedule', 'documents', 'execution', 'collection'],
    ];

    public function test_capabilities_describe_each_composition_and_request_limits(): void
    {
        MinimalHost::load();
        $host = new Host('capability-test');
        $codec = new Codec;
        foreach (self::PROFILES as $profile => $modules) {
            $app = MinimalHost::application($host, 'unused_test', $profile, $modules);
            $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
                $app->make(Repository::class)->set('billium-server', ['max_body_bytes' => 512, 'max_depth' => 1]);
            });
            $response = $host->request($app, '/billium/v1/capabilities?installationRef=forged&environment=live');
            self::assertSame(200, $response->getStatusCode());
            $capabilities = $codec->decode((string) $response->getContent());
            self::assertInstanceOf(Capabilities::class, $capabilities);
            self::assertSame(Host::INSTALLATION, $capabilities->installationRef);
            self::assertSame('test', $capabilities->environment);
            self::assertSame($profile === 'remote-billium', $capabilities->features->execution);
            self::assertFalse($capabilities->features->document);
            self::assertSame(512, $capabilities->limits->maxBodyBytes);
            self::assertSame(1, $capabilities->limits->maxDepth);
            self::assertSame($codec->digestOf($capabilities), $capabilities->digest);
            self::assertNotContains('obligation.prepare.v1', $capabilities->acceptedCommandSchemas);
            self::assertSame(401, $host->request($app, '/billium/v1/capabilities', token: 'forged')->getStatusCode());
            self::assertSame(404, $host->request($app, self::path('commands'), '{}')->getStatusCode());
            if ($profile === 'ledger-only') {
                self::assertSame(404, $host->request($app, self::path('payments/execute'), '{}')->getStatusCode());
            }

            $path = self::path('queries');
            $query = '{"schema":"billium.query.v1","installationRef":"inst-eu-1","sellerRef":"seller-acme","environment":"test","query":"obligation","selector":{"obligationRef":"any"}}';
            self::assertSame('billium.query.v1', $codec->decode($query)->schema);
            foreach ([str_repeat(' ', 513).$query, $query, "{\"schema\":\"\xff\"}"] as $badBody) {
                self::assertSame(400, $host->request($app, $path, $badBody)->getStatusCode());
            }
        }
    }

    public function test_runtime_routes_match_the_http_manifest(): void
    {
        MinimalHost::load();
        $host = new Host('route-test');
        $installed = [];
        foreach (self::PROFILES as $profile => $modules) {
            $app = MinimalHost::application($host, 'unused_test', $profile, $modules);
            $app->make(Kernel::class)->bootstrap();
            foreach ($app->make(Router::class)->getRoutes() as $route) {
                $path = '/'.$route->uri();
                $name = $route->getName();
                if (! str_starts_with($path, '/billium/') && ! str_starts_with($name ?? '', 'billium.server.')) {
                    continue;
                }
                $methods = $route->methods();
                foreach ($methods as $method) {
                    if ($method === 'HEAD' && in_array('GET', $methods, true)) {
                        continue;
                    }
                    $key = $method.' '.$path;
                    $entry = ['method' => $method, 'path' => $path, 'name' => $name];
                    if (isset($installed[$key])) {
                        self::assertSame($installed[$key], $entry, $key);
                    }
                    $installed[$key] = $entry;
                }
            }
        }

        $manifest = json_decode((string) file_get_contents(MinimalHost::root().'/spec/server/openapi.v1.json'), true, flags: JSON_THROW_ON_ERROR);
        $expected = [];
        foreach ($manifest['x-billium-installed-routes'] as $route) {
            $expected[$route['method'].' '.$route['path']] = $route;
        }
        self::assertCount(count($manifest['paths']), $expected);
        ksort($expected);
        ksort($installed);
        self::assertSame($expected, $installed);
    }

    public function test_feed_reads_keep_the_selection_and_advance_no_durable_state(): void
    {
        MinimalHost::load();
        $database = Database::create();
        try {
            $host = new Host('feed-test');
            $app = MinimalHost::application($host, $database, 'ledger-only', ['platform', 'ledger']);
            $app->instance(AuthorizationVerdict::class, $this->createConfiguredStub(AuthorizationVerdict::class, ['authorize' => Verdict::Allow]));
            $host->request($app, '/billium/live');
            Host::migrate($app, ['platform', 'ledger']);
            $app->make(Installation::class)->init(Host::INSTALLATION);
            $db = $app->make(DatabaseManager::class)->connection('server_owner');
            $codec = new Codec;
            $scope = ['installationRef' => Host::INSTALLATION, 'sellerRef' => Host::SELLER, 'environment' => 'test'];
            $open = ['schema' => 'billium.feed.open.v1', ...$scope,
                'commandKey' => '98e68552-8fdb-4edb-8434-3b3bad0b56db',
                'scope' => [...$scope, 'channel' => 'events', 'sourceAuthority' => 'billium', 'lineage' => '1'],
                'eventContractRevision' => 'events-2026-10-v1', 'envelopeSchemas' => ['billium.event.v1'], 'eventTypes' => ['obligation.created.v1']];
            self::assertSame(403, self::send($host, $app, 'feed/open', [
                ...$open, 'scope' => [...$open['scope'], 'sellerRef' => 'foreign'],
            ])->getStatusCode());
            $opened = self::send($host, $app, 'feed/open', $open);
            self::assertSame(200, $opened->getStatusCode(), (string) $opened->getContent());
            self::assertSame($opened->getContent(), self::send($host, $app, 'feed/open', $open)->getContent());
            $selection = $codec->decode((string) $opened->getContent());
            self::assertInstanceOf(FeedSelection::class, $selection);
            $read = ['schema' => 'billium.feed.read.v1', ...$scope, 'subscriptionRef' => $selection->subscriptionRef,
                'selectionDigest' => $selection->selectionDigest, 'cursor' => $selection->nextCursor];
            $claims = $db->table('billium_idempotency_receipt')->count();
            $before = $db->table('billium_feed_subscription')->first();
            $response = self::send($host, $app, 'feed/read', $read);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $page = $codec->decode((string) $response->getContent());
            self::assertInstanceOf(FeedPage::class, $page);
            self::assertSame($selection->selectionDigest, $page->selectionDigest);
            self::assertSame([], $page->events);
            self::assertSame($response->getContent(), self::send($host, $app, 'feed/read', $read)->getContent());
            self::assertSame(403, self::send($host, $app, 'feed/read', [...$read, 'selectionDigest' => 'sha256:'.str_repeat('0', 64)])->getStatusCode());
            self::assertEquals($before, $db->table('billium_feed_subscription')->first());
            self::assertSame($claims, $db->table('billium_idempotency_receipt')->count());
            self::assertSame(0, $db->table('billium_feed_bootstrap_lease')->count());

            $bootstrap = ['schema' => 'billium.feed.bootstrap_request.v1', ...$scope,
                'commandKey' => $open['commandKey'], 'subscriptionRef' => $selection->subscriptionRef, 'selectionDigest' => $selection->selectionDigest];
            $snapshot = self::send($host, $app, 'feed/bootstrap', $bootstrap);
            self::assertSame(200, $snapshot->getStatusCode(), (string) $snapshot->getContent());
            self::assertInstanceOf(FeedBootstrap::class, $codec->decode((string) $snapshot->getContent()));
            self::assertSame($snapshot->getContent(), self::send($host, $app, 'feed/bootstrap', $bootstrap)->getContent());
            self::assertSame(1, $db->table('billium_feed_bootstrap_lease')->count());
            self::assertSame($claims + 1, $db->table('billium_idempotency_receipt')->count());
        } finally {
            Database::drop($database);
        }
    }

    private static function path(string $endpoint): string
    {
        return '/billium/v1/sellers/'.Host::SELLER.'/'.$endpoint;
    }

    /** @param array<string, mixed> $body */
    private static function send(Host $host, Application $app, string $endpoint, array $body): Response
    {
        return $host->request($app, self::path($endpoint), json_encode($body, JSON_THROW_ON_ERROR));
    }
}

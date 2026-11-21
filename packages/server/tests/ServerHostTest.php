<?php

declare(strict_types=1);

namespace Billium\Server\Tests;

use Billium\Collection\Contracts\CollectionConsentGuard;
use Billium\Collection\Contracts\DunningCaseService;
use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Documents\Contracts\DocumentRenderer;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\ExecutionOriginResolver;
use Billium\Execution\Contracts\ExecutionQueries;
use Billium\Execution\Contracts\ExecutionReadiness;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Server\Contracts\AuthorizationVerdict;
use Billium\Server\Data\AuthenticatedPrincipal;
use Billium\Server\Data\ResourceScope;
use Billium\Server\Enums\Verdict;
use Billium\Server\Tests\Support\MinimalHost;
use BilliumMinimalHost\Database;
use BilliumMinimalHost\Host;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

#[Group('pg')]
#[Group('server')]
final class ServerHostTest extends TestCase
{
    private const string ROOT = '/billium/v1/sellers/seller-acme';

    private Host $host;

    private ?string $database = null;

    protected function setUp(): void
    {
        parent::setUp();
        MinimalHost::load();
        $this->host = new Host('server-test-token');
        $this->database = Database::create();
    }

    protected function tearDown(): void
    {
        if ($this->database !== null) {
            Database::drop($this->database);
        }
        parent::tearDown();
    }

    public function test_empty_and_incomplete_schema_refuse_delivery_until_explicit_migration(): void
    {
        $app = $this->application();
        self::assertSame(200, $this->request($app, '/billium/live')->getStatusCode());
        self::assertSame([], $app->make(DatabaseManager::class)->getConnections());
        self::assertSame(503, $this->request($app, '/billium/ready')->getStatusCode());
        self::assertSame(503, $this->request($app, self::ROOT.'/query', $this->query())->getStatusCode());
        self::assertFalse(self::owner($app)->getSchemaBuilder()->hasTable('migrations'));
        Host::migrate($app, ['platform']);
        self::assertSame(503, $this->request($app, '/billium/ready')->getStatusCode());
        Host::migrate($app, ['ledger']);
        self::assertSame(200, $this->request($app, '/billium/ready')->getStatusCode());
        $response = $this->request($app, self::ROOT.'/query', $this->query());
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('billium.reference.unknown', (string) $response->getContent());
        self::assertFalse(self::owner($app)->getSchemaBuilder()->hasTable('billium_execution_operation'));
        self::assertFalse(self::owner($app)->getSchemaBuilder()->hasTable('billium_usage_fact'));
    }

    public function test_invalid_optional_composition_and_forbidden_authority_stay_unready(): void
    {
        $app = $this->application();
        $this->request($app, '/billium/live');
        Host::migrate($app, ['platform', 'ledger']);

        $invalid = $this->application(modules: ['platform', 'ledger', 'unknown']);
        self::assertSame(200, $this->request($invalid, '/billium/live')->getStatusCode());
        self::assertSame(503, $this->request($invalid, '/billium/ready')->getStatusCode());
        self::assertSame(503, $this->request($invalid, self::ROOT.'/query', $this->query())->getStatusCode());

        $app->instance(WriterAdmission::class, $this->createStub(WriterAdmission::class));
        self::assertSame(503, $this->request($app, '/billium/ready')->getStatusCode());
        self::assertSame(503, $this->request($app, self::ROOT.'/query', $this->query())->getStatusCode());
        self::assertSame(404, $this->request($app, self::ROOT.'/commands', '{}')->getStatusCode());
    }

    public function test_seller_and_source_grants_are_checked_before_any_custody(): void
    {
        $app = $this->application();
        $this->request($app, '/billium/live');
        Host::migrate($app, ['platform', 'ledger']);
        $app->make(Installation::class)->init(Host::INSTALLATION);

        self::assertSame(403, $this->host->request($app, self::ROOT.'/query', $this->query(), 'forged')->getStatusCode());
        self::assertSame(403, $this->request($app, '/billium/v1/sellers/other/query', $this->query())->getStatusCode());
        foreach ([
            ['sellerRef' => 'other'], ['installationRef' => 'other'], ['environment' => 'live'],
            ['sourceAuthority' => 'standalone-billium'], ['sourceLineage' => 'forged'], ['partitionRef' => 'forged'],
        ] as $forgery) {
            self::assertSame(403, $this->request($app, self::ROOT.'/observations', $this->observation($forgery))->getStatusCode());
        }
        self::assertSame(0, self::owner($app)->table('billium_inbox_message')->count());

        $parked = $this->request($app, self::ROOT.'/observations', $this->observation());
        self::assertSame(409, $parked->getStatusCode());
        self::assertStringContainsString('billium.observation.parked', (string) $parked->getContent());
        $event = '01JBX5Q0Z8M3N4P7R9S2000002';
        $sources = ['embedded-commerz', 'remote-billium'];
        foreach ($sources as $source) {
            $response = $this->request($app, self::ROOT.'/observations', $this->observation([
                'sourceAuthority' => $source, 'sourceSequence' => 1, 'operationRevision' => 1, 'eventId' => $event,
            ]));
            self::assertSame(503, $response->getStatusCode(), (string) $response->getContent());
            self::assertStringContainsString('billium.recovery.required', (string) $response->getContent());
        }
        self::assertSame($sources, self::owner($app)->table('billium_inbox_message')->where('external_event_id', $event)
            ->orderBy('source_authority')->pluck('source_authority')->all());
        self::assertSame($sources, self::owner($app)->table('billium_feed_partition')->where('partition_ref', 'fixture-partition')
            ->orderBy('source_authority')->pluck('source_authority')->all());
        self::assertSame(0, self::owner($app)->table('billium_allocation_receipt')->count());
        $this->expectException(LogicException::class);
        $app->make(CallerContext::class)->current();
    }

    public function test_full_host_needs_writer_admission_and_authorizes_before_execution_lookup(): void
    {
        $modules = ['platform', 'ledger', 'usage', 'rating', 'schedule', 'documents', 'execution', 'collection'];
        $app = $this->application('remote-billium', $modules);
        foreach ([
            PaymentDrivers::class, DispatchEgress::class, MerchantCredentials::class, ProviderAbsenceVerifier::class,
            ExecutionOriginResolver::class, AdapterQualification::class, ExecutionReadiness::class,
            DocumentRenderer::class, CollectionConsentGuard::class,
        ] as $port) {
            $app->instance($port, $this->createStub($port));
        }
        self::assertSame(200, $this->request($app, '/billium/live')->getStatusCode());
        self::assertTrue($app->bound(AttemptWorker::class));
        self::assertTrue($app->bound(DunningCaseService::class));
        Host::migrate($app, $modules);
        self::assertSame(503, $this->request($app, '/billium/ready')->getStatusCode());
        $app->instance(WriterAdmission::class, $this->createStub(WriterAdmission::class));
        self::assertSame(200, $this->request($app, '/billium/ready')->getStatusCode());
        self::assertSame(404, $this->request($app, self::ROOT.'/observations', $this->observation())->getStatusCode());

        $codec = new Codec;
        $request = json_encode(['schema' => 'payment.find.v1', 'installationRef' => Host::INSTALLATION,
            'environment' => 'test', 'sellerRef' => Host::SELLER, 'operationRef' => 'fixture-op'], JSON_THROW_ON_ERROR);
        $zero = ['amount' => '0.00', 'currency' => 'RUB'];
        $body = ['schema' => 'payment.find.v1', 'operationRef' => 'fixture-op', 'status' => 'accepted-unknown', 'revision' => 1,
            'capturedTotal' => $zero, 'refundedTotal' => $zero, 'reservedRefundTotal' => $zero, 'optionalField' => ['retain' => 'yes']];
        $body['digest'] = $codec->digest($body);
        $reply = $codec->decode(json_encode($body, JSON_THROW_ON_ERROR));
        $queries = $this->createMock(ExecutionQueries::class);
        $queries->expects(self::once())->method('find')->with(self::callback(static fn ($scope): bool => $scope->sellerRef === Host::SELLER
            && $scope->intentOwnerNamespace === 'fixture-intents'), 'fixture-op')->willReturn($reply);
        $app->instance(ExecutionQueries::class, $queries);
        $execute = '{"schema":"payment.execute.v1","commandKey":"7e0c4a52-91d3-4b6f-8a25-c3d1e9f04b87","operationRef":"fixture-op","operationKind":"charge","source":{"context":"fixture","orderRef":"order","intentRef":"intent"},"installationRef":"inst-eu-1","environment":"test","executionProfile":"remote-billium","routingEpoch":1,"sellerRef":"seller-acme","merchantAccountRef":"merchant","money":{"amount":"1490.00","currency":"RUB"},"bindingRevision":"binding","returnContextRef":"return","requestedAt":"2026-10-17T12:00:00Z"}';
        $commands = $this->createMock(RootPaymentCommands::class);
        $commands->expects(self::once())->method('admit')->willReturnCallback(static function (ExecutionScope $scope, ExecutePayment $command) use ($app, $codec): StoredOutcome {
            $caller = $app->make(CallerContext::class)->current();
            self::assertSame('host-fixture', $caller->actorRef);
            self::assertSame(Host::SELLER, $scope->sellerRef);
            self::assertSame('fixture-intents', $scope->intentOwnerNamespace);
            self::assertSame('7e0c4a52-91d3-4b6f-8a25-c3d1e9f04b87', $command->commandKey);

            return StoredOutcome::accepted(new ExecutionReceipt('receipt-1', $command->commandKey, $command->operationRef,
                $codec->digestOf($command), 'accepted-unknown', false, []));
        });
        $app->instance(RootPaymentCommands::class, $commands);

        self::assertSame(403, $this->request($app, self::ROOT.'/find', $request)->getStatusCode());
        self::assertSame(403, $this->request($app, self::ROOT.'/commands', $execute)->getStatusCode());
        $app->instance(AuthorizationVerdict::class, new class(['payment.find.v1' => $codec->digest($request), 'payment.execute.v1' => $codec->digest($execute)]) implements AuthorizationVerdict
        {
            /** @param array<string, string> $digests */
            public function __construct(private readonly array $digests) {}

            public function authorize(AuthenticatedPrincipal $principal, string $action, ResourceScope $resourceScope, string $payloadDigest): Verdict
            {
                return $payloadDigest === ($this->digests[$action] ?? null) && ($resourceScope->selectors['operationRef'] ?? null) === 'fixture-op'
                    ? Verdict::Allow
                    : Verdict::Deny;
            }
        });
        $response = $this->request($app, self::ROOT.'/find', $request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($codec->encode($reply), $response->getContent());
        self::assertSame($body['digest'], $codec->digest($response->getContent()));
        $admission = $this->request($app, self::ROOT.'/commands', $execute);
        self::assertSame(200, $admission->getStatusCode());
        self::assertStringContainsString('receipt-1', (string) $admission->getContent());
    }

    /** @param list<string> $modules */
    private function application(string $profile = 'ledger-only', array $modules = ['platform', 'ledger']): Application
    {
        return MinimalHost::application($this->host, $this->database ?? self::fail('Test database missing'), $profile, $modules);
    }

    private function request(Application $app, string $path, ?string $json = null): Response
    {
        return $this->host->request($app, $path, $json);
    }

    private static function owner(Application $app): Connection
    {
        return $app->make(DatabaseManager::class)->connection('server_owner');
    }

    private function query(): string
    {
        return json_encode([
            'schema' => 'billium.query.v1', 'installationRef' => Host::INSTALLATION, 'environment' => 'test', 'sellerRef' => Host::SELLER,
            'query' => 'dueObligations', 'selector' => ['billingAccountRef' => 'account-a'], 'asOf' => '2026-11-19T00:00:00Z', 'limit' => 5,
        ], JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $overrides */
    private function observation(array $overrides = []): string
    {
        $body = $overrides + [
            'schema' => 'payment.observation.v1', 'eventId' => '01JBX5Q0Z8M3N4P7R9S2000001', 'sourceAuthority' => 'embedded-commerz',
            'sourceLineage' => 'fixture-lineage', 'partitionRef' => 'fixture-partition', 'sourceSequence' => 2,
            'installationRef' => Host::INSTALLATION, 'environment' => 'test', 'sellerRef' => Host::SELLER, 'operationRef' => 'op-fixture',
            'operationKind' => 'charge', 'businessIntentRef' => 'intent-fixture', 'operationRevision' => 2, 'state' => 'succeeded',
            'currency' => 'RUB', 'requested' => ['amount' => '100.00', 'currency' => 'RUB'], 'authorizedTotal' => ['amount' => '100.00', 'currency' => 'RUB'],
            'capturedTotal' => ['amount' => '30.00', 'currency' => 'RUB'], 'refundedTotal' => ['amount' => '0.00', 'currency' => 'RUB'],
            'providerAccountRef' => 'pa-fixture', 'evidenceRef' => 'ev-fixture', 'observedAt' => '2026-10-29T10:00:00Z', 'recordedAt' => '2026-10-29T10:00:01Z',
        ];
        $body['contentDigest'] = (new Codec)->digest($body, 'contentDigest');

        return json_encode($body, JSON_THROW_ON_ERROR);
    }
}

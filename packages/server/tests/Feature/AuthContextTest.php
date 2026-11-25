<?php

declare(strict_types=1);

namespace Billium\Server\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\CancelOperation;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\ExecutionOriginResolver;
use Billium\Execution\Contracts\ExecutionReadiness;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Server\Contracts\AuthorizationVerdict;
use Billium\Server\Contracts\InstallationContextResolver;
use Billium\Server\Contracts\SellerContextResolver;
use Billium\Server\Contracts\SellerEligibility;
use Billium\Server\Data\AuthenticatedPrincipal;
use Billium\Server\Data\ExecutionContext;
use Billium\Server\Data\InstallationContext;
use Billium\Server\Data\ResourceScope;
use Billium\Server\Data\SellerAdmission;
use Billium\Server\Data\SellerScope;
use Billium\Server\Data\SourceAuthorityGrant;
use Billium\Server\Enums\Verdict;
use Billium\Server\Internal\Http\RequestAccess;
use Billium\Server\Tests\Support\MinimalHost;
use BilliumMinimalHost\Database;
use BilliumMinimalHost\Host;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('pg')]
#[Group('server')]
final class AuthContextTest extends TestCase
{
    private const string ROOT = '/billium/v1/sellers/seller-acme';

    private Host $host;

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        MinimalHost::load();
        $this->host = new Host('fixture-auth');
        $this->database = Database::create();
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            Database::drop($this->database);
        }
        parent::tearDown();
    }

    public function test_authentication_and_scope_precede_policy_and_handler(): void
    {
        $app = MinimalHost::application($this->host, $this->database, 'ledger-only', ['platform', 'ledger']);
        $policy = $this->createMock(AuthorizationVerdict::class);
        $policy->expects(self::never())->method('authorize');
        $app->instance(AuthorizationVerdict::class, $policy);
        $app->instance(SellerContextResolver::class, $this->createConfiguredStub(SellerContextResolver::class, ['resolve' => new ExecutionContext(Host::INSTALLATION, 'test', Host::SELLER, 'actor-a'),
        ]));
        $this->host->request($app, '/billium/live');
        $app->make(Router::class)->post(self::ROOT.'/operations/{operationRef}/cancel',
            static fn (Request $request, RequestAccess $access): JsonResponse => $access->run(
                $request, Host::SELLER, [CancelOperation::class], static fn (): never => self::fail('Denied request invoked the handler'),
            ));

        $base = ['installationRef' => Host::INSTALLATION, 'environment' => 'test', 'sellerRef' => Host::SELLER];
        $query = ['schema' => 'billium.query.v1', 'query' => 'obligation', 'selector' => ['obligationRef' => 'obligation-a'], ...$base];
        $unauthenticated = $this->host->request($app, self::ROOT.'/queries', '{', 'wrong-token');
        self::assertSame(401, $unauthenticated->getStatusCode());
        self::assertSame('billium.auth.unauthenticated', (new Codec)->decodeProblem((string) $unauthenticated->getContent())->code->value);
        $routeMismatch = $this->host->request($app, '/billium/v1/sellers/seller-b/queries', json_encode($query, JSON_THROW_ON_ERROR));
        self::assertSame(403, $routeMismatch->getStatusCode());
        foreach ([['sellerRef' => 'seller-b'], ['environment' => 'live'], ['installationRef' => 'foreign-installation']] as $change) {
            $response = $this->host->request($app, self::ROOT.'/queries', json_encode([...$query, ...$change], JSON_THROW_ON_ERROR));
            self::assertSame($routeMismatch->getContent(), $response->getContent());
        }

        $cancel = json_encode(['schema' => 'payment.cancel.v1', 'operationRef' => 'known-operation-b',
            'commandKey' => '7e0c4a52-91d3-4b6f-8a25-c3d1e9f04b87', ...$base], JSON_THROW_ON_ERROR);
        $operationMismatch = $this->host->request($app, self::ROOT.'/operations/local-operation-a/cancel', $cancel);
        self::assertSame(403, $operationMismatch->getStatusCode());
        self::assertSame($routeMismatch->getContent(), $operationMismatch->getContent());
    }

    public function test_host_policy_can_refuse_one_query_kind_under_the_shared_action(): void
    {
        $app = MinimalHost::application($this->host, $this->database, 'ledger-only', ['platform', 'ledger']);
        $kinds = [];
        $policy = $this->createStub(AuthorizationVerdict::class);
        $policy->method('authorize')->willReturnCallback(static function (AuthenticatedPrincipal $principal, string $action, ResourceScope $scope) use (&$kinds): Verdict {
            $kinds[] = $action.':'.(is_string($scope->selectors['query'] ?? null) ? $scope->selectors['query'] : '');

            return $action === 'ledger.query' && ($scope->selectors['query'] ?? null) !== 'refundAuthorization' ? Verdict::Allow : Verdict::Deny;
        });
        $app->instance(AuthorizationVerdict::class, $policy);
        $app->instance(SellerContextResolver::class, $this->createConfiguredStub(SellerContextResolver::class, ['resolve' => new ExecutionContext(Host::INSTALLATION, 'test', Host::SELLER, 'actor-a')]));
        $base = ['schema' => 'billium.query.v1', 'installationRef' => Host::INSTALLATION, 'environment' => 'test', 'sellerRef' => Host::SELLER];

        $denied = $this->host->request($app, self::ROOT.'/queries', json_encode([...$base, 'query' => 'refundAuthorization', 'selector' => ['paymentRef' => 'payment-a']], JSON_THROW_ON_ERROR));
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('billium.auth.denied', (new Codec)->decodeProblem((string) $denied->getContent())->code->value);
        $allowed = $this->host->request($app, self::ROOT.'/queries', json_encode([...$base, 'query' => 'obligation', 'selector' => ['obligationRef' => 'obligation-a']], JSON_THROW_ON_ERROR));
        self::assertNotSame(403, $allowed->getStatusCode());
        self::assertSame(['ledger.query:refundAuthorization', 'ledger.query:obligation'], $kinds);
    }

    public function test_capabilities_context_is_resolved_from_the_authenticated_principal(): void
    {
        $app = MinimalHost::application($this->host, $this->database, 'ledger-only', ['platform', 'ledger']);
        $resolver = $this->createMock(InstallationContextResolver::class);
        $resolver->expects(self::once())->method('resolve')->with(new AuthenticatedPrincipal('host-fixture'))
            ->willReturn(new InstallationContext(Host::INSTALLATION, 'test'));
        $app->instance(InstallationContextResolver::class, $resolver);
        $this->host->request($app, '/billium/live');
        $access = $app->make(RequestAccess::class);
        $path = '/billium/v1/capabilities?installationRef=foreign&environment=live';
        try {
            $access->installation(Request::create($path, 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer wrong-token']));
            self::fail('An unauthenticated request reached the deployment selection');
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::AuthUnauthenticated, $problem->problem);
        }
        $scope = $access->installation(Request::create($path, 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer fixture-auth']));
        self::assertSame(Host::INSTALLATION, $scope->installationRef);
        self::assertSame('test', $scope->environment);
    }

    public function test_current_eligibility_and_verdict_gate_replay_and_advertised_capabilities(): void
    {
        $app = MinimalHost::application($this->host, $this->database, 'remote-billium', ['platform', 'ledger', 'execution']);
        foreach ([AdapterQualification::class, DispatchEgress::class, ExecutionOriginResolver::class, ExecutionReadiness::class,
            PaymentDrivers::class, ProviderAbsenceVerifier::class, WriterAdmission::class] as $port) {
            $app->instance($port, $this->createStub($port));
        }
        $credentials = $this->createMock(MerchantCredentials::class);
        $credentials->expects(self::never())->method('resolve');
        $app->instance(MerchantCredentials::class, $credentials);
        $this->host->request($app, '/billium/live');
        Host::migrate($app, ['platform', 'ledger', 'execution']);

        $body = ['schema' => 'payment.execute.v1', 'commandKey' => '7e0c4a52-91d3-4b6f-8a25-c3d1e9f04b87',
            'operationRef' => 'operation-a', 'operationKind' => 'charge', 'source' => ['context' => 'fixture', 'orderRef' => 'order', 'intentRef' => 'intent'],
            'installationRef' => Host::INSTALLATION, 'environment' => 'test', 'sellerRef' => Host::SELLER, 'executionProfile' => 'remote-billium',
            'routingEpoch' => 1, 'merchantAccountRef' => 'merchant-a', 'money' => ['amount' => '10.00', 'currency' => 'RUB'],
            'bindingRevision' => 'binding-a', 'requestedAt' => '2026-10-17T12:00:00Z'];
        $codec = new Codec;
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $digest = $codec->digest($json);
        $commands = $this->createMock(RootPaymentCommands::class);
        $commands->expects(self::exactly(2))->method('admit')->willReturnCallback(static function (ExecutionScope $scope, ExecutePayment $command) use ($app, $digest): StoredOutcome {
            self::assertSame(Host::SELLER, $scope->sellerRef);
            $caller = $app->make(CallerContext::class)->current();
            self::assertSame('delegated-actor-a', $caller->actorRef);
            self::assertSame(['remote-billium'], $caller->sourceAuthorities);

            return StoredOutcome::accepted(new ExecutionReceipt('receipt-a', $command->commandKey, $command->operationRef, $digest, 'admitted', false, [], 0, $command->operationRef));
        });
        $app->instance(RootPaymentCommands::class, $commands);
        $app->instance(SellerContextResolver::class, $this->createConfiguredStub(SellerContextResolver::class, ['resolve' => new ExecutionContext(
            Host::INSTALLATION, 'test', Host::SELLER, 'delegated-actor-a', capabilities: ['payment.execute.v1'],
            sourceAuthorities: [new SourceAuthorityGrant('payment.observation', 'embedded-commerz', Host::INSTALLATION, 'test', Host::SELLER, 'L1', ['P1'])],
            intentOwnerNamespace: 'fixture-intents', executionAuthorities: ['remote-billium'],
        )]));
        $verdict = Verdict::Allow;
        $policy = $this->createMock(AuthorizationVerdict::class);
        $policy->expects(self::exactly(4))->method('authorize')->willReturnCallback(
            static function (AuthenticatedPrincipal $principal, string $action, ResourceScope $scope, string $payloadDigest) use (&$verdict, $digest): Verdict {
                self::assertSame('host-fixture', $principal->principalRef);
                self::assertSame('delegated-actor-a', $scope->actorRef);
                self::assertSame('payment.execute', $action);
                if ($scope->selectors['bindingRevision'] !== 'binding-a') {
                    return Verdict::Deny;
                }
                self::assertSame($digest, $payloadDigest);

                return $verdict;
            },
        );
        $app->instance(AuthorizationVerdict::class, $policy);

        $first = $this->host->request($app, self::ROOT.'/payments/execute', $json);
        self::assertSame(202, $first->getStatusCode(), (string) $first->getContent());
        self::assertSame('payment.admission.v1', $codec->decode((string) $first->getContent())->schema);
        self::assertSame($first->getContent(), $this->host->request($app, self::ROOT.'/payments/execute', $json)->getContent());
        $verdict = Verdict::Deny;
        $revoked = $this->host->request($app, self::ROOT.'/payments/execute', $json);
        self::assertSame(403, $revoked->getStatusCode());
        self::assertSame('none', $codec->decodeProblem((string) $revoked->getContent())->code->receipt());
        self::assertStringNotContainsString('receipt-a', (string) $revoked->getContent());

        $verdict = Verdict::Allow;
        $foreign = $this->host->request($app, self::ROOT.'/payments/execute', json_encode([...$body, 'bindingRevision' => 'known-binding-b'], JSON_THROW_ON_ERROR));
        self::assertSame($revoked->getContent(), $foreign->getContent());
        $locator = 'secret://fixture/seller-b';
        $forged = $this->host->request($app, self::ROOT.'/payments/execute', json_encode([...$body, 'credentialRef' => $locator], JSON_THROW_ON_ERROR));
        self::assertSame(400, $forged->getStatusCode());
        self::assertSame(ProblemCode::ValidationInvalid, $codec->decodeProblem((string) $forged->getContent())->code);
        self::assertStringNotContainsString($locator, (string) $forged->getContent());

        foreach ([null, new SellerAdmission(new SellerScope(Host::INSTALLATION, 'test', Host::SELLER), false),
            new SellerAdmission(new SellerScope(Host::INSTALLATION, 'test', 'seller-b'), true)] as $admission) {
            $app->instance(SellerEligibility::class, $this->createConfiguredStub(SellerEligibility::class, ['admission' => $admission]));
            self::assertSame($revoked->getContent(), $this->host->request($app, self::ROOT.'/payments/execute', $json)->getContent());
        }
    }
}

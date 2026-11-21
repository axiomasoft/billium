<?php

declare(strict_types=1);

namespace Billium\Server\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\FindReceipt;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Data\Obligations\ObligationDuePolicy;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Server\Contracts\AuthorizationVerdict;
use Billium\Server\Contracts\SellerContextResolver;
use Billium\Server\Contracts\SellerEligibility;
use Billium\Server\Data\AuthenticatedPrincipal;
use Billium\Server\Data\ExecutionContext;
use Billium\Server\Data\ResourceScope;
use Billium\Server\Data\SellerAdmission;
use Billium\Server\Data\SellerScope;
use Billium\Server\Enums\Verdict;
use Billium\Server\Internal\Http\RequestAccess;
use Billium\Server\Internal\Http\RequestCaller;
use Billium\Server\Tests\Support\MinimalHost;
use BilliumMinimalHost\Database;
use BilliumMinimalHost\Host;
use DateTimeImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

#[Group('pg')]
#[Group('server')]
final class SellerIsolationTest extends TestCase
{
    private const string SELLER_B = 'seller-b';

    private const string DUE_AT = '2026-11-19T00:00:00Z';

    private string $database;

    private Host $host;

    protected function setUp(): void
    {
        parent::setUp();
        MinimalHost::load();
        $this->host = new Host('fixture-isolation');
        $this->database = Database::create();
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            Database::drop($this->database);
        }
        parent::tearDown();
    }

    public function test_same_account_and_customer_keep_seller_resources_receipts_and_cursors_isolated_after_restart(): void
    {
        $app = $this->application();
        $callers = $this->activate($app);
        $a = new BillingAccountScope(Host::SELLER, 'test', 'shared-account');
        $b = new BillingAccountScope(self::SELLER_B, 'test', 'shared-account');
        $ledger = $app->make(Ledger::class);
        $this->record($ledger, $a, 'shared-obligation', 'basis-a', '10.00');
        $this->record($ledger, $a, 'obligation-a', 'only-a', '11.00');
        $callers->as(self::SELLER_B);
        $this->record($ledger, $b, 'shared-obligation', 'basis-b', '20.00');
        $this->record($ledger, $b, 'obligation-b', 'only-b', '21.00');

        $app = $this->application();
        $resolver = $this->createStub(SellerContextResolver::class);
        $resolver->method('resolve')->willReturnCallback(static fn (AuthenticatedPrincipal $principal, string $seller): ExecutionContext => new ExecutionContext(Host::INSTALLATION, 'test', $seller, $principal->principalRef));
        $app->instance(SellerContextResolver::class, $resolver);
        $eligibility = $this->createStub(SellerEligibility::class);
        $eligibility->method('admission')->willReturnCallback(static fn (SellerScope $scope): ?SellerAdmission => in_array($scope->sellerRef, [Host::SELLER, self::SELLER_B], true) ? new SellerAdmission($scope, true) : null);
        $app->instance(SellerEligibility::class, $eligibility);
        $policy = $this->createStub(AuthorizationVerdict::class);
        $policy->method('authorize')->willReturnCallback(static function (AuthenticatedPrincipal $principal, string $action, ResourceScope $scope, string $digest): Verdict {
            if ($action === 'receipt.find.v1') {
                self::assertArrayHasKey('receiptRef', $scope->selectors);
            }
            if (($scope->selectors['query'] ?? null) === 'dueObligations') {
                self::assertSame('shared-account', $scope->selectors['selector']['billingAccountRef']);
            }

            return Verdict::Allow;
        });
        $app->instance(AuthorizationVerdict::class, $policy);

        foreach ([Host::SELLER => '10.00', self::SELLER_B => '20.00'] as $seller => $amount) {
            $response = $this->query($app, $seller, 'obligation', ['obligationRef' => 'shared-obligation']);
            self::assertSame(200, $response->getStatusCode());
            $body = self::body($response);
            self::assertSame($seller, $body['sellerRef']);
            self::assertSame('test', $body['environment']);
            self::assertSame(['amount' => $amount, 'currency' => 'RUB'], $body['result']['originalDue']);
        }
        $foreign = $this->query($app, Host::SELLER, 'obligation', ['obligationRef' => 'obligation-b']);
        $unknown = $this->query($app, Host::SELLER, 'obligation', ['obligationRef' => 'missing-obligation']);
        self::assertSame(403, $foreign->getStatusCode());
        self::assertSame($unknown->getContent(), $foreign->getContent());

        // Exercise the shared gateway with the registered receipt envelope and the real scoped core receipt reader.
        $app->make(Router::class)->post('/billium/v1/sellers/{seller}/receipts/find',
            static fn (Request $request, RequestAccess $access, string $seller): JsonResponse => $access->run($request, $seller, [FindReceipt::class],
                static fn (FindReceipt $receipt): JsonResponse => new JsonResponse($app->make(LedgerQueries::class)->receipt($receipt->receiptRef)),
            ));
        $ownedReceipt = $this->receipt($app, self::SELLER_B, 'obligation:only-b');
        self::assertSame(200, $ownedReceipt->getStatusCode(), (string) $ownedReceipt->getContent());
        $foreignReceipt = $this->receipt($app, Host::SELLER, 'obligation:only-b');
        $unknownReceipt = $this->receipt($app, Host::SELLER, 'obligation:missing');
        self::assertSame(403, $foreignReceipt->getStatusCode());
        self::assertSame($unknownReceipt->getContent(), $foreignReceipt->getContent());
        self::assertSame($foreign->getContent(), $foreignReceipt->getContent());

        $account = ['billingAccountRef' => 'shared-account'];
        $page = ['asOf' => self::DUE_AT, 'limit' => 1];
        $first = $this->query($app, Host::SELLER, 'dueObligations', $account, $page);
        self::assertSame(200, $first->getStatusCode());
        $next = [...$page, 'cursor' => self::body($first)['result']['nextCursor']];
        $ownPage = $this->query($app, Host::SELLER, 'dueObligations', $account, $next);
        self::assertSame(200, $ownPage->getStatusCode());
        self::assertSame(['obligation-a'], array_column(self::body($ownPage)['result']['obligations'], 'obligationRef'));
        $foreignPage = $this->query($app, self::SELLER_B, 'dueObligations', $account, $next);
        $malformedPage = $this->query($app, self::SELLER_B, 'dueObligations', $account, [...$page, 'cursor' => 'bad-cursor']);
        self::assertSame(400, $foreignPage->getStatusCode());
        self::assertSame($malformedPage->getContent(), $foreignPage->getContent());
    }

    public function test_external_authority_query_returns_the_ledger_observed_exposure_and_hold(): void
    {
        $app = $this->application('embedded-commerz');
        $this->activate($app);
        $scope = new BillingAccountScope(Host::SELLER, 'test', 'account-a');
        $ledger = $app->make(Ledger::class);
        $this->record($ledger, $scope, 'owed', 'basis-owed', '10.00');
        $ledger->declareSettlementIntent(new DeclareSettlementIntent('declare-intent', $scope, 'intent-a', 'RUB', ['owed']))->orThrow();
        $observationUrl = self::endpoint(Host::SELLER, 'observations');
        foreach ([1 => 0, 2 => 7000, 3 => 14000] as $revision => $refunded) {
            $fact = ObservationFactory::charge('charge-a', $revision, 10000, $refunded, $revision, [
                'installationRef' => Host::INSTALLATION, 'sellerRef' => Host::SELLER, 'sourceLineage' => 'fixture-lineage', 'partitionRef' => 'fixture-partition',
            ]);
            $response = $this->host->request($app, $observationUrl, ObservationFactory::json($fact));
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        }
        $fact = ObservationFactory::json($fact);
        self::assertSame($response->getContent(), $this->host->request($app, $observationUrl, $fact)->getContent());
        $policy = $this->createStub(AuthorizationVerdict::class);
        $policy->method('authorize')->willReturnCallback(static fn (AuthenticatedPrincipal $principal, string $action): Verdict => $action === 'billium.query.v1' ? Verdict::Allow : Verdict::Deny);
        $app->instance(AuthorizationVerdict::class, $policy);
        $denied = $this->host->request($app, $observationUrl, $fact);
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame(ProblemCode::AuthDenied, (new Codec)->decodeProblem((string) $denied->getContent())->code);

        $response = $this->query($app, Host::SELLER, 'paymentStatus', ['paymentRef' => 'charge-a']);
        self::assertSame(200, $response->getStatusCode());
        (new Codec)->decode((string) $response->getContent());
        $status = self::body($response)['result'];
        self::assertSame('100.00', $status['applied']['capturedTotal']['amount']);
        self::assertSame('70.00', $status['applied']['refundedTotal']['amount']);
        self::assertSame('140.00', $status['observed']['refundedTotal']['amount']);
        self::assertSame('40.00', $status['exposure']['overRefunded']['amount']);
        self::assertTrue($status['financialHold']['active']);
        self::assertSame('held_discrepancy', $status['projectionStatus']);
        self::assertSame('0.00', $status['spendable']['amount']);
    }

    private function application(string $profile = 'ledger-only'): Application
    {
        $app = MinimalHost::application($this->host, $this->database, $profile, ['platform', 'ledger']);
        $this->host->request($app, '/billium/live');

        return $app;
    }

    private function activate(Application $app): FixedCallerContext
    {
        Host::migrate($app, ['platform', 'ledger']);
        $callers = new FixedCallerContext(new Caller(Host::SELLER, 'test', Host::INSTALLATION, 'host-fixture', ['embedded-commerz']));
        $app->instance(CallerContext::class, new RequestCaller($app, $callers));
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token(Host::INSTALLATION)));
        SimulatedFinancialAuthorityEvidence::reset();
        $authority = SimulatedFinancialAuthorityEvidence::install($app);
        $installation = $app->make(Installation::class);
        $installation->init(Host::INSTALLATION);
        $authority->issueTransition('open', 'open-1', ['owner-1', 1, 1, 1, 'evidence:open-1']);
        $installation->openSession('open-1', 'owner-1', 1, 1, 1, 'evidence:open-1');
        $authority->issueTransition('activate', 'activate-1', [1, 'certificate:1', 'admission:1', 'evidence:activate-1']);
        $installation->activateSession('activate-1', 1, 'certificate:1', 'admission:1', 'evidence:activate-1');
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);

        return $callers;
    }

    private function record(Ledger $ledger, BillingAccountScope $scope, string $ref, string $basis, string $amount): void
    {
        $digest = 'sha256:'.str_repeat('a', 64);
        $policy = new ObligationDuePolicy(new DateTimeImmutable(self::DUE_AT), 'fixture/1', 'fixture-policy', $digest);
        $ledger->recordObligation(new RecordObligation('key-'.$ref, $scope, $ref, $basis, 'shared-customer', 'component-a',
            Money::parse($amount, 'RUB'), $digest, duePolicy: $policy))->orThrow();
    }

    /** @param array<string, string> $selector
     * @param  array<string, mixed>  $extra
     */
    private function query(Application $app, string $seller, string $name, array $selector, array $extra = []): Response
    {
        return $this->host->request($app, self::endpoint($seller, 'query'), json_encode([
            'schema' => 'billium.query.v1', 'installationRef' => Host::INSTALLATION, 'environment' => 'test', 'sellerRef' => $seller,
            'query' => $name, 'selector' => $selector, ...$extra,
        ], JSON_THROW_ON_ERROR));
    }

    private function receipt(Application $app, string $seller, string $ref): Response
    {
        return $this->host->request($app, self::endpoint($seller, 'receipts/find'), json_encode([
            'schema' => 'receipt.find.v1', 'installationRef' => Host::INSTALLATION, 'environment' => 'test', 'sellerRef' => $seller, 'receiptRef' => $ref,
        ], JSON_THROW_ON_ERROR));
    }

    private static function endpoint(string $seller, string $route): string
    {
        return '/billium/v1/sellers/'.$seller.'/'.$route;
    }

    /** @return array<string, mixed> */
    private static function body(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}

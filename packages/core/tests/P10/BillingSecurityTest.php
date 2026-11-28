<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P10;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Core\Tests\TestEnvironment;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\CallbackPayloadArchive;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionOriginResolver;
use Billium\Execution\Contracts\ExecutionReadiness;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Contracts\RepairCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\RecoveryAction;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\CallbackCustodyTest;
use Billium\Execution\Tests\Feature\DisputeSettlementTest;
use Billium\Execution\Tests\Feature\HistoricalMerchantRefundTest;
use Billium\Execution\Tests\Feature\InventoryReconciliationTest;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Invoices\UpdateInvoiceMetadata;
use Billium\Ledger\Data\Obligations\ObligationDuePolicy;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Server\Contracts\AuthorizationVerdict;
use Billium\Server\Contracts\SellerContextResolver;
use Billium\Server\Contracts\SellerEligibility;
use Billium\Server\Data\AuthenticatedPrincipal;
use Billium\Server\Data\ExecutionContext;
use Billium\Server\Data\SellerAdmission;
use Billium\Server\Data\SellerScope;
use Billium\Server\Data\SourceAuthorityGrant;
use Billium\Server\Enums\Verdict;
use Billium\Server\Tests\Support\MinimalHost;
use Billium\Testkit\Fixtures\DurableJournal;
use BilliumMinimalHost\Database;
use BilliumMinimalHost\Host;
use DateTimeImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Basic security, privacy, and isolation sanity gate:
 * - Spoofed seller header/payload rejection & cross-seller isolation
 * - Unauthorized replay, query, and manual-repair rejection
 * - Webhook authenticity, replay idempotency, and hash-conflict protection
 * - Oversized request body and metadata rejection
 * - Sensitive field redaction and zero secret leakage in custody/DTOs/DB
 */
#[Group('pg')]
#[Group('P10.6')]
#[Group('D71')]
#[Group('D72')]
final class BillingSecurityTest extends ExecutionTestCase
{
    private const string SELLER_A = 'seller-acme';

    private const string SELLER_B = 'seller-b';

    private const string ROOT_A = '/billium/v1/sellers/seller-acme';

    private const string DUE_AT = '2026-11-22T00:00:00Z';

    #[Group('PB-17')]
    #[Group('INV-14')]
    #[Group('REQ-PROF-08')]
    public function test_spoofed_seller_header_payload_rejection_and_cross_seller_isolation(): void
    {
        MinimalHost::load();
        $host = new Host('fixture-sec-iso');
        $database = Database::create();

        $prev = Container::getInstance();

        try {
            $app = $this->serverApp($host, $database, 'ledger-only', ['platform', 'ledger']);
            $callers = $this->activateServer($host, $app);
            $ledger = $app->make(Ledger::class);

            $scopeA = new BillingAccountScope(self::SELLER_A, 'test', 'shared-account');
            $scopeB = new BillingAccountScope(self::SELLER_B, 'test', 'shared-account');
            $this->recordObligation($ledger, $scopeA, 'shared-obligation', 'basis-sec-a', '10.00');
            $this->recordObligation($ledger, $scopeA, 'obligation-a', 'only-sec-a', '11.00');
            $callers->as(self::SELLER_B);
            $this->recordObligation($ledger, $scopeB, 'shared-obligation', 'basis-sec-b', '20.00');
            $this->recordObligation($ledger, $scopeB, 'obligation-b', 'only-sec-b', '21.00');

            // 1) Unauthenticated and spoofed seller/environment/installation payloads never reach policy.
            $guardApp = $this->serverApp($host, $database, 'ledger-only', ['platform', 'ledger']);
            $neverPolicy = $this->createMock(AuthorizationVerdict::class);
            $neverPolicy->expects(self::never())->method('authorize');
            $guardApp->instance(AuthorizationVerdict::class, $neverPolicy);
            $guardApp->instance(SellerContextResolver::class, $this->createConfiguredStub(SellerContextResolver::class, [
                'resolve' => new ExecutionContext(Host::INSTALLATION, 'test', self::SELLER_A, 'actor-a'),
            ]));
            $host->request($guardApp, '/billium/live');

            $base = ['installationRef' => Host::INSTALLATION, 'environment' => 'test', 'sellerRef' => self::SELLER_A];
            $query = ['schema' => 'billium.query.v1', 'query' => 'obligation', 'selector' => ['obligationRef' => 'obligation-a'], ...$base];

            $unauth = $host->request($guardApp, self::ROOT_A.'/queries', json_encode($query, JSON_THROW_ON_ERROR), 'wrong-token');
            self::assertSame(401, $unauth->getStatusCode());
            self::assertSame(ProblemCode::AuthUnauthenticated, (new Codec)->decodeProblem((string) $unauth->getContent())->code);

            $spoofedHeaderAndRoute = $this->requestWithHeader(
                $guardApp,
                '/billium/v1/sellers/'.self::SELLER_B.'/queries',
                json_encode($query, JSON_THROW_ON_ERROR),
                'fixture-sec-iso',
                ['HTTP_X_SELLER_REF' => self::SELLER_B],
            );
            self::assertSame(403, $spoofedHeaderAndRoute->getStatusCode());
            self::assertSame(ProblemCode::AuthDenied, (new Codec)->decodeProblem((string) $spoofedHeaderAndRoute->getContent())->code);

            foreach ([['sellerRef' => self::SELLER_B], ['environment' => 'live'], ['installationRef' => 'foreign-installation']] as $tamper) {
                $resp = $this->requestWithHeader(
                    $guardApp,
                    self::ROOT_A.'/queries',
                    json_encode([...$query, ...$tamper], JSON_THROW_ON_ERROR),
                    'fixture-sec-iso',
                    ['HTTP_X_SELLER_REF' => self::SELLER_B],
                );
                self::assertSame(403, $resp->getStatusCode());
                self::assertSame($spoofedHeaderAndRoute->getContent(), $resp->getContent());
            }

            // 2) Cross-seller resource, receipt, and cursor isolation.
            $isoApp = $this->serverApp($host, $database, 'ledger-only', ['platform', 'ledger']);
            $resolver = $this->createStub(SellerContextResolver::class);
            $resolver->method('resolve')->willReturnCallback(
                static fn (AuthenticatedPrincipal $p, string $seller): ExecutionContext => new ExecutionContext(Host::INSTALLATION, 'test', $seller, $p->principalRef),
            );
            $isoApp->instance(SellerContextResolver::class, $resolver);
            $eligibility = $this->createStub(SellerEligibility::class);
            $eligibility->method('admission')->willReturnCallback(
                static fn (SellerScope $s): ?SellerAdmission => in_array($s->sellerRef, [self::SELLER_A, self::SELLER_B], true) ? new SellerAdmission($s, true) : null,
            );
            $isoApp->instance(SellerEligibility::class, $eligibility);
            $allow = $this->createStub(AuthorizationVerdict::class);
            $allow->method('authorize')->willReturn(Verdict::Allow);
            $isoApp->instance(AuthorizationVerdict::class, $allow);
            $host->request($isoApp, '/billium/live');

            foreach ([self::SELLER_A => '10.00', self::SELLER_B => '20.00'] as $seller => $expectedAmount) {
                $own = $this->serverQuery($host, $isoApp, $seller, 'obligation', ['obligationRef' => 'shared-obligation']);
                self::assertSame(200, $own->getStatusCode());
                self::assertSame($expectedAmount, self::jsonBody($own)['result']['originalDue']['amount']);
            }

            $foreignOb = $this->serverQuery($host, $isoApp, self::SELLER_A, 'obligation', ['obligationRef' => 'obligation-b']);
            $missingOb = $this->serverQuery($host, $isoApp, self::SELLER_A, 'obligation', ['obligationRef' => 'missing-obligation']);
            self::assertSame(403, $foreignOb->getStatusCode());
            self::assertSame($missingOb->getContent(), $foreignOb->getContent());

            $page1 = $this->serverQuery($host, $isoApp, self::SELLER_A, 'dueObligations', ['billingAccountRef' => 'shared-account'], ['asOf' => self::DUE_AT, 'limit' => 1]);
            self::assertSame(200, $page1->getStatusCode());
            $cursorA = self::jsonBody($page1)['result']['nextCursor'];
            self::assertIsString($cursorA);

            $foreignCursor = $this->serverQuery($host, $isoApp, self::SELLER_B, 'dueObligations', ['billingAccountRef' => 'shared-account'], ['asOf' => self::DUE_AT, 'limit' => 1, 'cursor' => $cursorA]);
            $badCursor = $this->serverQuery($host, $isoApp, self::SELLER_B, 'dueObligations', ['billingAccountRef' => 'shared-account'], ['asOf' => self::DUE_AT, 'limit' => 1, 'cursor' => 'malformed']);
            self::assertSame(400, $foreignCursor->getStatusCode());
            self::assertSame($badCursor->getContent(), $foreignCursor->getContent());
        } finally {
            Container::setInstance($prev);
            Database::drop($database);
        }
    }

    #[Group('PB-17')]
    #[Group('PF-11')]
    #[Group('REQ-PROF-08')]
    public function test_unauthorized_replay_query_and_manual_repair_rejection(): void
    {
        // Part A: HTTP command replay & query denial without receipt or secret leakage.
        MinimalHost::load();
        $host = new Host('fixture-sec-replay');
        $database = Database::create();

        $prev = Container::getInstance();

        try {
            $app = $this->serverApp($host, $database, 'remote-billium', ['platform', 'ledger', 'execution']);
            foreach ([AdapterQualification::class, DispatchEgress::class, ExecutionOriginResolver::class, ExecutionReadiness::class,
                PaymentDrivers::class, ProviderAbsenceVerifier::class, WriterAdmission::class] as $port) {
                $app->instance($port, $this->createStub($port));
            }
            $credentials = $this->createMock(MerchantCredentials::class);
            $credentials->expects(self::never())->method('resolve');
            $app->instance(MerchantCredentials::class, $credentials);
            $host->request($app, '/billium/live');
            Host::migrate($app, ['platform', 'ledger', 'execution']);

            $body = ['schema' => 'payment.execute.v1', 'commandKey' => '7e0c4a52-91d3-4b6f-8a25-c3d1e9f04b99',
                'operationRef' => 'op-sec-a', 'operationKind' => 'charge', 'source' => ['context' => 'fixture', 'orderRef' => 'order-sec', 'intentRef' => 'intent-sec'],
                'installationRef' => Host::INSTALLATION, 'environment' => 'test', 'sellerRef' => self::SELLER_A, 'executionProfile' => 'remote-billium',
                'routingEpoch' => 1, 'merchantAccountRef' => 'merchant-a', 'money' => ['amount' => '10.00', 'currency' => 'RUB'],
                'bindingRevision' => 'binding-a', 'requestedAt' => '2026-11-22T12:00:00Z'];
            $codec = new Codec;
            $json = json_encode($body, JSON_THROW_ON_ERROR);
            $digest = $codec->digest($json);

            $commands = $this->createMock(RootPaymentCommands::class);
            $commands->expects(self::exactly(2))->method('admit')->willReturnCallback(
                static fn (ExecutionScope $scope, ExecutePayment $cmd): StoredOutcome => StoredOutcome::accepted(
                    new ExecutionReceipt('receipt-sec-a', $cmd->commandKey, $cmd->operationRef, $digest, 'admitted', false, [], 0, $cmd->operationRef),
                ),
            );
            $app->instance(RootPaymentCommands::class, $commands);
            $app->instance(SellerContextResolver::class, $this->createConfiguredStub(SellerContextResolver::class, [
                'resolve' => new ExecutionContext(Host::INSTALLATION, 'test', self::SELLER_A, 'delegated-actor-sec',
                    capabilities: ['payment.execute.v1'],
                    sourceAuthorities: [new SourceAuthorityGrant('payment.observation', 'external-authority', Host::INSTALLATION, 'test', self::SELLER_A, 'L1', ['P1'])],
                    intentOwnerNamespace: 'fixture-intents', executionAuthorities: ['remote-billium']),
            ]));

            $verdict = Verdict::Allow;
            $policy = $this->createStub(AuthorizationVerdict::class);
            $policy->method('authorize')->willReturnCallback(static function () use (&$verdict): Verdict {
                return $verdict;
            });
            $app->instance(AuthorizationVerdict::class, $policy);

            $first = $host->request($app, self::ROOT_A.'/payments/execute', $json);
            self::assertSame(202, $first->getStatusCode(), (string) $first->getContent());
            self::assertSame($first->getContent(), $host->request($app, self::ROOT_A.'/payments/execute', $json)->getContent());

            $verdict = Verdict::Deny;
            $deniedReplay = $host->request($app, self::ROOT_A.'/payments/execute', $json);
            self::assertSame(403, $deniedReplay->getStatusCode());
            self::assertSame(ProblemCode::AuthDenied, $codec->decodeProblem((string) $deniedReplay->getContent())->code);
            self::assertSame('none', $codec->decodeProblem((string) $deniedReplay->getContent())->code->receipt());
            self::assertStringNotContainsString('receipt-sec-a', (string) $deniedReplay->getContent());

            $deniedQuery = $this->serverQuery($host, $app, self::SELLER_A, 'obligation', ['obligationRef' => 'any-ob']);
            self::assertSame(403, $deniedQuery->getStatusCode());
            self::assertSame(ProblemCode::AuthDenied, $codec->decodeProblem((string) $deniedQuery->getContent())->code);

            $verdict = Verdict::Allow;
            $secretLocator = 'secret://fixture/forged-seller-b';
            $forgedField = $host->request($app, self::ROOT_A.'/payments/execute', json_encode([...$body, 'credentialRef' => $secretLocator], JSON_THROW_ON_ERROR));
            self::assertSame(400, $forgedField->getStatusCode());
            self::assertSame(ProblemCode::ValidationInvalid, $codec->decodeProblem((string) $forgedField->getContent())->code);
            self::assertStringNotContainsString($secretLocator, (string) $forgedField->getContent());
        } finally {
            Container::setInstance($prev);
            Database::drop($database);
        }

        // Part B: Unauthorized execution manual repair is rejected with zero repair receipts.
        $execApp = $this->application();
        $path = Fixture::tempPath();

        try {
            Refunds::captured($execApp, $path);
            InventoryReconciliationTest::grant($this->callers);
            InventoryReconciliationTest::enableInventory($execApp, $path);
            InventoryReconciliationTest::page($path, [DisputeSettlementTest::line('external_manual', 45, 'external-sec-1')]);
            InventoryReconciliationTest::scan($execApp, 'audit-sec', new DateTimeImmutable('-1 hour'), new DateTimeImmutable);
            $eventRef = self::text($this->value('SELECT event_ref FROM billium_execution_recon_review'));

            $scope = Fixture::scope();
            $actor = $this->callers->current()->actorRef;
            $digest = PaymentIdentity::digest(['schema' => 'billium.execution.repair.v1', 'scope' => get_object_vars($scope),
                'source' => 'remote-billium', 'root' => 'root-a', 'review' => $eventRef, 'action' => 'adopt_external_effect',
                'actor' => $actor, 'reason' => 'Unauthorized attempt', 'evidence' => ['external-sec-1']]);
            $action = new RecoveryAction('unauth-repair-key', $scope, 'adopt_external_effect', ['external-sec-1'], $actor, 'Unauthorized attempt', $digest, 'root-a');
            $repairApi = $execApp->make(RepairCommands::class);

            foreach ([['execution.reconcile'], []] as $insufficientGrants) {
                InventoryReconciliationTest::grant($this->callers, $insufficientGrants);
                HistoricalMerchantRefundTest::problem(ProblemCode::AuthDenied, static fn () => $repairApi->repair($action, $eventRef));
            }
            self::assertSame(0, $this->rows('billium_execution_repair_receipt'));
        } finally {
            InventoryReconciliationTest::clear($path);
        }
    }

    #[Group('PB-07')]
    #[Group('PB-08')]
    #[Group('PB-17')]
    #[Group('PF-05')]
    #[Group('ACC-CON-23')]
    public function test_webhook_authenticity_replay_and_hash_conflict_protection(): void
    {
        $journal = Fixture::tempPath();

        try {
            $app = $this->application();
            $event = CallbackCustodyTest::dispatched($app, $journal);
            $custody = $app->make(CallbackCustody::class);

            // 1) Forged signature callback is rejected with AuthDenied, quarantined, and creates 0 provider facts.
            $forged = new ProviderEvidence(
                $event->evidenceRef, $event->scope, $event->providerId, $event->merchantAccountRef, $event->providerEventId,
                $event->payloadDigest, $event->signatureVerdict, $event->handlerContractVersion, $event->receivedAt,
                ['material' => $event->sanitizedPayload['material'], 'fixtureSignature' => 'forged-hmac-sig'], $event->securePayloadRef,
            );
            try {
                $custody->receive(Fixture::scope(), 'merchant-a', 'root-a', $forged);
                self::fail('Forged webhook signature must fail authentication');
            } catch (ProblemException $failure) {
                self::assertSame(ProblemCode::AuthDenied, $failure->problem);
            }
            self::assertSame(1, $this->rows('billium_execution_provider_work', "state='quarantined'"));
            self::assertSame(0, $this->rows('billium_execution_provider_fact'));

            // 2) Authentic callback + idempotent replay produce a single fact and confirmed capture.
            $accepted = $custody->receive(Fixture::scope(), 'merchant-a', 'root-a', $event)->orThrow();
            $replayed = $custody->receive(Fixture::scope(), 'merchant-a', 'root-a', $event)->orThrow();
            self::assertSame('custody_accepted', $accepted->body['status']);
            self::assertSame($accepted->body['commandKey'], $replayed->body['commandKey']);
            self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce(CallbackCustodyTest::work($event))->disposition);
            self::assertSame(1, $this->rows('billium_execution_provider_fact'));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100'));

            // 3) Hash-conflict callback (same event identity with conflicting status) is quarantined and protects terminal state.
            $conflicting = CallbackCustodyTest::signed($event, ['status' => 'declined']);
            $quarantined = $custody->receive(Fixture::scope(), 'merchant-a', null, $conflicting)->orThrow();
            self::assertSame('custody_quarantined', $quarantined->body['status']);
            self::assertSame(1, $this->rows('billium_execution_provider_evidence', 'evidence_ref=? AND payload_digest=?', [$event->evidenceRef, $event->payloadDigest]));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100'));
        } finally {
            UnknownResultTest::clean($journal);
        }
    }

    #[Group('INV-13')]
    #[Group('REQ-CON-21')]
    public function test_oversized_request_body_and_metadata_rejection(): void
    {
        // Part A: HTTP body exceeding configured max_body_bytes is rejected with 400 ValidationInvalid.
        MinimalHost::load();
        $host = new Host('fixture-sec-limits');
        $database = Database::create();

        $prev = Container::getInstance();

        try {
            $app = $this->serverApp($host, $database, 'ledger-only', ['platform', 'ledger']);
            $app->afterBootstrapping(LoadConfiguration::class, static function (Application $a): void {
                $a->make(Repository::class)->set('billium-server.max_body_bytes', 512);
            });
            $host->request($app, '/billium/live');

            $oversized = json_encode([
                'schema' => 'billium.query.v1',
                'query' => 'obligation',
                'installationRef' => Host::INSTALLATION,
                'environment' => 'test',
                'sellerRef' => self::SELLER_A,
                'selector' => ['obligationRef' => str_repeat('a', 1024)],
            ], JSON_THROW_ON_ERROR);
            $resp = $host->request($app, self::ROOT_A.'/queries', $oversized);
            self::assertSame(400, $resp->getStatusCode());
            self::assertSame(ProblemCode::ValidationInvalid, (new Codec)->decodeProblem((string) $resp->getContent())->code);
        } finally {
            Container::setInstance($prev);
            Database::drop($database);
        }

        // Part B: Ledger invoice metadata validation & PostgreSQL 64 KiB + secret-key CHECK backstops.
        $execApp = $this->application();
        $ledger = $execApp->make(Ledger::class);
        $scope = new BillingAccountScope('seller-a', 'test', 'account-sec-meta');
        $this->recordObligation($ledger, $scope, 'ob-sec-meta', 'basis-sec-meta', '15.00');
        $ledger->createInvoice(new CreateInvoice(
            'create-inv-sec',
            $scope,
            'inv-sec-meta',
            'shared-customer',
            [new InvoiceLineInput('ob-sec-meta', 0)],
        ))->orThrow();

        try {
            $ledger->updateInvoiceMetadata(new UpdateInvoiceMetadata('empty-meta', $scope, 'upd-empty', 'inv-sec-meta', []))->orThrow();
            self::fail('Empty metadata must be rejected');
        } catch (ProblemException $failure) {
            self::assertSame(ProblemCode::ValidationInvalid, $failure->problem);
        }

        $rejectedOversize = false;
        try {
            $ledger->updateInvoiceMetadata(new UpdateInvoiceMetadata(
                'oversize-meta',
                $scope,
                'upd-oversize',
                'inv-sec-meta',
                ['note' => str_repeat('x', 65537)],
            ))->orThrow();
        } catch (QueryException|ProblemException) {
            $rejectedOversize = true;
        }
        self::assertTrue($rejectedOversize, 'Oversized invoice metadata (>64 KiB) must be rejected');

        $owner = TestEnvironment::pdo(owner: true);
        $owner->exec("UPDATE billium_invoice SET status='issued', issued_at=now() WHERE invoice_ref='inv-sec-meta'");
        self::assertSame('23514', self::sqlState($owner, "UPDATE billium_invoice SET metadata=jsonb_build_object('note', repeat('x', 65537)) WHERE invoice_ref='inv-sec-meta'"));
        self::assertSame('23514', self::sqlState($owner, "UPDATE billium_invoice SET metadata='{\"labels\":{\"api_key\":\"sk_live_secret\"}}' WHERE invoice_ref='inv-sec-meta'"));
        self::assertSame('23514', self::sqlState($owner, "UPDATE billium_invoice SET metadata='{\"labels\":{\"token\":\"raw-bearer\"}}' WHERE invoice_ref='inv-sec-meta'"));
        self::assertNull(self::sqlState($owner, "UPDATE billium_invoice SET metadata='{\"labels\":{\"secret_ref\":\"secret://merchant/key\"}}' WHERE invoice_ref='inv-sec-meta'"));
    }

    #[Group('PF-05')]
    #[Group('REQ-PROF-08')]
    public function test_sensitive_field_redaction_and_no_secret_leakage_in_custody_and_dtos(): void
    {
        // 1) SecretRef DTO rejects raw credential material and accepts only opaque secret:// locators.
        foreach (['', 'sk_live_1234567890', 'https://user:pass@provider.example', 'secret://merchant/key?token=raw'] as $invalid) {
            try {
                new SecretRef($invalid);
                self::fail('SecretRef must reject raw secret material: '.$invalid);
            } catch (ProblemException $failure) {
                self::assertSame(ProblemCode::ValidationInvalid, $failure->problem);
                self::assertStringNotContainsString('sk_live_1234567890', $failure->getMessage());
                self::assertStringNotContainsString('pass@provider', $failure->getMessage());
            }
        }
        $opaque = new SecretRef('secret://merchant-a/api-key-v1');
        self::assertSame('secret://merchant-a/api-key-v1', $opaque->locator);

        // 2) Callback custody redacts inline sensitive fields (PAN, CVV, password, api_key, client_secret) from DB persistence.
        $journal = Fixture::tempPath();
        $archiveFile = $journal.'.sec-frames';

        try {
            $app = $this->application();
            $source = CallbackCustodyTest::dispatched($app, $journal);
            $event = CallbackCustodyTest::signed($source, ['revision' => '7']);
            $secrets = [
                'password' => 'fixture-secret-pass',
                'api_key' => 'sk_live_never_inline',
                'note' => '4111111111111111',
                'cvv2' => 'not-inline-cvv2',
                'client_secret' => 'cs_live_never_inline',
            ];
            $dirtyEvent = new ProviderEvidence(
                $event->evidenceRef, $event->scope, $event->providerId, $event->merchantAccountRef, $event->providerEventId,
                $event->payloadDigest, $event->signatureVerdict, $event->handlerContractVersion, $event->receivedAt,
                [...$event->sanitizedPayload, ...$secrets], $event->securePayloadRef,
            );

            $verifier = self::createStub(AdapterQualification::class);
            $verifier->method('verify')->willThrowException(new RuntimeException('Verifier unavailable'));
            $app->instance(AdapterQualification::class, $verifier);

            $vault = $this->createMock(CallbackPayloadArchive::class);
            $vault->expects(self::once())->method('retain')->willReturnCallback(static function ($scope, $revision, $value) use ($archiveFile): string {
                new DurableJournal($archiveFile)->append($value);

                return 'fixture-sec-archive:1';
            });
            $app->instance(CallbackPayloadArchive::class, $vault);

            try {
                $app->make(CallbackCustody::class)->receive(Fixture::scope(), 'merchant-a', 'root-a', $dirtyEvent);
                self::fail('Expected durable AuthDenied on unverified sensitive callback');
            } catch (ProblemException $failure) {
                self::assertSame(ProblemCode::AuthDenied, $failure->problem);
                foreach ($secrets as $rawValue) {
                    self::assertStringNotContainsString($rawValue, $failure->getMessage());
                }
            }

            foreach ($secrets as $rawValue) {
                self::assertSame(0, $this->rows('billium_execution_provider_evidence', 'sanitized_payload::text LIKE ?', ['%'.$rawValue.'%']));
            }
            self::assertSame(1, $this->rows('billium_execution_provider_evidence', "secure_payload_ref='fixture-sec-archive:1' AND sanitized_payload->>'archived'='true'"));
        } finally {
            UnknownResultTest::clean($journal);
            if (is_file($archiveFile)) {
                unlink($archiveFile);
            }
        }
    }

    /** @param list<string> $modules */
    private function serverApp(Host $host, string $database, string $profile, array $modules): Application
    {
        return MinimalHost::application($host, $database, $profile, $modules);
    }

    private function activateServer(Host $host, Application $app): FixedCallerContext
    {
        $host->request($app, '/billium/live');
        Host::migrate($app, ['platform', 'ledger']);
        $callers = new FixedCallerContext(new Caller(self::SELLER_A, 'test', Host::INSTALLATION, 'test-actor'));
        $app->instance(CallerContext::class, $callers);
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

    /** @param array<string, string> $headers */
    private function requestWithHeader(Application $app, string $path, string $json, string $token, array $headers): Response
    {
        $request = Request::create($path, 'POST', server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
            ...$headers,
        ], content: $json);

        return $app->make(Kernel::class)->handle($request);
    }

    private function recordObligation(Ledger $ledger, BillingAccountScope $scope, string $ref, string $basis, string $amount): void
    {
        $digest = 'sha256:'.str_repeat('a', 64);
        $policy = new ObligationDuePolicy(new DateTimeImmutable(self::DUE_AT), 'fixture/1', 'fixture-policy', $digest);
        $ledger->recordObligation(new RecordObligation(
            'key-'.$ref, $scope, $ref, $basis, 'shared-customer', 'component-a', Money::parse($amount, 'RUB'), $digest, duePolicy: $policy,
        ))->orThrow();
    }

    /**
     * @param  array<string, mixed>  $selector
     * @param  array<string, mixed>|null  $page
     */
    private function serverQuery(Host $host, Application $app, string $seller, string $query, array $selector, array $extra = []): Response
    {
        $payload = ['schema' => 'billium.query.v1', 'query' => $query, 'installationRef' => Host::INSTALLATION,
            'environment' => 'test', 'sellerRef' => $seller, 'selector' => $selector, ...$extra];

        return $host->request($app, '/billium/v1/sellers/'.$seller.'/queries', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private static function jsonBody(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

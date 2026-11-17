<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionReconciliationQueries;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\PaymentDriver;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\QualifiedInventoryDriver;
use Billium\Execution\Contracts\ReconciliationCommands;
use Billium\Execution\Contracts\RepairCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\DriverRequest;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\InventoryPage;
use Billium\Execution\Data\InventoryReportFact;
use Billium\Execution\Data\LookupRequest;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Data\QualifiedInventoryPage;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\ChildProcess;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
#[Group('PB-24')]
#[Group('PF-11')]
final class InventoryReconciliationTest extends ExecutionTestCase
{
    /** @param list<string> $permissions */
    public static function grant(FixedCallerContext $callers, array $permissions = ['execution.reconcile', 'execution.repair']): void
    {
        $c = $callers->current();
        $callers->set(new Caller($c->sellerRef, $c->environment, $c->installationRef, $c->actorRef, $c->sourceAuthorities, $c->intentOwnerNamespaces, $permissions));
    }

    public static function enableInventory(Application $app, string $path): void
    {
        $scope = Fixture::scope();
        $merchant = $app->make(ExecutionStore::class)->merchantRevision($scope, 'merchant-a');
        $app->make(OperationRunner::class)->run(new OperationSpec('execution.enable_inventory', $scope->sellerRef, $scope->environment, FinancialMode::Administrative,
            ModuleOperationClass::HistoricalRead, ['execution', 'platform'], financialScope: new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, 'remote-billium')),
            static function (TransactionContext $ctx) use ($app, $scope, $merchant): PlatformReceipt {
                $registry = $app->make(DatabaseMerchantRegistry::class);
                $from = new DateTimeImmutable('-1 day');
                $through = new DateTimeImmutable('+2 days');
                $registry->appendLifecycle($ctx, $scope, 'merchant-a', 'enable-inventory', 'enable_recovery', 'reconciliation', $from, $through, 'fixture qualification', 'approved');
                $registry->appendCredential($ctx, $merchant, 'cred-inventory', $merchant->credentialVersionRef, $merchant->credentialRef, 'reconciliation', $from, $through, 'proof-reconciliation', null);

                return new PlatformReceipt('execution.seed', ['enabled' => true]);
            })->orThrow();
        self::bindInventory($app, $path);
    }

    public static function bindInventory(Application $app, string $path): void
    {
        $original = $app->make(PaymentDrivers::class);
        $cap = SimulatedCapabilities::snapshot();
        $historical = $original->resolve($cap->driverId, $cap->driverContractVersion);
        $driver = new readonly class($historical, $path, $app->make(DatabaseManager::class)) implements PaymentDriver, QualifiedInventoryDriver
        {
            public function __construct(private PaymentDriver $payment, private string $path, private DatabaseManager $db) {}

            public function contractVersion(): string
            {
                return $this->payment->contractVersion();
            }

            public function execute(DriverRequest $driverRequest): ProviderLookupResult
            {
                return $this->payment->execute($driverRequest);
            }

            public function authorize(DriverRequest $driverRequest): ProviderLookupResult
            {
                return $this->payment->authorize($driverRequest);
            }

            public function capture(DriverRequest $driverRequest): ProviderLookupResult
            {
                return $this->payment->capture($driverRequest);
            }

            public function voidAuthorization(DriverRequest $driverRequest): ProviderLookupResult
            {
                return $this->payment->voidAuthorization($driverRequest);
            }

            public function refund(DriverRequest $driverRequest): ProviderLookupResult
            {
                return $this->payment->refund($driverRequest);
            }

            public function lookup(LookupRequest $lookupRequest): ProviderLookupResult
            {
                return $this->payment->lookup($lookupRequest);
            }

            public function normalize(ProviderEvidence $providerEvidence, CapabilitySnapshot $capabilitySnapshot): MaterialSnapshot
            {
                return $this->payment->normalize($providerEvidence, $capabilitySnapshot);
            }

            public function inventory(MerchantRevision $merchantRevision, ResolvedCredentials $resolvedCredentials, DateTimeImmutable $from, ?string $cursor): InventoryPage
            {
                return $this->payment->inventory($merchantRevision, $resolvedCredentials, $from, $cursor);
            }

            public function inventoryWindow(MerchantRevision $merchant, ResolvedCredentials $credentials, string $currency, string $sourceAuthority,
                DateTimeImmutable $from, DateTimeImmutable $through, ?string $cursor): QualifiedInventoryPage
            {
                foreach ($this->db->getConnections() as $connection) {
                    if ($connection->transactionLevel() !== 0) {
                        throw new RuntimeException('Inventory contacted inside transaction');
                    }
                }
                $file = $this->path.'.page.'.hash('sha256', $cursor ?? '');
                if (! is_file($file)) {
                    throw new RuntimeException('Provider export page is missing');
                }
                $document = file_get_contents($file);
                if ($document === false) {
                    throw new RuntimeException('Unreadable export');
                }
                $fields = ExecutionCodec::map($document);
                $items = [];
                foreach ((array) $fields['items'] as $line) {
                    if (! is_array($line)) {
                        throw new RuntimeException('Invalid fixture line');
                    }
                    $items[] = isset($line['evidenceRef']) ? ExecutionCodec::decode(ProviderEvidence::class, ExecutionCodec::encode($line))
                        : ExecutionCodec::decode(InventoryReportFact::class, ExecutionCodec::encode($line));
                }
                $next = $fields['next'] ?? null;

                $route = (new Codec)->digest(['schema' => 'billium.execution.inventory-route.v1',
                    'environment' => $merchant->scope->environment, 'installationRef' => $merchant->scope->installationRef,
                    'merchantAccountRef' => $merchant->merchantAccountRef, 'sourceAuthority' => $sourceAuthority, 'currency' => $currency,
                    'ownerNamespace' => $merchant->scope->intentOwnerNamespace, 'sellerRef' => $merchant->scope->sellerRef,
                    'merchantRevisionRef' => $merchant->revisionRef, 'merchantRevisionDigest' => $merchant->digest, 'providerId' => $merchant->providerId,
                    'credentialVersionRef' => $credentials->credentialVersionRef]);

                return new QualifiedInventoryPage(self::given($fields, 'route', $route), self::given($fields, 'credential', $credentials->credentialVersionRef),
                    self::given($fields, 'currency', $currency), isset($fields['wrongFrom']) ? $from->modify('-1 second') : $from, $through,
                    is_string($fields['request'] ?? null) ? $fields['request'] : $cursor, is_string($next) ? $next : null,
                    self::given($fields, 'report', 'independent-report'), self::given($fields, 'qualification', 'simulated-qualification.v1'), 'sha256:'.hash('sha256', $document),
                    $next === null && ! isset($fields['unattested']) ? $through : null, $items, self::given($fields, 'source', $sourceAuthority), self::given($fields, 'owner', $merchant->scope->intentOwnerNamespace));
            }

            /** @param array<string,mixed> $fields */
            private static function given(array $fields, string $key, string $default): string
            {
                return is_string($fields[$key] ?? null) ? $fields[$key] : $default;
            }
        };
        $app->instance(PaymentDrivers::class, new readonly class($driver, $original) implements PaymentDrivers
        {
            public function __construct(private PaymentDriver $driver, private PaymentDrivers $original) {}

            public function resolve(string $driverId, string $contractVersion): PaymentDriver
            {
                $this->original->resolve($driverId, $contractVersion);

                return $this->driver;
            }

            public function capabilities(MerchantRevision $merchant): CapabilitySnapshot
            {
                return $this->original->capabilities($merchant);
            }
        });
    }

    /**
     * @param  list<ProviderEvidence|InventoryReportFact>  $items
     * @param  array<string,string>  $response
     */
    public static function page(string $path, array $items, ?string $cursor = null, ?string $next = null, ?string $request = null, array $response = []): void
    {
        file_put_contents($path.'.page.'.hash('sha256', $cursor ?? ''), ExecutionCodec::encode(['items' => $items, 'next' => $next, 'request' => $request] + $response));
    }

    public static function clear(string $path): void
    {
        $files = glob($path.'.page.*');
        foreach (is_array($files) ? $files : [] as $file) {
            unlink($file);
        }
        if (is_file($path.'.restart')) {
            unlink($path.'.restart');
        }
        UnknownResultTest::clean($path);
    }

    public static function scan(Application $app, string $run, DateTimeImmutable $from, DateTimeImmutable $through): string
    {
        $result = $app->make(ReconciliationCommands::class)->inventory(Fixture::scope(), 'remote-billium', $run, 'merchant-a', 'RUB', $from, $through)->orThrow();

        return self::text($result->body['status']);
    }

    public static function restart(string $path, string $from, string $through): void
    {
        $app = Fixture::workerApp(913);
        $caller = $app->make(CallerContext::class);
        self::assertInstanceOf(FixedCallerContext::class, $caller);
        self::grant($caller);
        Fixture::host($app, $path);
        self::bindInventory($app, $path);
        $status = self::scan($app, 'inventory-one', new DateTimeImmutable($from), new DateTimeImmutable($through));
        $backend = $app->make(DatabaseManager::class)->connection()->scalar('SELECT pg_backend_pid()');
        file_put_contents($path.'.restart', json_encode([$status, getmypid(), $backend], JSON_THROW_ON_ERROR));
    }

    public function test_missing_page_resume_and_overlapping_signed_effects_preserve_original_account_horizon_and_never_send(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            $event = CallbackCustodyTest::dispatched($app, $path);
            self::enableInventory($app, $path);
            self::grant($this->callers);
            $from = new DateTimeImmutable('-1 hour');
            $through = new DateTimeImmutable;
            $lost = CallbackCustodyTest::signed($event, ['operationRef' => 'lost-after-backup', 'reference' => 'archived-PSP-ref', 'stableEffectId' => 'external-identity', 'providerOperationId' => 'external-assigned']);
            self::page($path, [$event], next: 'p2');
            self::assertSame('running', self::scan($app, 'inventory-one', $from, $through));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100'));
            try {
                self::scan($app, 'inventory-one', $from, $through);
                self::fail('Missing page advanced the run');
            } catch (RuntimeException $e) {
                self::assertSame('Provider export page is missing', $e->getMessage());
            }
            self::assertSame(1, $this->rows('billium_execution_reconciliation_run', "state='incomplete' AND inventory_cursor='p2' AND completeness_watermark IS NULL"));
            self::page($path, [$lost], cursor: 'p2', request: 'skipped');
            HistoricalMerchantRefundTest::problem(ProblemCode::RecoveryRequired, static fn (): string => self::scan($app, 'inventory-one', $from, $through));
            foreach (['route' => 'sha256:'.str_repeat('0', 64), 'credential' => 'foreign-version', 'currency' => 'USD', 'wrongFrom' => '1',
                'report' => 'replaced-snapshot', 'source' => 'standalone-billium', 'owner' => 'another-owner', 'qualification' => 'unknown-proof', 'unattested' => '1'] as $key => $value) {
                self::page($path, [$lost], cursor: 'p2', response: [$key => $value]);
                HistoricalMerchantRefundTest::problem(ProblemCode::RecoveryRequired, static fn (): string => self::scan($app, 'inventory-one', $from, $through));
            }
            self::assertSame(1, $this->rows('billium_execution_recon_page'));
            self::assertSame(0, $this->rows('billium_execution_reconciliation_run', 'completeness_watermark IS NOT NULL'));
            self::page($path, [$lost], cursor: 'p2');
            $original = $app->make(EvidenceReducer::class);
            $stopped = self::createStub(EvidenceReducer::class);
            $stopped->method('reduce')->willThrowException(new RuntimeException('Stopped immediately after inventory custody'));
            $app->instance(EvidenceReducer::class, $stopped);
            try {
                self::scan($app, 'inventory-one', $from, $through);
                self::fail('Expected interruption after custody');
            } catch (RuntimeException $failure) {
                self::assertSame('Stopped immediately after inventory custody', $failure->getMessage());
            } finally {
                $app->instance(EvidenceReducer::class, $original);
            }
            self::assertSame(1, $this->rows('billium_execution_namespace', "state='recovery_required' AND completeness_receipt_ref LIKE 'comparison:%'"));
            self::assertSame(1, $this->rows('billium_execution_recon_review', "code='missing_local_effect' AND root_ref IS NULL AND status='open'"));
            self::assertSame(1, $this->rows('billium_execution_recon_item'));
            self::assertSame(0, $this->rows('billium_execution_reconciliation_run', 'completeness_watermark IS NOT NULL'));
            $worker = new ChildProcess([PHP_BINARY, '-r', 'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Feature\\InventoryReconciliationTest::restart($argv[1],$argv[2],$argv[3]);',
                $path, $from->format('Y-m-d H:i:s.uP'), $through->format('Y-m-d H:i:s.uP')]);
            self::assertSame(0, $worker->wait());
            $witness = file_get_contents($path.'.restart');
            self::assertIsString($witness);
            $report = json_decode($witness, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($report);
            self::assertSame('complete', $report[0]);
            self::assertSame($worker->pid, $report[1]);
            self::assertNotEquals($this->value('SELECT pg_backend_pid()'), $report[2]);
            self::assertSame(1, $this->rows('billium_execution_recon_review', "code='missing_local_effect' AND root_ref IS NULL AND status='open'"));
            self::assertSame(2, $this->rows('billium_execution_recon_item'));
            self::assertSame(1, $this->rows('billium_execution_namespace', "state='recovery_required' AND completeness_receipt_ref LIKE 'comparison:%'"));
            self::page($path, [$event, $lost]);
            self::assertSame('complete', self::scan($app, 'inventory-two', $through->modify('-2 minutes'), new DateTimeImmutable));
            self::assertSame(1, $this->rows('billium_execution_provider_fact'));
            self::assertSame(1, $this->rows('billium_execution_recon_review'));
            self::assertSame(1, $this->rows('billium_execution_operation'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            HistoricalMerchantRefundTest::problem(ProblemCode::RecoveryRequired, static fn (): string => self::scan($app, 'gap', new DateTimeImmutable('-1 microsecond'), new DateTimeImmutable));
            Fixture::host($app, $path, [Fixture::origin(context: 'support', operation: 'new-after-loss')]);
            $nextPayment = Fixture::wire(['source' => ['context' => 'support', 'intentRef' => 'intent-a'], 'commandKey' => Fixture::key(6), 'operationRef' => 'new-after-loss']);
            HistoricalMerchantRefundTest::problem(ProblemCode::RecoveryRequired, static fn () => $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), $nextPayment)->orThrow());
            self::assertSame(1, $this->rows('billium_execution_operation'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            self::grant($this->callers, []);
            HistoricalMerchantRefundTest::problem(ProblemCode::AuthDenied, static fn (): string => self::scan($app, 'inventory-one', $from, $through));
        } finally {
            self::clear($path);
        }
    }

    public function test_a_replaced_partial_page_or_wrong_currency_cannot_cover_the_account_or_reapply_money(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            $payment = CallbackCustodyTest::dispatched($app, $path);
            self::enableInventory($app, $path);
            self::grant($this->callers);
            $at = new DateTimeImmutable;
            $bad = new InventoryReportFact('fee', 'fee-foreign', '1', 'root-a', new Money(3, Currency::of('USD')), $at, 'sha256:'.hash('sha256', 'fee-statement'));
            self::page($path, [$payment, $bad]);
            $from = $at->modify('-5 minutes');
            $through = new DateTimeImmutable;
            HistoricalMerchantRefundTest::problem(ProblemCode::ValidationInvalid, static fn (): string => self::scan($app, 'changed-report', $from, $through));
            self::assertSame(1, $this->rows('billium_execution_recon_item'));
            self::assertSame(1, $this->rows('billium_execution_recon_page', 'item_count=2'));
            self::assertSame(1, $this->rows('billium_execution_reconciliation_run', "state='incomplete' AND completeness_watermark IS NULL"));
            self::page($path, [$payment]);
            HistoricalMerchantRefundTest::problem(ProblemCode::RecoveryRequired, static fn (): string => self::scan($app, 'changed-report', $from, $through));
            $foreign = new ProviderEvidence($payment->evidenceRef, $payment->scope, $payment->providerId, 'other-account', $payment->providerEventId,
                $payment->payloadDigest, $payment->signatureVerdict, $payment->handlerContractVersion, $payment->receivedAt, $payment->sanitizedPayload, $payment->securePayloadRef);
            self::page($path, [$foreign]);
            HistoricalMerchantRefundTest::problem(ProblemCode::AuthDenied, static fn (): string => self::scan($app, 'foreign-account', $from, $through));
            self::assertSame(0, $this->rows('billium_execution_reconciliation_run', 'completeness_watermark IS NOT NULL'));
            self::assertSame(1, $this->rows('billium_execution_provider_fact'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            self::clear($path);
        }
    }

    public function test_targeted_unknown_recovery_applies_original_signed_lookup_and_preserves_no_send_for_admitted(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            Fixture::seedMerchant($app);
            Fixture::host($app, $path, mode: ResponseMode::AcceptedThenTimeout);
            self::grant($this->callers);
            $scope = Fixture::scope();
            $app->make(RootPaymentCommands::class)->admit($scope, Fixture::wire())->orThrow();
            $recovery = $app->make(ReconciliationCommands::class);
            $attempt = UnknownResultTest::attemptRef();
            $lease = new DateTimeImmutable('+1 minute');
            self::assertSame('parked', $recovery->targeted($scope, 'admitted', $attempt, 'operator', $lease)->orThrow()->body['status']);
            self::assertSame(0, UnknownResultTest::events($path, 'provider.call'));
            $app->make(AttemptWorker::class)->dispatch($scope, $attempt, 'send', $lease)->orThrow();
            usleep(1100000);
            self::assertSame('complete', $recovery->targeted($scope, 'unknown', $attempt, 'recover', $lease)->orThrow()->body['status']);
            self::assertSame('unknown', $recovery->targeted($scope, 'unknown', $attempt, 'recover', $lease)->orThrow()->body['receiptRef']);
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.lookup'));
            self::assertSame(1, $this->rows('billium_execution_provider_fact'));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100'));
        } finally {
            self::clear($path);
        }
    }

    public function test_unsupported_historical_inventory_parks_a_visible_case_without_issuing_coverage_or_provider_work(): void
    {
        $app = $this->application();
        self::provisionCursorKey($app);
        $path = Fixture::tempPath();
        try {
            Fixture::seedMerchant($app);
            self::assertFalse($app->bound(PaymentDrivers::class));
            self::assertFalse($app->bound(AdapterQualification::class));
            self::assertInstanceOf(RepairCommands::class, $app->make(RepairCommands::class));
            $recovery = $app->make(ReconciliationCommands::class);
            HistoricalMerchantRefundTest::problem(ProblemCode::ReferenceUnknown, static fn () => $recovery->targeted(Fixture::scope(), 'missing-attempt', 'never-admitted', 'worker', new DateTimeImmutable('+1 minute')));
            self::grant($this->callers);
            $from = new DateTimeImmutable('-1 hour');
            $through = new DateTimeImmutable;
            HistoricalMerchantRefundTest::problem(ProblemCode::CapabilityUnsupported, static fn (): string => self::scan($app, 'unsupported-inventory', $from, $through));
            self::assertSame('parked', self::scan($app, 'unsupported-inventory', $from, $through));
            self::assertSame(1, $this->rows('billium_execution_reconciliation_run', "state='parked' AND completeness_watermark IS NULL AND manifest_digest IS NULL"));
            $review = $app->make(ExecutionReconciliationQueries::class)->dueReview(Fixture::scope(), 'remote-billium', new DateTimeImmutable);
            self::assertCount(1, $review->rows);
            self::assertSame('manual_required', $review->rows[0]->code);
            self::assertSame('inventory_unsupported', $review->rows[0]->observed['lookupState']);
            self::assertSame(0, $this->rows('billium_execution_recon_page'));
            self::assertSame(0, $this->rows('billium_execution_provider_work'));
            self::assertSame(0, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            self::clear($path);
        }
    }

    public static function provisionCursorKey(Application $app): void
    {
        $app->make(Repository::class)->set('app.key', 'base64:'.base64_encode(hash('sha256', 'simulated persistent reconciliation queue host key', true)));
    }

    public static function restartQueues(string $path, string $asOf, string $recoverCursor, string $reviewCursor): void
    {
        $app = Fixture::workerApp(914);
        self::provisionCursorKey($app);
        $caller = $app->make(CallerContext::class);
        self::assertInstanceOf(FixedCallerContext::class, $caller);
        self::grant($caller);
        $queries = $app->make(ExecutionReconciliationQueries::class);
        $until = new DateTimeImmutable($asOf);
        $scope = Fixture::scope();
        $next = $queries->dueRecovery($scope, 'remote-billium', AttemptState::Unknown, $until, 1, $recoverCursor);
        $reviews = $queries->openDiscrepancies($scope, 'remote-billium', 'open', $until, 1, $reviewCursor);
        $db = $app->make(DatabaseManager::class)->connection();
        file_put_contents($path.'.restart', json_encode([getmypid(), $db->scalar('SELECT pg_backend_pid()'), $next->rows[0]->attemptRef,
            $reviews->rows[0]->evidenceRef, $reviews->rows[0]->expected, $reviews->rows[0]->observed, $reviews->rows[0]->code], JSON_THROW_ON_ERROR));
    }

    public function test_due_recovery_and_latest_review_queries_seek_scoped_indexes_and_resume_authenticated_cursors_after_restart(): void
    {
        $app = $this->application();
        self::provisionCursorKey($app);
        $path = Fixture::tempPath();
        try {
            Fixture::seedMerchant($app);
            Fixture::host($app, $path, mode: ResponseMode::AcceptedThenTimeout);
            self::grant($this->callers);
            $scope = Fixture::scope();
            $app->make(RootPaymentCommands::class)->admit($scope, Fixture::wire())->orThrow();
            $app->make(AttemptWorker::class)->dispatch($scope, UnknownResultTest::attemptRef(), 'worker', new DateTimeImmutable('+1 minute'))->orThrow();
            self::enableInventory($app, $path);
            self::page($path, [DisputeSettlementTest::line('fee', 2, 'fee-a'), DisputeSettlementTest::line('settlement', 95, 'settlement-b')]);
            self::scan($app, 'plan-input', new DateTimeImmutable('-1 day'), new DateTimeImmutable);
            $owner = $this->owner();
            // Populate future and cross-seller history so the optimizer can distinguish a selective due index.
            $owner->beginTransaction();
            $owner->statement(<<<'SQL'
                INSERT INTO billium_execution_child_identity
                    (installation_ref,seller_ref,environment,intent_owner_namespace,source_context,business_intent_ref,operation_kind,operation_ref,
                    root_operation_ref,semantic_digest,canonicalization_version,binding,requested_minor,currency,currency_exponent,receipt_ref)
                SELECT installation_ref,seller_ref,environment,intent_owner_namespace,source_context,'plan-child-'||n,'capture','q-op-'||n,
                    root_operation_ref,economic_digest,binding->>'canonicalizationVersion',binding,requested_minor,currency,currency_exponent,'q-receipt-'||n
                FROM billium_execution_operation CROSS JOIN generate_series(1,2048) n WHERE operation_ref='root-a'
                SQL);
            foreach ([
                'operation' => ['kind' => "'capture'", 'operation_ref' => "'q-op-'||n"],
                'attempt' => ['operation_ref' => "'q-op-'||n", 'attempt_ref' => "'q-attempt-'||n", 'provider_reference' => "'q-ref-'||n"],
                'dispatch_work' => ['operation_ref' => "'q-op-'||n", 'attempt_ref' => "'q-attempt-'||n", 'work_ref' => "'q-work-'||n",
                    'due_at' => "source.due_at + (CASE WHEN n=1 THEN interval '1 second' ELSE interval '3 days' END)"],
                'recon_review' => ['seller_ref' => "CASE WHEN n%2=0 THEN 'seller-b' ELSE source.seller_ref END",
                    'case_ref' => "'q-case-'||n", 'event_ref' => "'q-event-'||n",
                    'created_at' => "source.created_at + CASE WHEN n%2=0 THEN interval '-1 day' ELSE interval '3 days' END",
                    'next_review_at' => "source.next_review_at + CASE WHEN n%2=0 THEN interval '-1 day' ELSE interval '3 days' END"],
            ] as $name => $expressions) {
                $table = 'billium_execution_'.$name;
                $source = $owner->table($table)->orderBy('id')->first();
                self::assertNotNull($source);
                $columns = array_values(array_diff(array_keys(get_object_vars($source)), ['id']));
                $values = array_map(static fn (string $col): string => $expressions[$col] ?? 'source.'.$col, $columns);
                $owner->statement('INSERT INTO '.$table.' ('.implode(',', $columns).') SELECT '.implode(',', $values).' FROM (SELECT * FROM '.$table.' ORDER BY id LIMIT 1) source CROSS JOIN generate_series(1,2048) n');
                $owner->statement('ANALYZE '.$table);
            }
            $owner->commit();
            $queries = $app->make(ExecutionReconciliationQueries::class);
            $db = $app->make(DatabaseManager::class)->connection();
            $until = new DateTimeImmutable('+1 hour');
            $db->enableQueryLog();
            $recovery = $queries->dueRecovery($scope, 'remote-billium', AttemptState::Unknown, $until, 1);
            $review = $queries->openDiscrepancies($scope, 'remote-billium', 'open', $until, 1);
            $due = $queries->dueReview($scope, 'remote-billium', $until, 1);
            $log = $db->getQueryLog();
            $db->disableQueryLog();
            self::assertCount(1, $recovery->rows);
            self::assertSame(UnknownResultTest::attemptRef(), $recovery->rows[0]->attemptRef);
            self::assertSame('account-a', $recovery->rows[0]->merchantAccountRef);
            self::assertSame(1, $recovery->rows[0]->dispatchWorkAttempts);
            self::assertNotNull($recovery->rows[0]->evidenceRef);
            self::assertSame('fee-a', $review->rows[0]->evidenceRef);
            self::assertSame('fee-a', $due->rows[0]->evidenceRef);
            self::assertNotNull($review->nextCursor);
            self::assertNotNull($recovery->nextCursor);
            $worker = new ChildProcess([PHP_BINARY, '-r', 'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Feature\\InventoryReconciliationTest::restartQueues(...array_slice($argv,1));',
                $path, $until->format('Y-m-d H:i:s.uP'), $recovery->nextCursor, $review->nextCursor]);
            self::assertSame(0, $worker->wait());
            $witness = file_get_contents($path.'.restart');
            self::assertIsString($witness);
            $saved = json_decode($witness, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($saved);
            self::assertSame($worker->pid, $saved[0]);
            self::assertNotEquals($this->value('SELECT pg_backend_pid()'), $saved[1]);
            self::assertSame(['q-attempt-1', 'settlement-b'], array_slice($saved, 2, 2));
            self::assertIsArray($saved[4]);
            self::assertIsArray($saved[5]);
            self::assertSame(0, $saved[4]['capturedMinor']);
            self::assertSame(95, $saved[5]['minor']);
            self::assertSame('settlement', $saved[6]);
            foreach (['dispatch_work' => 'billium_execution_recovery_queue_clock', 'r.created_at as sort_at' => 'billium_execution_recon_review_open',
                'r.next_review_at as sort_at' => 'billium_execution_recon_review_due'] as $selector => $index) {
                $statement = array_find($log, static fn (array $entry): bool => str_contains(self::text($entry['query']), $selector));
                self::assertNotNull($statement);
                /** @var list<mixed> $bindings */
                $bindings = $statement['bindings'];
                $plan = self::text($owner->scalar('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.self::text($statement['query']), $bindings));
                fwrite(STDOUT, "\nReconciliation queue ".$index.' '.$plan."\n");
                self::assertStringContainsString($index, $plan);
            }
            HistoricalMerchantRefundTest::problem(ProblemCode::ValidationInvalid, static fn () => $queries->dueReview($scope, 'remote-billium', $until, 1, $review->nextCursor));
            HistoricalMerchantRefundTest::problem(ProblemCode::ValidationInvalid, static fn () => $queries->openDiscrepancies($scope, 'remote-billium', 'open', $until, 1, 'invalid-cursor'));
            HistoricalMerchantRefundTest::problem(ProblemCode::ValidationInvalid, static fn () => $queries->dueRecovery($scope, 'remote-billium', AttemptState::Unknown, $until, 101));
            $this->runner()->run($this->spec('execution.hold_queue_fixture', class: ModuleOperationClass::UnknownResolution, modules: ['execution', 'platform']),
                static function (TransactionContext $ctx) use ($app, $scope): PlatformReceipt {
                    $store = $app->make(ExecutionStore::class);
                    $namespace = $store->namespace($scope, $ctx);
                    $store->transitionNamespace($ctx, $scope, NamespaceState::RecoveryRequired, $namespace->generation, 'retained-queue-incident');

                    return new PlatformReceipt('execution.hold_queue_fixture', ['held' => true]);
                })->orThrow();
            self::assertSame([], $queries->dueRecovery($scope, 'remote-billium', AttemptState::Unknown, $until)->rows);
            self::grant($this->callers, ['execution.reconcile', 'execution.recover_held']);
            $held = $queries->dueRecovery($scope, 'remote-billium', AttemptState::Unknown, $until);
            self::assertCount(2, $held->rows);
            self::assertSame('recovery_required', $held->rows[0]->namespaceState);
            $foreign = new ExecutionScope($scope->installationRef, 'seller-b', $scope->environment, $scope->intentOwnerNamespace);
            HistoricalMerchantRefundTest::problem(ProblemCode::AuthDenied, static fn () => $queries->dueReview($foreign, 'remote-billium', $until));
            $this->callers->as('seller-b');
            self::grant($this->callers);
            HistoricalMerchantRefundTest::problem(ProblemCode::AuthDenied, static fn () => $queries->dueReview($scope, 'remote-billium', $until));
            HistoricalMerchantRefundTest::problem(ProblemCode::ValidationInvalid, static fn () => $queries->openDiscrepancies($foreign, 'remote-billium', 'open', $until, 1, $review->nextCursor));
            self::assertSame([], $queries->dueRecovery($foreign, 'remote-billium', AttemptState::Unknown, $until)->rows);
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(0, UnknownResultTest::events($path, 'provider.lookup'));
        } finally {
            self::clear($path);
        }
    }
}

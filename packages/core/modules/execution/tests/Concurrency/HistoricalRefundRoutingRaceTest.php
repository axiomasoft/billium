<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Concurrency;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\MerchantRevisionPolicy;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessBarrier;
use Billium\Testkit\Fixtures\ProcessIdentity;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class HistoricalRefundRoutingRaceTest extends ExecutionTestCase
{
    #[Group('ACC-CON-25')]
    public function test_route_switch_and_historical_refund_serialize_on_the_original_merchant(): void
    {
        $path = AdmissionIdentityTest::tempPath();
        $control = new DurableJournal($path.'.control');
        $barrier = new ProcessBarrier($control);
        $children = [];
        try {
            $app = $this->application();
            $scope = AdmissionIdentityTest::scope();
            RefundCapacityTest::captured($app, $path);
            $root = $this->row("SELECT binding,binding_digest,merchant_revision_ref,merchant_account_ref FROM billium_execution_operation WHERE operation_ref='root-a'");
            self::assertSame(1, $this->rows('billium_execution_operation', "operation_ref='root-a' AND state='succeeded'"));
            $route = $children[] = self::start($path, 'route');
            $refund = $children[] = self::start($path, 'refund');
            $barrier->await('ready', 2, 15_000);
            $barrier->arrive('start');
            $barrier->await('route-locked', 1, 10_000);
            $routeBackend = self::backend($control, 'route');
            $refundBackend = self::backend($control, 'refund');
            self::assertNotSame($route->pid, $refund->pid);
            self::assertNotSame($routeBackend, $refundBackend);
            $deadline = hrtime(true) + 5_000_000_000;
            do {
                $blocked = $this->value('SELECT ?::integer = ANY(pg_blocking_pids(?::integer))', [$routeBackend, $refundBackend]);
                if ($blocked === true) {
                    break;
                }
                usleep(2_000);
            } while (hrtime(true) < $deadline);
            self::assertTrue($blocked, 'PostgreSQL must show refund admission waiting on the route switch');
            self::assertSame(0, $this->rows('billium_execution_operation', "kind='refund'"));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));

            $barrier->arrive('commit-route');
            while ($children !== []) {
                self::assertSame(0, array_shift($children)->wait());
            }
            $registry = $app->make(MerchantRegistry::class);
            self::assertSame('replacement-account', $registry->forAdmission($scope, 'replacement')->merchantAccountRef);
            try {
                $registry->forAdmission($scope, 'merchant-a');
                self::fail('Stopped merchant still accepts new payment admissions');
            } catch (ProblemException $exception) {
                self::assertSame(ProblemCode::ValidationInvalid, $exception->problem);
            }
            self::assertSame($root, $this->row("SELECT binding,binding_digest,merchant_revision_ref,merchant_account_ref FROM billium_execution_operation WHERE operation_ref='racing-refund'"));
            self::assertSame(0, $this->rows('billium_execution_operation', "kind='refund' AND merchant_revision_ref='replacement'"));
            try {
                $this->owner()->statement(<<<'SQL'
                    INSERT INTO billium_execution_operation (installation_ref,seller_ref,environment,operation_ref,root_operation_ref,kind,
                        binding,binding_digest,business_intent_ref,intent_owner_namespace,merchant_revision_ref,merchant_account_ref,
                        driver_id,driver_contract_version,capability_digest,requested_minor,currency,currency_exponent,state,economic_digest)
                    SELECT installation_ref,seller_ref,environment,'wrong-binding',root_operation_ref,kind,
                        binding || '{"merchantRevisionRef":"replacement","merchantAccountRef":"replacement-account"}'::jsonb,
                        binding_digest,'wrong-binding',intent_owner_namespace,'replacement','replacement-account',
                        driver_id,driver_contract_version,capability_digest,requested_minor,currency,currency_exponent,'admitted',economic_digest
                    FROM billium_execution_operation WHERE operation_ref='racing-refund'
                    SQL);
                self::fail('PostgreSQL accepted the replacement binding for a historical refund');
            } catch (QueryException $exception) {
                self::assertSame('23514', $exception->errorInfo[0] ?? null);
                self::assertStringContainsString('child historical binding mismatch', $exception->getMessage());
            }
            self::assertSame(1, $this->rows('billium_execution_operation', "kind='refund'"));
            $app->make(AttemptWorker::class)->dispatch($scope, RefundCapacityTest::attemptRef('racing-refund'), 'refund-worker', new DateTimeImmutable('+1 minute'))->orThrow();
            $sent = array_find(new DurableJournal($path)->records(), static fn (object $event): bool => $event instanceof ProviderRecord && $event->kind === EffectKind::Refund);
            self::assertInstanceOf(ProviderRecord::class, $sent);
            self::assertSame(['merchant-a', 'account-a'], [$sent->request->binding->merchantRevisionRef, $sent->request->credentials->merchantAccountRef]);
            self::assertSame(2, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            $barrier->arrive('commit-route');
            foreach ($children as $child) {
                $child->wait();
            }
            UnknownResultTest::clean($path);
        }
    }

    public static function process(string $path, string $role): void
    {
        $scope = AdmissionIdentityTest::scope();
        $app = AdmissionIdentityTest::workerApp($role === 'route' ? 118 : 119);
        $control = new DurableJournal($path.'.control');
        $barrier = new ProcessBarrier($control);
        $db = $app->make(DatabaseManager::class)->connection();
        if ($role === 'refund') {
            AdmissionIdentityTest::host($app, $path);
        }
        $control->append(new JournalEvent($role, ['backend' => self::integer($db->scalar('SELECT pg_backend_pid()'))], ProcessIdentity::current()));
        $barrier->arrive('ready');
        $barrier->await('start', 1, 15_000);
        if ($role === 'refund') {
            $barrier->await('route-locked', 1, 10_000);
            $app->make(RefundCommands::class)->admit($scope, RefundCapacityTest::refund('racing-refund', 25, 18))->orThrow();

            return;
        }
        $runner = $app->make(OperationRunner::class);
        $selection = new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, 'remote-billium');
        $admin = new OperationSpec('execution.switch_route', $scope->sellerRef, $scope->environment, FinancialMode::Administrative,
            ModuleOperationClass::HistoricalRead, ['execution', 'platform'], financialScope: $selection);
        $old = $app->make(MerchantRegistry::class)->historical($scope, 'root-a');
        $next = SimulatedCapabilities::snapshot('replacement-account');
        $registry = $app->make(DatabaseMerchantRegistry::class);
        $store = $app->make(ExecutionStore::class);
        $runner->run($admin, static function (TransactionContext $ctx) use ($scope, $old, $next, $registry, $store, $barrier): PlatformReceipt {
            $time = new DateTimeImmutable('-1 minute');
            $registry->appendLifecycle($ctx, $scope, $old->revisionRef, 'stop-old-route', 'stop_new', 'new', $time, null, 'approve route switch', 'route-evidence');
            $replacement = new MerchantRevision('replacement', $scope, $next->merchantAccountRef, 'simulated-provider', $next->driverId, $next->driverContractVersion,
                $next->snapshotRef, $next->digest, new SecretRef('secret://vault/replacement_account'), 'new-v1', $next->currencies, $old->digest, $time,
                new MerchantRevisionPolicy($time, null, $old->revisionRef, 'sim-api-v2', 'replacement-webhook', 'replacement-proof', 'replacement-settlement', 'replacement-mandate'));
            $store->appendMerchantRevision($ctx, $replacement, $next);
            $registry->appendLifecycle($ctx, $scope, $replacement->revisionRef, 'enable-new-route', 'enable_new', 'new', $time, null, 'approve route switch', 'route-evidence');
            $barrier->arrive('route-locked');
            $barrier->await('commit-route', 1, 10_000);

            return self::receipt();
        })->orThrow();
    }

    private static function start(string $path, string $role): ChildProcess
    {
        return new ChildProcess([PHP_BINARY, '-r',
            'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Concurrency\\HistoricalRefundRoutingRaceTest::process($argv[1], $argv[2]);', $path, $role]);
    }

    private static function backend(DurableJournal $control, string $role): int
    {
        $event = array_find($control->records(), static fn (object $entry): bool => $entry instanceof JournalEvent && $entry->kind === $role);
        self::assertInstanceOf(JournalEvent::class, $event);

        return self::integer($event->data['backend']);
    }
}

<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\TestEnvironment;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Data\AdmissionPlan;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\MerchantRevisionPolicy;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Internal\Merchants\ContinuityMerchantCredentials;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Internal\Storage\DatabaseExecutionStore;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;

final class MerchantRevisionTest extends ExecutionTestCase
{
    protected function seedRoot(): DatabaseExecutionStore
    {
        $store = $this->application()->make(DatabaseExecutionStore::class);
        $registry = $this->application()->make(DatabaseMerchantRegistry::class);
        $this->runner()->run($this->spec(kind: 'execution.schema_admin', mode: FinancialMode::Administrative, class: ModuleOperationClass::HistoricalRead, modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store, $registry) {
            $store->appendMerchantRevision($ctx, ExecutionSchemaTest::merchantFixture(), ExecutionSchemaTest::capabilityFixture());
            $registry->appendLifecycle($ctx, ExecutionSchemaTest::scopeFixture(), 'merchant-a', 'enable-a', 'enable_new', 'new', new DateTimeImmutable('2026-01-01T00:00:00Z'), null, 'test enable', 'authority-enable-a');

            return self::receipt();
        });
        $this->runner()->run($this->spec(kind: 'execution.schema_admit', modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store) {
            $store->transitionNamespace($ctx, ExecutionSchemaTest::scopeFixture(), NamespaceState::Open, 0, 'attested-complete-history');
            $b = new FrozenBinding(ExecutionSchemaTest::scopeFixture(), 'remote-billium', 'intent-a', 'root-a', 'merchant-a', 'account-a', 'driver-a', 'driver.v1', 'sha256:'.str_repeat('a', 64), 'sha256:'.str_repeat('c', 64), 'execution.intent.v1', null, 'commerz');
            self::assertNull($store->insertBinding($ctx, $b));
            $p = new AdmissionPlan(ExecutionSchemaTest::scopeFixture(), $b, 'root-a', EffectKind::Charge, new Money(1000, Currency::of('RUB')), 'command-a', 'sha256:'.str_repeat('c', 64), 'execution.intent.v1', ['execution', 'platform'], [], null);
            $store->insertOperation($ctx, $p);

            return self::receipt();
        });

        return $store;
    }

    private function registry(): DatabaseMerchantRegistry
    {
        return $this->application()->make(DatabaseMerchantRegistry::class);
    }

    /** @param callable(TransactionContext):void $body */
    private function admin(callable $body): void
    {
        $this->runner()->run($this->spec(kind: 'execution.merchant_admin', mode: FinancialMode::Administrative, class: ModuleOperationClass::HistoricalRead, modules: ['execution', 'platform']), static function (TransactionContext $ctx) use ($body) {
            $body($ctx);

            return self::receipt();
        });
    }

    public function test_rotation_keeps_historical_original_account_and_exact_credential_permission(): void
    {
        $store = $this->seedRoot();
        $old = ExecutionSchemaTest::merchantFixture();
        $new = new MerchantRevision('merchant-new', $old->scope, 'account-new', 'provider-a', 'driver-a', 'driver.v1', 'cap-new', $old->capabilityDigest, new SecretRef('secret://vault/new_account'), 'new-v1', ['RUB'], $old->digest, $old->createdAt, new MerchantRevisionPolicy($old->createdAt, null, 'merchant-a', 'api-v2', 'webhook-new', 'new-account-proof', 'settlement-new', 'mandate-new'));
        $registry = $this->registry();
        $this->admin(function (TransactionContext $ctx) use ($store, $registry, $old, $new) {
            $store->appendMerchantRevision($ctx, $new, ExecutionSchemaTest::capabilityFixture('cap-new', 'account-new'));
            $registry->appendLifecycle($ctx, $old->scope, 'merchant-a', 'stop-old', 'stop_new', 'new', new DateTimeImmutable('2026-02-01'), null, 'rotate route', 'proof-stop');
            $registry->appendLifecycle($ctx, $old->scope, 'merchant-a', 'recover-old', 'enable_recovery', 'refund', new DateTimeImmutable('2026-02-01'), new DateTimeImmutable('2030-01-01'), 'historical refunds', 'proof-refund');
            $registry->appendCredential($ctx, $old, 'rotation-a', 'rotated-v2', new SecretRef('secret://vault/old_account_v2'), 'refund', new DateTimeImmutable('2026-02-01'), new DateTimeImmutable('2030-01-01'), 'authenticated-continuity-a', null);
        });
        self::assertEquals($old, $registry->historical($old->scope, 'root-a'));
        $host = new class implements MerchantCredentials
        {
            public function resolve(MerchantRevision $revision, string $purpose): ResolvedCredentials
            {
                return new ResolvedCredentials('account-a', 'rotated-v2', new SecretRef('secret://vault/old_account_v2'), $purpose, new DateTimeImmutable('2027-01-01'), 'authenticated-continuity-a');
            }
        };
        $resolver = new ContinuityMerchantCredentials($host, $registry, $this->application()->make(DatabaseManager::class), $this->application()->make(BilliumOptions::class));
        $resolved = $resolver->resolve($old, 'refund');
        self::assertSame('account-a', $resolved->merchantAccountRef);
        self::assertSame('rotated-v2', $resolved->credentialVersionRef);
        self::assertSame('secret://vault/old_account_v2', $resolved->secretRef->locator);
        self::assertSame(2, $this->rows('billium_execution_merchant_revision'));
        self::assertSame(1, $this->rows('billium_execution_merchant_credential_revision'));
        $this->expectException(ProblemException::class);
        $registry->forAdmission($old->scope, 'merchant-a');
    }

    public function test_expired_configuration_can_retain_explicit_historical_recovery_permission(): void
    {
        $store = $this->application()->make(DatabaseExecutionStore::class);
        $registry = $this->registry();
        $base = ExecutionSchemaTest::merchantFixture();
        $old = new MerchantRevision($base->revisionRef, $base->scope, $base->merchantAccountRef, $base->providerId, $base->driverId, $base->driverContractVersion, $base->capabilitySnapshotRef, $base->capabilityDigest, $base->credentialRef, $base->credentialVersionRef, $base->currencyCodes, $base->digest, $base->createdAt, new MerchantRevisionPolicy($base->createdAt, new DateTimeImmutable('2026-02-01'), null, 'old-api', 'old-route', 'old-proof', 'settlement-old', 'mandate-old'));
        $this->admin(function (TransactionContext $ctx) use ($store, $registry, $old) {
            $store->appendMerchantRevision($ctx, $old, ExecutionSchemaTest::capabilityFixture());
            $registry->appendLifecycle($ctx, $old->scope, $old->revisionRef, 'enable-historical', 'enable_recovery', 'lookup', new DateTimeImmutable('2026-02-01'), new DateTimeImmutable('2030-01-01'), 'explicit historical horizon', 'proof-recovery');
        });
        self::assertTrue($registry->allowed($old->scope, $old->revisionRef, 'lookup', new DateTimeImmutable('2026-11-17')));
        self::assertFalse($registry->allowed($old->scope, $old->revisionRef, 'refund', new DateTimeImmutable('2026-11-17')));
        self::assertFalse($registry->allowed($old->scope, $old->revisionRef, 'lookup', new DateTimeImmutable('2031-01-01')));
        $this->expectException(ProblemException::class);
        $registry->forAdmission($old->scope, $old->revisionRef);
    }

    public function test_cross_seller_and_missing_continuity_refuse_without_new_account_redirect(): void
    {
        $this->seedRoot();
        $old = ExecutionSchemaTest::merchantFixture();
        $registry = $this->registry();
        $this->callers->as('seller-b');
        try {
            $registry->historical($old->scope, 'root-a');
            self::fail('Foreign seller read was accepted');
        } catch (ProblemException) {
            self::assertSame(1, $this->rows('billium_execution_operation'));
        }
        $this->callers->as('seller-a');
        $this->expectException(ProblemException::class);
        $this->admin(fn (TransactionContext $ctx) => $registry->appendCredential($ctx, $old, 'bad-rotation', 'bad-v2', new SecretRef('secret://vault/wrong'), 'refund', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2030-01-01'), '', null));
    }

    public function test_database_rejects_configuration_content_rewrite_and_credential_account_substitution(): void
    {
        $this->seedRoot();
        $owner = TestEnvironment::pdo(owner: true);
        self::assertSame('55000', self::sqlState($owner, 'UPDATE billium_execution_merchant_revision SET valid_until=now()'));
        self::assertSame('55000', self::sqlState($owner, 'DELETE FROM billium_execution_merchant_revision'));
        self::assertSame('23514', self::sqlState($owner, "INSERT INTO billium_execution_merchant_credential_revision(installation_ref,seller_ref,environment,credential_revision_ref,revision_ref,merchant_account_ref,credential_ref,credential_version_ref,purpose,valid_from,valid_until,identity_continuity_evidence_ref) VALUES ('inst-test','seller-a','test','wrong','merchant-a','other-account','secret://vault/wrong','wrong','refund','2026-01-01','2030-01-01','claimed-proof')"));
        self::assertSame(0, $this->rows('billium_execution_merchant_credential_revision'));
    }

    public function test_two_postgres_processes_cannot_enable_competing_revisions_for_one_route(): void
    {
        $store = $this->application()->make(DatabaseExecutionStore::class);
        $this->admin(function (TransactionContext $ctx) use ($store) {
            foreach (['race-a', 'race-b'] as $ref) {
                $b = ExecutionSchemaTest::merchantFixture($ref, 'account-a', 'cap-'.$ref);
                $m = new MerchantRevision($b->revisionRef, $b->scope, $b->merchantAccountRef, $b->providerId, $b->driverId, $b->driverContractVersion, $b->capabilitySnapshotRef, $b->capabilityDigest, $b->credentialRef, $b->credentialVersionRef, $b->currencyCodes, $b->digest, $b->createdAt, new MerchantRevisionPolicy($b->createdAt, null, null, 'api-v1', 'shared-race-route', 'continuity-'.$ref, 'settlement-'.$ref, 'mandate-'.$ref));
                $store->appendMerchantRevision($ctx, $m, ExecutionSchemaTest::capabilityFixture('cap-'.$ref));
            }
        });
        $owner = TestEnvironment::pdo(owner: true);
        $key = random_int(100000, 900000);
        $owner->exec('SELECT pg_advisory_lock('.$key.')');
        $script = <<<'WORKER'
require 'vendor/autoload.php';
$p=\Billium\Core\Tests\TestEnvironment::pdo();
$p->exec("SET statement_timeout='10s'");
$pid=$p->query('SELECT pg_backend_pid()')->fetchColumn();
$q=$p->prepare('SELECT pg_advisory_lock_shared(?)'); $q->execute([(int)$argv[2]]);
try {
 $p->beginTransaction();
 $q=$p->prepare("INSERT INTO billium_execution_merchant_lifecycle_event(installation_ref,seller_ref,environment,event_ref,revision_ref,action,purpose,effective_at,actor_ref,reason,evidence_ref) VALUES ('inst-test','seller-a','test',?,?,'enable_new','new','2026-11-17T00:00:00Z','actor-1','race fixture','independent-host-fixture')");
 $q->execute(['enable-'.$argv[1],$argv[1]]); $p->commit(); $status='committed';
} catch (\PDOException $e) { if($p->inTransaction())$p->rollBack(); $status=$e->errorInfo[0]; }
echo json_encode(['backend'=>(int)$pid,'process'=>getmypid(),'status'=>$status],JSON_THROW_ON_ERROR);
WORKER;
        $children = [];
        try {
            foreach (['race-a', 'race-b'] as $ref) {
                $process = proc_open([PHP_BINARY, '-r', $script, $ref, (string) $key], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                self::assertIsResource($process);
                fclose($pipes[0]);
                $children[] = ['process' => $process, 'out' => $pipes[1], 'err' => $pipes[2]];
            }
            $waiting = $owner->prepare("SELECT count(*) FROM pg_locks WHERE locktype='advisory' AND classid=0 AND objid=? AND objsubid=1 AND mode='ShareLock' AND NOT granted");
            $deadline = microtime(true) + 8;
            do {
                $waiting->execute([$key]);
                $count = (int) $waiting->fetchColumn();
                if ($count === 2) {
                    break;
                }
                if (microtime(true) > $deadline) {
                    self::fail('Workers did not both reach the PostgreSQL barrier');
                }
                usleep(10000);
            } while (true);
            $owner->exec('SELECT pg_advisory_unlock('.$key.')');
            $results = [];
            foreach ($children as $child) {
                $out = stream_get_contents($child['out']);
                $err = stream_get_contents($child['err']);
                self::assertSame('', $err);
                fclose($child['out']);
                fclose($child['err']);
                self::assertSame(0, proc_close($child['process']));
                self::assertIsString($out);
                $result = json_decode($out, true, 64, JSON_THROW_ON_ERROR);
                self::assertIsArray($result);
                $results[] = $result;
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            self::assertSame(['23514', 'committed'], $statuses);
            self::assertCount(2, array_unique(array_map(self::integer(...), array_column($results, 'backend'))));
            self::assertCount(2, array_unique(array_map(self::integer(...), array_column($results, 'process'))));
            self::assertSame(1, $this->rows('billium_execution_merchant_lifecycle_event'));
        } finally {
            $owner->exec('SELECT pg_advisory_unlock('.$key.')');
            foreach ($children as $child) {
                if (is_resource($child['process'])) {
                    proc_terminate($child['process']);
                }
            }
        }
    }

    public function test_sellers_can_share_root_and_external_ids_without_sharing_financial_namespaces(): void
    {
        $store = $this->seedRoot();
        $registry = $this->registry();
        $a = ExecutionSchemaTest::merchantFixture();
        $scopeB = new ExecutionScope(self::INSTALLATION, 'seller-b', 'test', 'source-a');
        $b = new MerchantRevision($a->revisionRef, $scopeB, $a->merchantAccountRef, $a->providerId, $a->driverId, $a->driverContractVersion, $a->capabilitySnapshotRef, $a->capabilityDigest, $a->credentialRef, $a->credentialVersionRef, $a->currencyCodes, $a->digest, $a->createdAt, new MerchantRevisionPolicy($a->policy->validFrom, null, null, 'api-v1', 'webhook-a', 'continuity-seller-b', 'settlement-seller-b', 'mandate-seller-b'));
        $this->admin(fn (TransactionContext $ctx) => $registry->appendLifecycle($ctx, $a->scope, $a->revisionRef, 'retention-a', 'enable_recovery', 'refund', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2030-01-01'), 'seller-a retention', 'host-retention-a'));
        $this->callers->as('seller-b');
        $this->admin(function (TransactionContext $ctx) use ($store, $registry, $b) {
            $store->appendMerchantRevision($ctx, $b, ExecutionSchemaTest::capabilityFixture());
            $registry->appendLifecycle($ctx, $b->scope, $b->revisionRef, 'enable-b', 'enable_new', 'new', $b->policy->validFrom, null, 'seller-b enable', 'host-b-proof');
            $registry->appendLifecycle($ctx, $b->scope, $b->revisionRef, 'retention-b', 'enable_recovery', 'refund', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2027-01-01'), 'seller-b retention', 'host-retention-b');
        });
        $this->runner()->run($this->spec(kind: 'execution.seller_b_admit', modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store, $b) {
            $store->transitionNamespace($ctx, $b->scope, NamespaceState::Open, 0, 'seller-b-attested-history');
            $binding = new FrozenBinding($b->scope, 'remote-billium', 'seller-b-intent', 'root-a', 'merchant-a', 'account-a', 'driver-a', 'driver.v1', $b->capabilityDigest, 'sha256:'.str_repeat('e', 64), 'execution.intent.v1', null, 'commerz');
            self::assertNull($store->insertBinding($ctx, $binding));
            $store->insertOperation($ctx, new AdmissionPlan($b->scope, $binding, 'root-a', EffectKind::Charge, new Money(300, Currency::of('RUB')), 'seller-b-command', $binding->semanticDigest, 'execution.intent.v1', ['execution', 'platform'], [], null));

            return self::receipt();
        });
        self::assertFalse($registry->allowed($scopeB, 'merchant-a', 'refund', new DateTimeImmutable('2028-01-01')));
        $historicalB = $registry->historical($scopeB, 'root-a');
        self::assertSame('settlement-seller-b', $historicalB->policy->settlementAccountRef);
        self::assertSame('mandate-seller-b', $historicalB->policy->mandateNamespaceRef);
        try {
            $registry->historical($a->scope, 'root-a');
            self::fail('Foreign seller data disclosed');
        } catch (ProblemException) {
            self::assertSame(2, $this->rows('billium_execution_operation'));
        }
        $this->callers->as('seller-a');
        self::assertTrue($registry->allowed($a->scope, 'merchant-a', 'refund', new DateTimeImmutable('2028-01-01')));
        $historicalA = $registry->historical($a->scope, 'root-a');
        self::assertSame('settlement-account-a', $historicalA->policy->settlementAccountRef);
        self::assertSame('mandate-account-a', $historicalA->policy->mandateNamespaceRef);
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), "UPDATE billium_execution_merchant_revision SET settlement_account_ref='seller-b-substitution' WHERE seller_ref='seller-a'"));
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), "UPDATE billium_execution_merchant_revision SET mandate_namespace_ref='seller-b-substitution' WHERE seller_ref='seller-a'"));
        self::assertSame(2, $this->rows('billium_execution_merchant_revision'));
    }
}

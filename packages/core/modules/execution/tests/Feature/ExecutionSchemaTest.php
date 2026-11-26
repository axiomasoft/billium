<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\TestEnvironment;
use Billium\Execution\Data\AdmissionPlan;
use Billium\Execution\Data\CapabilityDetails;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\CapacitySnapshot;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\MaterialChange;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\MerchantRevisionPolicy;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ProviderFact;
use Billium\Execution\Data\ProviderWorkClaim;
use Billium\Execution\Data\RecoveryAction;
use Billium\Execution\Data\Reservation;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Enums\CaptureMode;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Enums\ProviderEvidenceMode;
use Billium\Execution\Enums\QualificationMode;
use Billium\Execution\Enums\ReservationState;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Internal\Storage\DatabaseExecutionStore;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Platform\Data\Installation\PlatformReceipt;
use DateTimeImmutable;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
class ExecutionSchemaTest extends ExecutionTestCase
{
    public static function scopeFixture(): ExecutionScope
    {
        return new ExecutionScope(self::INSTALLATION, 'seller-a', 'test', 'source-a');
    }

    public static function capabilityFixture(string $ref = 'cap-a', string $account = 'account-a'): CapabilitySnapshot
    {
        $details = new CapabilityDetails('details.v1', true, false, 'account/reference', 86400, true, 'eventual', 86400, CaptureMode::PartialMultiple, true, true, true, true, true, true, true, true, true, false, false, false, 'account/reference', 'stable.v1', 'manifest-a');

        return new CapabilitySnapshot($ref, 'driver-a', 'driver.v1', 'cap.v1', $account, [EffectKind::Charge, EffectKind::Authorize, EffectKind::Capture, EffectKind::Refund, EffectKind::VoidAuthorization], ['RUB'], ProviderEvidenceMode::StableEffectId, true, true, true, true, true, false, false, null, QualificationMode::Simulated, 'fixture-qualification', 'sha256:'.str_repeat('a', 64), $details);
    }

    public static function merchantFixture(string $ref = 'merchant-a', string $account = 'account-a', string $cap = 'cap-a'): MerchantRevision
    {
        return new MerchantRevision($ref, self::scopeFixture(), $account, 'provider-a', 'driver-a', 'driver.v1', $cap, 'sha256:'.str_repeat('a', 64), new SecretRef('secret://vault/credential_a'), 'credential-v1', ['RUB'], 'sha256:'.str_repeat('b', 64), new DateTimeImmutable('2026-01-01T00:00:00Z'), new MerchantRevisionPolicy(new DateTimeImmutable('2026-01-01T00:00:00Z'), null, null, 'api-v1', 'webhook-a', 'continuity-root-a', 'settlement-'.$account, 'mandate-'.$account));
    }

    protected function seedRoot(): DatabaseExecutionStore
    {
        $store = $this->application()->make(DatabaseExecutionStore::class);
        $registry = $this->application()->make(DatabaseMerchantRegistry::class);
        $this->runner()->run($this->spec(kind: 'execution.schema_admin', mode: FinancialMode::Administrative, class: ModuleOperationClass::HistoricalRead, modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store, $registry): PlatformReceipt {
            $store->appendMerchantRevision($ctx, self::merchantFixture(), self::capabilityFixture());
            $registry->appendLifecycle($ctx, self::scopeFixture(), 'merchant-a', 'enable-a', 'enable_new', 'new', new DateTimeImmutable('2026-01-01T00:00:00Z'), null, 'test enable', 'authority-enable-a');

            return self::receipt();
        });
        $this->runner()->run($this->spec(kind: 'execution.schema_admit', modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store): PlatformReceipt {
            $store->transitionNamespace($ctx, self::scopeFixture(), NamespaceState::Open, 0, 'attested-complete-history');
            $b = new FrozenBinding(self::scopeFixture(), 'remote-billium', 'intent-a', 'root-a', 'merchant-a', 'account-a', 'driver-a', 'driver.v1', 'sha256:'.str_repeat('a', 64), 'sha256:'.str_repeat('c', 64), 'execution.intent.v1', null, 'commerz');
            self::assertNull($store->insertBinding($ctx, $b));
            $p = new AdmissionPlan(self::scopeFixture(), $b, 'root-a', EffectKind::Charge, new Money(1000, Currency::of('RUB')), 'command-a', 'sha256:'.str_repeat('c', 64), 'execution.intent.v1', ['execution', 'platform'], [], null);
            $store->insertOperation($ctx, $p);

            return self::receipt();
        });

        return $store;
    }

    public function test_execution_ddl_is_registered_and_historical_migrations_remain_separate(): void
    {
        $store = $this->seedRoot();
        $this->runner()->run($this->spec(kind: 'execution.schema_attempt', modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store): PlatformReceipt {
            $plan = $store->plan(self::scopeFixture(), 'root-a');
            $store->lock($ctx, $plan);
            $root = $store->operation(self::scopeFixture(), 'root-a') ?? throw new LogicException('missing root');
            $attempt = $store->insertAttempt($ctx, $root, 'attempt-a', 'provider-ref-a');
            $store->insertDispatchWork($ctx, $attempt, 'dispatch-a', 'handler-v1');

            return self::receipt();
        });
        self::assertSame(31, $this->rows('pg_tables', "schemaname='public' AND tablename LIKE 'billium_execution_%'"));
        self::assertSame(1, $this->rows('migrations', "migration='2026_11_17_000001_create_billium_execution_runtime'"));
        self::assertSame(1, $this->rows('pg_tables', "schemaname='public' AND tablename='billium_financial_session'"));
    }

    public function test_store_uses_current_root_and_one_attempt_with_sql_scope_and_immutability_guards(): void
    {
        $store = $this->seedRoot();
        $this->runner()->run($this->spec(kind: 'execution.schema_test', modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store): PlatformReceipt {
            $store->lock($ctx, $store->plan(self::scopeFixture(), 'root-a'));
            $op = $store->operation(self::scopeFixture(), 'root-a') ?? throw new LogicException('Missing root');
            $attempt = $store->insertAttempt($ctx, $op, 'attempt-a', 'provider-ref-a');
            $store->insertDispatchWork($ctx, $attempt, 'dispatch-a', 'handler.v1');
            self::assertSame(0, $attempt->leaseFence);

            return self::receipt();
        });
        $pdo = TestEnvironment::pdo();
        self::assertSame('42501', self::sqlState($pdo, "UPDATE billium_execution_operation SET merchant_account_ref='substituted' WHERE operation_ref='root-a'"));
        self::assertSame('23505', self::sqlState($pdo, "INSERT INTO billium_execution_attempt(installation_ref,seller_ref,environment,attempt_ref,operation_ref,provider_reference,provider_id,merchant_account_ref,request_digest) SELECT installation_ref,seller_ref,environment,'attempt-b',operation_ref,'provider-ref-b',provider_id,merchant_account_ref,request_digest FROM billium_execution_attempt"));
        self::assertSame(1, $this->rows('billium_execution_attempt'));
        self::assertSame(1, $this->rows('billium_execution_dispatch_work'));
        self::assertSame('23514', self::sqlState($pdo, <<<'SQL'
            INSERT INTO billium_execution_operation(installation_ref,seller_ref,environment,operation_ref,root_operation_ref,kind,source_context,binding,binding_digest,business_intent_ref,intent_owner_namespace,merchant_revision_ref,merchant_account_ref,driver_id,driver_contract_version,capability_digest,requested_minor,currency,currency_exponent,state,revision,economic_digest,created_at)
            SELECT installation_ref,seller_ref,environment,'recreated','recreated',kind,source_context,jsonb_set(binding,'{rootOperationRef}','"recreated"'),binding_digest,business_intent_ref,intent_owner_namespace,merchant_revision_ref,merchant_account_ref,driver_id,driver_contract_version,capability_digest,requested_minor,currency,currency_exponent,state,revision,economic_digest,created_at
            FROM billium_execution_operation WHERE operation_ref='root-a'
            SQL));
        self::assertSame(1, $this->rows('billium_execution_operation'));

        self::assertSame('23514', self::sqlState($pdo, "UPDATE billium_execution_operation SET state='made_up' WHERE operation_ref='root-a'"));
    }

    public function test_due_scoped_lookup_has_a_supporting_index(): void
    {
        $this->seedRoot();
        // Representative lookup fixtures retain matching child identities; they do not claim provider execution.
        $this->owner()->transaction(function (): void {
            $this->owner()->unprepared(<<<'SQL'
            INSERT INTO billium_execution_child_identity(installation_ref,seller_ref,environment,intent_owner_namespace,source_context,business_intent_ref,operation_kind,operation_ref,root_operation_ref,semantic_digest,canonicalization_version,binding,requested_minor,currency,currency_exponent,receipt_ref)
            SELECT o.installation_ref,o.seller_ref,o.environment,o.intent_owner_namespace,o.source_context,'lookup-intent-'||n,'capture','lookup-'||n,o.root_operation_ref,o.economic_digest,o.binding->>'canonicalizationVersion',o.binding,100,o.currency,o.currency_exponent,'fixture-admission-'||n
            FROM billium_execution_operation o CROSS JOIN generate_series(1,2000) n WHERE o.operation_ref='root-a';
            INSERT INTO billium_execution_operation(installation_ref,seller_ref,environment,operation_ref,root_operation_ref,kind,source_context,binding,binding_digest,business_intent_ref,intent_owner_namespace,merchant_revision_ref,merchant_account_ref,driver_id,driver_contract_version,capability_digest,requested_minor,currency,currency_exponent,state,revision,economic_digest,created_at)
            SELECT o.installation_ref,o.seller_ref,o.environment,'lookup-'||n,o.root_operation_ref,'capture',o.source_context,o.binding,o.binding_digest,o.business_intent_ref,o.intent_owner_namespace,o.merchant_revision_ref,o.merchant_account_ref,o.driver_id,o.driver_contract_version,o.capability_digest,100,o.currency,o.currency_exponent,'admitted',0,o.economic_digest,o.created_at
            FROM billium_execution_operation o CROSS JOIN generate_series(1,2000) n WHERE o.operation_ref='root-a';
            INSERT INTO billium_execution_attempt(installation_ref,seller_ref,environment,attempt_ref,operation_ref,provider_reference,provider_id,merchant_account_ref,request_digest)
            SELECT installation_ref,seller_ref,environment,'attempt-'||operation_ref,operation_ref,'provider-ref-'||operation_ref,'provider-a',merchant_account_ref,economic_digest FROM billium_execution_operation WHERE kind='capture';
            ANALYZE billium_execution_operation;
            ANALYZE billium_execution_attempt;
            SQL);
        });
        $pdo = TestEnvironment::pdo();
        $pdo->exec('SET enable_seqscan=off');
        $statement = $pdo->query("EXPLAIN SELECT id FROM billium_execution_provider_work WHERE installation_ref='inst-test' AND seller_ref='seller-a' AND environment='test' AND state='due' AND due_at<=now() ORDER BY due_at,id LIMIT 100");
        self::assertNotFalse($statement);
        $plan = array_map(self::text(...), $statement->fetchAll(PDO::FETCH_COLUMN));
        self::assertStringContainsString('billium_execution_provider_work_lookup', implode("\n", $plan));
        $plans = [
            ["SELECT id FROM billium_execution_operation WHERE installation_ref='inst-test' AND seller_ref='seller-a' AND environment='test' AND operation_ref='root-a' AND currency='RUB' AND requested_minor>0", 'billium_execution_operation'],
            ["SELECT id FROM billium_execution_attempt WHERE installation_ref='inst-test' AND environment='test' AND provider_id='provider-a' AND merchant_account_ref='account-a' AND provider_reference='provider-ref-lookup-1999'", 'billium_execution_provider_reference'],
            ["SELECT id FROM billium_execution_merchant_revision WHERE installation_ref='inst-test' AND seller_ref='seller-a' AND environment='test' AND merchant_account_ref='account-a' AND valid_from<=now()", 'billium_execution_merchant_revision'],
        ];
        foreach ($plans as [$sql,$index]) {
            $statement = $pdo->query('EXPLAIN '.$sql);
            self::assertNotFalse($statement);
            $text = implode("\n", array_map(self::text(...), $statement->fetchAll(PDO::FETCH_COLUMN)));
            self::assertStringContainsString('Index', $text);
            self::assertStringContainsString($index, $text);
        }
        $pdo->exec('RESET enable_seqscan');
    }

    public function test_sql_capacity_guard_rejects_overbooking_and_retains_actual_overbound_with_hold(): void
    {
        $store = $this->seedRoot();
        $this->owner()->update("UPDATE billium_execution_capacity SET authorized_limit_minor=1000 WHERE root_operation_ref='root-a'");
        $this->runner()->run($this->spec(kind: 'execution.capacity_test', modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store): PlatformReceipt {
            $store->lockNamespace($ctx, self::scopeFixture());
            $store->lock($ctx, $store->plan(self::scopeFixture(), 'root-a', true));
            $root = $store->operation(self::scopeFixture(), 'root-a') ?? throw new LogicException('Root missing');
            foreach (['capture-a' => 400, 'capture-b' => 700] as $ref => $minor) {
                $childPlan = new AdmissionPlan(self::scopeFixture(), $root->binding, $ref, EffectKind::Capture, new Money($minor, Currency::of('RUB')), 'key-'.$ref, 'sha256:'.str_repeat('d', 64), 'execution.intent.v1', ['execution', 'platform'], [], null);
                $store->claimChildBinding($ctx, $childPlan, 'fixture-'.$ref);
                $store->insertOperation($ctx, $childPlan);
            }
            $before = $store->capacity($ctx, 'root-a');
            $next = $store->reserveCapacity($ctx, new Reservation('reserve-a', 'root-a', 'capture-a', EffectKind::Capture, new Money(400, Currency::of('RUB')), ReservationState::Held, new Money(0, Currency::of('RUB')), null), $before);
            self::assertSame(1, $next->version);
            self::assertSame(400, $next->heldCapture->minor);

            return self::receipt();
        });
        $runtime = TestEnvironment::pdo();
        self::assertSame('23514', self::sqlState($runtime, "INSERT INTO billium_execution_reservation(installation_ref,seller_ref,environment,reservation_ref,root_operation_ref,operation_ref,kind,requested_minor,currency,state) VALUES ('inst-test','seller-a','test','reserve-b','root-a','capture-b','capture',700,'RUB','held')"));
        self::assertSame('23514', self::sqlState($runtime, "UPDATE billium_execution_capacity SET confirmed_capture_minor=1200 WHERE root_operation_ref='root-a'"));
        $runtime->exec("UPDATE billium_execution_capacity SET confirmed_capture_minor=1200,financial_hold=true WHERE root_operation_ref='root-a'");
        self::assertSame(1200, self::integer($this->value("SELECT confirmed_capture_minor FROM billium_execution_capacity WHERE root_operation_ref='root-a'")));
        self::assertSame(1, $this->rows('billium_execution_reservation'));
        self::assertSame('23514', self::sqlState($runtime, "UPDATE billium_execution_reservation SET state='released',release_evidence_ref='missing-proof',version=version+1 WHERE reservation_ref='reserve-a'"));
        self::assertSame('held', $this->value("SELECT state FROM billium_execution_reservation WHERE reservation_ref='reserve-a'"));
    }

    public function test_raw_custody_retains_versions_and_work_finish_is_fenced(): void
    {
        $store = $this->application()->make(DatabaseExecutionStore::class);
        $this->runner()->run($this->spec(kind: 'execution.raw_test', mode: FinancialMode::RawCustody, class: ModuleOperationClass::CallbackCustody, modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store): PlatformReceipt {
            foreach (['evidence-a', 'evidence-b'] as $ref) {
                $store->appendEvidence($ctx, new ProviderEvidence($ref, self::scopeFixture(), 'provider-a', 'account-a', 'same-provider-event', 'sha256:'.hash('sha256', $ref), 'verified', 'handler.v1', new DateTimeImmutable, ['schema' => 'sanitized.v1', 'state' => $ref], 'custody://journal/'.$ref));
            }
            $store->enqueueProviderWork($ctx, 'evidence-a', 'work-a', 'handler.v1');

            return self::receipt();
        });
        $this->runner()->run($this->spec(kind: 'execution.work_test', mode: FinancialMode::RawCustody, class: ModuleOperationClass::CallbackCustody, modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store): PlatformReceipt {
            $claim = $store->claimProviderWork($ctx, 'work-a', 'worker-a', new DateTimeImmutable('+5 minutes'));
            self::assertNotNull($claim);
            self::assertSame(1, $claim->leaseFence);
            self::assertNull($store->claimProviderWork($ctx, 'work-a', 'worker-b', new DateTimeImmutable('+5 minutes')));
            $stale = new ProviderWorkClaim($claim->workRef, $claim->evidenceRef, $claim->handlerContractVersion, $claim->attemptCount, 0, 'worker-stale', $claim->leaseExpiresAt);
            self::assertFalse($store->finishProviderWork($ctx, $stale, 'processed', 'result-stale', null, null));
            self::assertTrue($store->finishProviderWork($ctx, $claim, 'processed', 'result-a', null, null));
            self::assertFalse($store->finishProviderWork($ctx, $claim, 'parked', null, 'late-worker', null));

            return self::receipt();
        });
        self::assertSame(2, $this->rows('billium_execution_provider_evidence'));
        self::assertSame('processed', $this->value("SELECT state FROM billium_execution_provider_work WHERE work_ref='work-a'"));
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), "UPDATE billium_execution_provider_evidence SET payload_digest='changed'"));
    }

    public function test_unsupported_currency_and_precision_create_no_child_or_attempt(): void
    {
        $store = $this->seedRoot();
        foreach ([new Money(100, Currency::of('EUR')), new Money(100, new Currency('RUB', 0))] as $money) {
            try {
                $this->runner()->run($this->spec(kind: 'execution.unsupported_probe', modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store, $money): void {
                    $store->lock($ctx, $store->plan(self::scopeFixture(), 'root-a'));
                    $root = $store->operation(self::scopeFixture(), 'root-a') ?? throw new LogicException('Root missing');
                    $store->insertOperation($ctx, new AdmissionPlan(self::scopeFixture(), $root->binding, 'unsupported-child', EffectKind::Capture, $money, 'unsupported-command', 'sha256:'.str_repeat('e', 64), 'execution.intent.v1', ['execution', 'platform'], [], null));
                    self::fail('Unsupported child reached successful admission');
                });
                self::fail('Unsupported admission returned successful root');
            } catch (ProblemException $exception) {
                self::assertContains($exception->problem->value, ['billium.capability.unsupported', 'billium.validation.invalid']);
            }
        }
        self::assertSame(1, $this->rows('billium_execution_operation'));
        self::assertSame(0, $this->rows('billium_execution_attempt'));
        self::assertSame(0, $this->rows('billium_execution_reservation'));
    }

    public function test_repair_receipt_keeps_root_identity_distinct_from_action_and_requires_its_lock(): void
    {
        $store = $this->seedRoot();
        $action = new RecoveryAction('repair-action', self::scopeFixture(), 'record_correction', ['evidence-verified'], 'permission-a', 'audited correction', 'sha256:'.str_repeat('f', 64), 'root-a');
        $this->runner()->run($this->spec(kind: 'execution.repair_probe', modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store, $action): ExecutionReceipt {
            $store->lock($ctx, $store->plan(self::scopeFixture(), 'root-a'));
            $receipt = $store->appendRepairReceipt($ctx, $action, 'before-digest', 'after-digest', ['correction-a']);
            self::assertSame('root-a', $receipt->operationRef);
            self::assertSame('repair-action', $receipt->receiptRef);

            return $receipt;
        });
        self::assertSame('root-a', $this->value("SELECT root_operation_ref FROM billium_execution_repair_receipt WHERE repair_ref='repair-action'"));
        self::assertSame('permission-a', $this->value("SELECT authorization_ref FROM billium_execution_repair_receipt WHERE repair_ref='repair-action'"));
        try {
            $this->runner()->run($this->spec(kind: 'execution.repair_without_lock', modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store, $action): void {
                $store->recordRecoveryAction($ctx, $action);
                self::fail('Repair accepted without current rootlock');
            });
            self::fail('Unlocked repair returned success');
        } catch (LogicException $exception) {
            self::assertStringContainsString('lock required', $exception->getMessage());
        }
        self::assertSame(1, $this->rows('billium_execution_repair_receipt'));
    }

    public function test_material_revision_and_capacity_commit_once_and_rollback_together(): void
    {
        $store = $this->seedRoot();
        $write = function (TransactionContext $ctx) use ($store): void {
            $store->lock($ctx, $store->plan(self::scopeFixture(), 'root-a', context: $ctx));
            $before = $store->operation(self::scopeFixture(), 'root-a', $ctx) ?? throw new LogicException('Root missing');
            $store->insertAttempt($ctx, $before, 'material-attempt-a', 'material-reference-a');
            $currency = Currency::of('RUB');
            $zero = new Money(0, $currency);
            $total = new Money(1000, $currency);
            // This storage-level fixture supplies a prevalidated normalized fact; callback signature checks have separate subjects.
            $snapshot = new MaterialSnapshot('root-a', EffectKind::Charge, 'account-a', 'RUB', $total, $total, $zero, $zero, 'succeeded', 'provider-revision-1', 'effect-a', 'sha256:'.str_repeat('d', 64), ['material-evidence-a'], new DateTimeImmutable,
                null, ExecutionCodec::bindingDigest($before->binding), 'material-reference-a', $total);
            $store->appendEvidence($ctx, new ProviderEvidence('material-evidence-a', self::scopeFixture(), 'provider-a', 'account-a', 'event-a', 'sha256:'.str_repeat('f', 64), 'verified', 'handler.v1', new DateTimeImmutable, ['schema' => 'provider-sanitized.v1'], 'secure://fixture/material-evidence-a'));
            $store->enqueueProviderWork($ctx, 'material-evidence-a', 'material-work-a', 'handler.v1');
            self::assertTrue($store->appendFact($ctx, new ProviderFact('fact-a', 'root-a', ExecutionCodec::providerIdentityScope(self::merchantFixture(), self::capabilityFixture()), 'effect-a', 'sha256:'.str_repeat('d', 64), ProviderEvidenceMode::StableEffectId, $snapshot, ['material-evidence-a'])));
            $after = new OperationRecord($before->id, $before->operationRef, $before->rootOperationRef, $before->kind, $before->binding, $before->requested, 1, 'succeeded', 'sha256:'.str_repeat('d', 64), null, $before->createdAt);
            $capacity = new CapacitySnapshot('root-a', 1, $total, $total, $zero, $zero, $zero, $zero, $zero, false);
            $store->appendRevision($ctx, new MaterialChange($before, $after, $snapshot, $capacity, null, [], []), 'material-receipt-a');
        };
        try {
            $this->runner()->run($this->spec(kind: 'execution.material_rollback', modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($write): void {
                $write($ctx);
                throw new RuntimeException('fault-after-material-write');
            });
            self::fail('Fault did not roll back root');
        } catch (RuntimeException $error) {
            self::assertSame('fault-after-material-write', $error->getMessage());
        }
        self::assertSame(0, $this->rows('billium_execution_operation_revision'));
        self::assertSame(0, $this->rows('billium_execution_provider_evidence'));
        self::assertSame(0, $this->rows('billium_execution_attempt'));
        self::assertSame(0, self::integer($this->value("SELECT version FROM billium_execution_capacity WHERE root_operation_ref='root-a'")));
        $this->runner()->run($this->spec(kind: 'execution.material_commit', modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($write): PlatformReceipt {
            $write($ctx);

            return self::receipt();
        });
        self::assertSame(1, $this->rows('billium_execution_operation_revision'));
        self::assertSame(1, self::integer($this->value("SELECT version FROM billium_execution_capacity WHERE root_operation_ref='root-a'")));
        self::assertSame(1000, self::integer($this->value("SELECT confirmed_capture_minor FROM billium_execution_capacity WHERE root_operation_ref='root-a'")));
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), "UPDATE billium_execution_operation_revision SET material_digest='rewritten'"));
    }

    public function test_same_fact_reference_with_changed_material_retains_conflict_without_overwrite(): void
    {
        $store = $this->seedRoot();
        $this->runner()->run($this->spec(kind: 'execution.fact_conflict', modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store): PlatformReceipt {
            $store->lock($ctx, $store->plan(self::scopeFixture(), 'root-a', context: $ctx));
            $operation = $store->operation(self::scopeFixture(), 'root-a', $ctx) ?? throw new LogicException('Root missing');
            $store->insertAttempt($ctx, $operation, 'fact-attempt-a', 'fact-reference-a');
            $currency = Currency::of('RUB');
            $zero = new Money(0, $currency);
            foreach ([100, 200] as $minor) {
                $money = new Money($minor, $currency);
                $digest = 'sha256:'.hash('sha256', (string) $minor);
                $evidenceRef = 'retained-evidence-'.$minor;
                $store->appendEvidence($ctx, new ProviderEvidence($evidenceRef, self::scopeFixture(), 'provider-a', 'account-a', 'fact-event-'.$minor, $digest,
                    'verified', 'handler.v1', new DateTimeImmutable, ['schema' => 'provider-sanitized.v1'], 'secure://fixture/'.$evidenceRef));
                $snapshot = new MaterialSnapshot('root-a', EffectKind::Charge, 'account-a', 'RUB', $money, $money, $zero, $zero, 'succeeded', 'same-provider-revision', 'same-effect', $digest, [$evidenceRef], new DateTimeImmutable,
                    null, ExecutionCodec::bindingDigest($operation->binding), 'fact-reference-a', $money);
                $fact = new ProviderFact('reused-fact-ref', 'root-a', ExecutionCodec::providerIdentityScope(self::merchantFixture(), self::capabilityFixture()), 'same-effect', $digest, ProviderEvidenceMode::StableEffectId, $snapshot, ['retained-evidence-'.$minor]);
                self::assertSame($minor === 100, $store->appendFact($ctx, $fact));
            }
            self::assertFalse($store->capacity($ctx, 'root-a')->financialHold);
            self::assertSame(0, $store->capacity($ctx, 'root-a')->version);

            return self::receipt();
        });
        self::assertSame(1, $this->rows('billium_execution_provider_fact'));
        self::assertSame(1, $this->rows('billium_execution_discrepancy', "kind='provider_fact_identity_conflict'"));
        $incoming = json_decode(self::text($this->value('SELECT observed FROM billium_execution_discrepancy')), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($incoming);
        self::assertIsArray($incoming['capturedTotal']);
        self::assertSame(200, $incoming['capturedTotal']['minor']);
    }

    public function test_default_now_operation_and_attempt_are_admitted_in_the_same_root_transaction(): void
    {
        $store = $this->application()->make(DatabaseExecutionStore::class);
        $registry = $this->application()->make(DatabaseMerchantRegistry::class);
        $this->runner()->run($this->spec(kind: 'execution.tx_a_admin', mode: FinancialMode::Administrative, class: ModuleOperationClass::HistoricalRead, modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store, $registry): PlatformReceipt {
            $store->appendMerchantRevision($ctx, self::merchantFixture(), self::capabilityFixture());
            $registry->appendLifecycle($ctx, self::scopeFixture(), 'merchant-a', 'enable-same-tx', 'enable_new', 'new', new DateTimeImmutable('2026-01-01'), null, 'same TxA fixture', 'host-enable-proof');

            return self::receipt();
        });
        $this->runner()->run($this->spec(kind: 'execution.tx_a_operation_attempt', modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($store): PlatformReceipt {
            $scope = self::scopeFixture();
            $store->transitionNamespace($ctx, $scope, NamespaceState::Open, 0, 'attested-history');
            $binding = new FrozenBinding($scope, 'remote-billium', 'intent-same-tx', 'root-same-tx', 'merchant-a', 'account-a', 'driver-a', 'driver.v1', 'sha256:'.str_repeat('a', 64), 'sha256:'.str_repeat('c', 64), 'execution.intent.v1', null, 'commerz');
            self::assertNull($store->insertBinding($ctx, $binding));
            $operation = $store->insertOperation($ctx, new AdmissionPlan($scope, $binding, 'root-same-tx', EffectKind::Charge, new Money(500, Currency::of('RUB')), 'command-same-tx', $binding->semanticDigest, 'execution.intent.v1', ['execution', 'platform'], [], null));
            // No timestamp is supplied or normalized by this fixture: the store returns its own default-now persisted row.
            $attempt = $store->insertAttempt($ctx, $operation, 'attempt-same-tx', 'provider-ref-same-tx');
            $store->insertDispatchWork($ctx, $attempt, 'work-same-tx', 'handler.v1');
            self::assertSame($operation->operationRef, $attempt->operationRef);
            self::assertEquals($operation, $store->operation($scope, $operation->operationRef));
            self::assertEquals($attempt, $store->attempt($scope, $attempt->attemptRef));

            return self::receipt();
        });
        self::assertSame(1, $this->rows('billium_execution_operation'));
        self::assertSame(1, $this->rows('billium_execution_attempt'));
        self::assertSame(1, $this->rows('billium_execution_dispatch_work'));
    }
}

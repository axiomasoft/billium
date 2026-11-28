<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\TestEnvironment;
use Billium\Execution\Data\AdmissionPlan;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\NamespaceRecord;
use Billium\Execution\Data\ObservationPartition;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Internal\Storage\DatabaseExecutionStore;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Closure;
use DateTimeImmutable;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class BusinessContextIdentityTest extends ExecutionTestCase
{
    private DatabaseExecutionStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->application()->make(DatabaseExecutionStore::class);
        $this->runner()->run($this->spec(kind: 'execution.identity_admin', mode: FinancialMode::Administrative, class: ModuleOperationClass::HistoricalRead, modules: ['execution', 'platform']), function (TransactionContext $ctx): PlatformReceipt {
            $merchant = ExecutionSchemaTest::merchantFixture();
            $this->store->appendMerchantRevision($ctx, $merchant, ExecutionSchemaTest::capabilityFixture());
            $this->application()->make(DatabaseMerchantRegistry::class)->appendLifecycle($ctx, $merchant->scope, $merchant->revisionRef, 'enable', 'enable_new', 'new', new DateTimeImmutable('2026-01-01'), null, 'fixture enable', 'attested');

            return self::receipt();
        });
        $this->open(ExecutionSchemaTest::scopeFixture());
    }

    private function open(ExecutionScope $scope): void
    {
        $this->write(fn (TransactionContext $ctx): NamespaceRecord => $this->store->transitionNamespace($ctx, $scope, NamespaceState::Open, 0, 'attested-history'));
    }

    /** @param Closure(TransactionContext):mixed $body */
    private function write(Closure $body, ?OperationSpec $spec = null): void
    {
        $this->runner()->run($spec ?? $this->spec(kind: 'execution.identity', modules: ['execution', 'platform']), static function (TransactionContext $ctx) use ($body): PlatformReceipt {
            $body($ctx);

            return self::receipt();
        })->orThrow();
    }

    private function binding(string $context = 'commerz', string $root = 'root-a', ?ExecutionScope $scope = null, string $profile = 'remote-billium', string $digest = 'original-semantics'): FrozenBinding
    {
        return new FrozenBinding($scope ?? ExecutionSchemaTest::scopeFixture(), $profile, 'intent-a', $root, 'merchant-a', 'account-a', 'driver-a', 'driver.v1', 'sha256:'.str_repeat('a', 64), $digest, 'execution.intent.v1', null, $context);
    }

    private function plan(FrozenBinding $binding, EffectKind $kind = EffectKind::Charge, int $minor = 100): AdmissionPlan
    {
        return new AdmissionPlan($binding->scope, $binding, $binding->rootOperationRef, $kind, new Money($minor, Currency::of('RUB')), 'transport-key', $binding->semanticDigest, $binding->canonicalizationVersion, ['execution', 'platform'], [], null);
    }

    private function admit(FrozenBinding $binding, EffectKind $kind = EffectKind::Charge): void
    {
        $this->write(function (TransactionContext $ctx) use ($binding, $kind): void {
            self::assertNull($this->store->insertBinding($ctx, $binding));
            $operation = $this->store->insertOperation($ctx, $this->plan($binding, $kind));
            $this->store->insertAttempt($ctx, $operation, 'attempt-'.$operation->operationRef, 'provider-'.$operation->operationRef);
        });
    }

    public function test_two_contexts_with_same_owner_and_intent_survive_distinct_roots_and_exact_replays(): void
    {
        foreach (['commerz' => 'root-a', 'support' => 'root-b'] as $context => $root) {
            $binding = $this->binding($context, $root);
            $this->admit($binding);
            $this->write(function (TransactionContext $ctx) use ($binding): void {
                $existing = $this->store->insertBinding($ctx, $binding);
                self::assertNotNull($existing);
                self::assertSame($binding->rootOperationRef, $existing->operationRef);
                self::assertSame($binding->sourceContext, $existing->binding->sourceContext);
            }, $this->spec(kind: 'execution.identity', key: 'fresh-key-'.$root, modules: ['execution', 'platform']));
        }
        self::assertSame(2, $this->rows('billium_execution_business_binding'));
        self::assertSame(2, $this->rows('billium_execution_operation'));
        self::assertSame(2, $this->rows('billium_execution_attempt'));
        self::assertSame('commerz', $this->value("SELECT source_context FROM billium_execution_operation WHERE operation_ref='root-a'"));
        self::assertSame('support', $this->value("SELECT source_context FROM billium_execution_business_binding WHERE operation_ref='root-b'"));
    }

    public function test_long_wire_context_is_persisted_verbatim_without_an_index_width_limit(): void
    {
        // The wire ref has no maxLength. A compressible repeated character would hide the btree limit.
        $context = implode('', array_map(static fn (int $n): string => hash('sha256', (string) $n), range(0, 159)));
        $envelope = (new Codec)->decode(json_encode([
            'schema' => 'payment.execute.v1', 'commandKey' => '0b6f2c1e-6d1a-4a44-9a51-3f0c6f2f7a10',
            'operationRef' => 'root-a', 'operationKind' => 'charge', 'source' => ['context' => $context, 'intentRef' => 'intent-a'],
            'installationRef' => self::INSTALLATION, 'environment' => 'test', 'sellerRef' => 'seller-a',
            'executionProfile' => 'remote-billium', 'routingEpoch' => 1, 'merchantAccountRef' => 'account-a',
            'money' => ['amount' => '1.00', 'currency' => 'RUB'], 'bindingRevision' => 'merchant-a', 'requestedAt' => '2026-11-17T00:00:00Z',
        ], JSON_THROW_ON_ERROR));
        self::assertInstanceOf(ExecutePayment::class, $envelope);
        self::assertIsString($envelope->source->context);
        $binding = $this->binding($envelope->source->context, $envelope->operationRef, profile: $envelope->executionProfile);
        $this->admit($binding);
        self::assertSame($context, $this->value('SELECT source_context FROM billium_execution_business_binding'));
    }

    public function test_postgres_and_store_match_independent_unicode_jcs_identity_and_binding_vectors(): void
    {
        $binding = $this->binding("checkout/«é»😀\n\"\\");
        $this->admit($binding);
        self::assertSame('sha256:abbb4c02fe107a328fbd54d31efc4c1deefac98e6a5ff2041326fd7b9e47b85d', $this->value('SELECT business_identity_digest FROM billium_execution_business_binding'));
        self::assertSame('sha256:d1262e9b45a4aeef7f173497662b97a45e8da9e0c15b41ee094ca7192b167d40', $this->value('SELECT binding_digest FROM billium_execution_operation'));
        $this->write(function (TransactionContext $ctx) use ($binding): void {
            self::assertSame('root-a', $this->store->insertBinding($ctx, $binding)?->operationRef);
        });
    }

    public function test_digest_collision_fails_closed_and_generated_identity_cannot_be_supplied(): void
    {
        $this->admit($this->binding());
        $owner = TestEnvironment::pdo(owner: true);
        self::assertSame('sha256:5dbe7a54ca5d6c4ab0ec5ce15a697cdb0b94109f8e3aa862f37cdf0685afa453', $this->value('SELECT business_identity_digest FROM billium_execution_business_binding'));
        self::assertSame('428C9', self::sqlState($owner, "INSERT INTO billium_execution_business_binding(business_identity_digest) VALUES ('forged')"));

        $owner->beginTransaction();
        try {
            // Force both inputs into one index entry in this transaction only.
            $owner->exec(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.billium_execution_business_identity_digest(text,text,text,text,text) RETURNS text
                LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE SET search_path=pg_catalog,pg_temp AS $$
                    SELECT 'sha256:5dbe7a54ca5d6c4ab0ec5ce15a697cdb0b94109f8e3aa862f37cdf0685afa453'::text
                $$
                SQL);
            self::assertSame(0, $owner->exec(<<<'SQL'
                INSERT INTO billium_execution_business_binding(installation_ref,seller_ref,environment,intent_owner_namespace,source_context,business_intent_ref,operation_kind,semantic_digest,canonicalization_version,operation_ref,binding)
                SELECT installation_ref,seller_ref,environment,intent_owner_namespace,'distinct-context',business_intent_ref,operation_kind,semantic_digest,canonicalization_version,'distinct-root',
                    binding || '{"sourceContext":"distinct-context","rootOperationRef":"distinct-root"}'::jsonb FROM billium_execution_business_binding WHERE operation_ref='root-a'
                ON CONFLICT DO NOTHING
                SQL));
            self::assertSame(0, self::integer(self::pdoValue($owner, "SELECT count(*) FROM billium_execution_business_binding WHERE source_context='distinct-context'")));
        } finally {
            $owner->rollBack();
        }
        self::assertSame(1, $this->rows('billium_execution_business_binding'));
        self::assertSame(1, $this->rows('billium_execution_operation'));
    }

    public function test_natural_query_plan_seeks_one_digest_then_compares_the_exact_identity(): void
    {
        $this->admit($this->binding());
        $this->owner()->unprepared(<<<'SQL'
            INSERT INTO billium_execution_business_binding(installation_ref,seller_ref,environment,intent_owner_namespace,source_context,business_intent_ref,operation_kind,semantic_digest,canonicalization_version,operation_ref,binding)
            SELECT installation_ref,seller_ref,environment,intent_owner_namespace,'catalogue-'||n,business_intent_ref,operation_kind,semantic_digest,canonicalization_version,'catalogue-root-'||n,
                binding || jsonb_build_object('sourceContext','catalogue-'||n,'rootOperationRef','catalogue-root-'||n)
            FROM billium_execution_business_binding CROSS JOIN generate_series(1,2000) n WHERE operation_ref='root-a';
            ANALYZE public.billium_execution_business_binding;
            SQL);
        $explain = TestEnvironment::pdo()->query(<<<'SQL'
            EXPLAIN SELECT id FROM billium_execution_business_binding
            WHERE business_identity_digest=public.billium_execution_business_identity_digest('source-a','test','commerz','intent-a','charge')
                AND intent_owner_namespace='source-a' AND environment='test' AND source_context='commerz' AND business_intent_ref='intent-a' AND operation_kind='charge'
            SQL);
        self::assertNotFalse($explain);
        self::assertStringContainsString('billium_execution_business_identity_digest_unique', implode("\n", array_map(self::text(...), $explain->fetchAll(PDO::FETCH_COLUMN))));
        self::assertSame(0, $this->rows('pg_indexes', "schemaname='public' AND tablename LIKE 'billium_execution_%' AND indexdef LIKE '%source_context%'"));
    }

    public function test_fresh_key_changed_root_seller_mode_or_amount_cannot_reinterpret_original_charge(): void
    {
        $binding = $this->binding();
        $this->admit($binding);
        foreach ([$this->binding(root: 'new-root'), $this->binding(digest: 'authorize-semantics'), $this->binding('other-context')] as $changed) {
            $this->conflict(fn (TransactionContext $ctx): ?OperationRecord => $this->store->insertBinding($ctx, $changed));
        }
        $this->conflict(fn (TransactionContext $ctx): OperationRecord => $this->store->insertOperation($ctx, $this->plan($binding, EffectKind::Authorize)));
        $this->conflict(fn (TransactionContext $ctx): OperationRecord => $this->store->insertOperation($ctx, $this->plan($binding, minor: 101)));
        $this->callers->as('seller-b');
        $other = new ExecutionScope(self::INSTALLATION, 'seller-b', 'test', 'source-a');
        $this->open($other);
        $this->conflict(fn (TransactionContext $ctx): ?OperationRecord => $this->store->insertBinding($ctx, $this->binding(root: 'seller-b-root', scope: $other)));
        self::assertSame(1, $this->rows('billium_execution_operation'));
        self::assertSame(1, $this->rows('billium_execution_attempt'));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_key='conflicting-new-key'"));
    }

    /** @param Closure(TransactionContext):mixed $body */
    private function conflict(Closure $body): void
    {
        try {
            $this->write($body, $this->spec(kind: 'execution.identity', key: 'conflicting-new-key', modules: ['execution', 'platform']));
            self::fail('Conflicting identity was accepted');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
            self::assertStringNotContainsString('root-a', $e->getMessage());
        }
    }

    public function test_selected_namespace_source_and_current_transaction_are_required_before_storage(): void
    {
        $this->admit($this->binding());
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'fixture', ['remote-billium', 'standalone-billium'], ['source-a', 'other-owner']));
        $scope = ExecutionSchemaTest::scopeFixture();
        $other = new ExecutionScope(self::INSTALLATION, 'seller-a', 'test', 'other-owner');
        self::assertNull($this->store->operation($other, 'root-a'));
        self::assertNull($this->store->attempt($other, 'attempt-root-a'));
        self::assertSame([], $this->store->plan($other, 'root-a')->locks);
        $bindings = [
            [$this->binding(scope: $other), new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium')],
            [$this->binding(profile: 'standalone-billium'), new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium')],
            [$this->binding(), new FinancialScope(self::INSTALLATION, 'seller-a', 'test', null)],
        ];
        foreach ($bindings as [$binding,$selected]) {
            $entered = false;
            try {
                $this->write(function (TransactionContext $ctx) use ($binding, &$entered): void {
                    $entered = true;
                    $this->store->insertBinding($ctx, $binding);
                }, $this->spec(kind: 'execution.identity', modules: ['execution', 'platform'], financialScope: $selected));
                self::fail('Participant accepted an unrelated selected tuple');
            } catch (ProblemException $e) {
                self::assertTrue($entered);
                self::assertSame(ProblemCode::AuthDenied, $e->problem);
            }
        }
        $standalone = new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'standalone-billium');
        try {
            $this->write(fn (TransactionContext $ctx): ObservationPartition => $this->store->ensureObservationPartition($ctx, $scope, 'standalone-billium', 'lineage-a', 'root-a'),
                $this->spec(kind: 'execution.identity', modules: ['execution', 'platform'], financialScope: $standalone));
            self::fail('Emitter profile differed from the persisted root');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::AuthDenied, $e->problem);
        }
        $this->write(fn (TransactionContext $ctx): ObservationPartition => $this->store->ensureObservationPartition($ctx, $scope, 'remote-billium', 'lineage-a', 'root-a'));
        self::assertSame('remote-billium', $this->value('SELECT source_authority FROM billium_execution_observation_partition'));
        $former = null;
        $this->write(static function (TransactionContext $ctx) use (&$former): void {
            $former = $ctx;
        });
        self::assertInstanceOf(TransactionContext::class, $former);
        try {
            $this->store->insertBinding($former, $this->binding(root: 'stale-root'));
            self::fail('Former root accepted');
        } catch (LogicException $e) {
            self::assertNotSame('', $e->getMessage());
        }
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'fixture', ['source-a'], []));
        try {
            $this->store->operation($scope, 'root-a');
            self::fail('Fact authority alone disclosed business history');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::AuthDenied, $e->problem);
        }
        self::assertSame(1, $this->rows('billium_execution_business_binding'));
    }

    #[Group('ACC-RST-08')]
    public function test_retained_tombstone_guards_original_identity_even_without_an_operation_or_receipt(): void
    {
        $binding = $this->binding();
        $this->admit($binding);
        $runtime = TestEnvironment::pdo();
        $runtime->exec(<<<'SQL'
            INSERT INTO billium_execution_business_binding(installation_ref,seller_ref,environment,intent_owner_namespace,source_context,business_intent_ref,operation_kind,semantic_digest,canonicalization_version,operation_ref,binding,tombstone_reason)
            SELECT installation_ref,seller_ref,environment,intent_owner_namespace,'archived',business_intent_ref,operation_kind,semantic_digest,canonicalization_version,'closed-root',
                binding || '{"sourceContext":"archived","rootOperationRef":"closed-root"}'::jsonb,'closed-with-proof'
            FROM billium_execution_business_binding WHERE operation_ref='root-a'
            SQL);
        $this->conflict(fn (TransactionContext $ctx): ?OperationRecord => $this->store->insertBinding($ctx, $this->binding('archived', 'closed-root')));
        self::assertSame(1, $this->rows('billium_execution_operation'));
        self::assertSame(2, $this->rows('billium_execution_business_binding'));
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), "DELETE FROM billium_execution_business_binding WHERE operation_ref='closed-root'"));
    }

    public function test_raw_route_retains_evidence_without_an_emitter_grant_and_cannot_admit_money(): void
    {
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'custodian', [], ['source-a']));
        $scope = ExecutionSchemaTest::scopeFixture();
        $raw = new OperationSpec('execution.custody', 'seller-a', 'test', FinancialMode::RawCustody, ModuleOperationClass::CallbackCustody, ['execution', 'platform']);
        $this->write(function (TransactionContext $ctx) use ($scope): void {
            $this->store->appendEvidence($ctx, new ProviderEvidence('raw-event', $scope, 'provider-a', 'account-a', null, 'evidence-digest', 'unverified', 'handler.v1', new DateTimeImmutable, ['schema' => 'sanitized.v1'], 'custody://event'));
        }, $raw);
        try {
            $this->write(fn (TransactionContext $ctx): ?OperationRecord => $this->store->insertBinding($ctx, $this->binding()), $raw);
            self::fail('Raw custody admitted a financial operation');
        } catch (LogicException $e) {
            self::assertStringContainsString('financial session', $e->getMessage());
        }
        self::assertSame(1, $this->rows('billium_execution_provider_evidence'));
        self::assertSame(0, $this->rows('billium_execution_operation'));
    }

    public function test_only_wire_profiles_and_explicit_nonempty_contexts_can_be_frozen(): void
    {
        self::assertSame('standalone-billium', $this->binding(profile: 'standalone-billium')->executionProfile);
        foreach ([['sale', 'commerz'], ['test-profile', 'commerz'], ['remote-billium', '']] as [$profile,$context]) {
            try {
                $this->binding($context, profile: $profile);
                self::fail('Invalid immutable binding accepted');
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            }
        }
    }

    public function test_database_rejects_context_substitution_and_rolls_back_entire_admission(): void
    {
        $this->admit($this->binding());
        $owner = TestEnvironment::pdo(owner: true);
        foreach (['operation', 'business_binding'] as $table) {
            self::assertSame('55000', self::sqlState($owner, "UPDATE billium_execution_{$table} SET source_context='different' WHERE operation_ref='root-a'"));
        }
        $runtime = TestEnvironment::pdo();
        $copy = <<<'SQL'
            INSERT INTO billium_execution_business_binding(installation_ref,seller_ref,environment,intent_owner_namespace,source_context,business_intent_ref,operation_kind,semantic_digest,canonicalization_version,operation_ref,binding)
            SELECT installation_ref,seller_ref,environment,intent_owner_namespace,'changed',business_intent_ref,operation_kind,semantic_digest,canonicalization_version,'root-c',
                binding || '{"rootOperationRef":"root-c"}'::jsonb FROM billium_execution_business_binding WHERE operation_ref='root-a'
            SQL;
        self::assertSame('23514', self::sqlState($runtime, $copy));
        self::assertSame('23514', self::sqlState($runtime, <<<'SQL'
            INSERT INTO billium_execution_operation(installation_ref,seller_ref,environment,operation_ref,root_operation_ref,kind,source_context,binding,binding_digest,business_intent_ref,intent_owner_namespace,merchant_revision_ref,merchant_account_ref,driver_id,driver_contract_version,capability_digest,requested_minor,currency,currency_exponent,state,economic_digest)
            SELECT installation_ref,seller_ref,environment,'child',root_operation_ref,'capture','wrong-context',binding,binding_digest,business_intent_ref,intent_owner_namespace,merchant_revision_ref,merchant_account_ref,driver_id,driver_contract_version,capability_digest,1,currency,currency_exponent,'admitted',economic_digest
            FROM billium_execution_operation WHERE operation_ref='root-a'
            SQL));
        $newInstallation = str_replace(['SELECT installation_ref', "'changed'"], ["SELECT 'new-installation'", "'commerz'"], $copy);
        $newInstallation = str_replace('binding ||', "jsonb_set(binding,'{scope,installationRef}','\"new-installation\"') ||", $newInstallation);
        self::assertSame('23505', self::sqlState($runtime, $newInstallation));
        try {
            $this->write(function (TransactionContext $ctx): void {
                $new = $this->binding('independent', 'rollback-root');
                self::assertNull($this->store->insertBinding($ctx, $new));
                $this->store->insertOperation($ctx, $this->plan($this->binding('substituted', 'rollback-root')));
            }, $this->spec(kind: 'execution.identity', key: 'rollback-key', modules: ['execution', 'platform']));
            self::fail('Mismatched operation committed');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }
        self::assertSame(1, $this->rows('billium_execution_business_binding'));
        self::assertSame(1, $this->rows('billium_execution_operation'));
        self::assertSame(1, $this->rows('billium_execution_attempt'));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_key='rollback-key'"));
    }
}

<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Contracts\EventContractRegistrar;
use Billium\Platform\Data\EventContracts\EventContractRegistration;
use Billium\Platform\Data\EventContracts\EventContractRegistrationPlan;
use Billium\Platform\Internal\EventContracts\DatabaseEventContractRegistrar;
use Billium\Platform\Tests\Outbox\OutboxTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\Group;

#[Group('EVT-04')]
final class EventContractRegistryTest extends OutboxTestCase
{
    public function test_disabled_never_installed_module_has_no_rows_and_used_history_survives_disable(): void
    {
        $modules = $this->application()->make(ModuleRegistry::class);
        $execution = $modules->descriptor('execution') ?? self::fail('missing descriptor');
        $owner = new DatabaseEventContractRegistrar($this->owner(), $modules);
        $owner->register($owner->plan($execution, false));
        self::assertSame(0, $this->rows('billium_event_contract', "owner_module_id = 'execution'"));
        self::assertSame(7, $this->rows('billium_event_contract'));
        $this->registerExecution();
        $owner->register($owner->plan($execution, false));
        self::assertSame('historical', $this->value("SELECT status FROM billium_event_contract WHERE owner_module_id = 'execution'"));
        $owner->register($owner->plan($execution, true));
        self::assertSame('registered', $this->value("SELECT status FROM billium_event_contract WHERE owner_module_id = 'execution'"));
    }

    public function test_runtime_cannot_register_or_alter_platform_storage(): void
    {
        self::assertSame('42501', self::sqlState(TestEnvironment::pdo(), 'ALTER TABLE billium_event_contract ADD COLUMN hijack text'));
        self::assertSame('42501', self::sqlState(TestEnvironment::pdo(), 'INSERT INTO billium_event_contract SELECT * FROM billium_event_contract LIMIT 1'));
        self::assertSame('42501', self::sqlState(TestEnvironment::pdo(), "UPDATE billium_feed_subscription SET source_scope = 'forged'"));
        self::assertSame('42501', self::sqlState(TestEnvironment::pdo(), "UPDATE billium_feed_bootstrap_lease SET expires_at = clock_timestamp() + interval '1 day'"));
        $this->expectException(LogicException::class);
        $this->application()->make(EventContractRegistrar::class)->register(new EventContractRegistrationPlan([], [], []));
    }

    public function test_changed_digest_and_forged_plan_conflict_without_changing_the_registry(): void
    {
        $row = $this->registerExecution();
        $changed = new EventContractRegistration($row->ownerModuleId, $row->contractRevision, $row->envelopeSchema, $row->eventType,
            $row->payloadSchemaRef, 'sha256:'.str_repeat('0', 64), $row->bootstrapMode, null, $row->retentionPolicyRef);
        $registrar = new DatabaseEventContractRegistrar($this->owner(), $this->application()->make(ModuleRegistry::class));
        try {
            $registrar->register(new EventContractRegistrationPlan([$changed], [], []));
            self::fail('Changed schema under a pinned key must fail');
        } catch (LogicException $error) {
            self::assertStringContainsString('conflict', $error->getMessage());
        }
        self::assertSame($row->payloadSchemaDigest, $this->value("SELECT payload_schema_digest FROM billium_event_contract WHERE owner_module_id = 'execution'"));
    }

    public function test_outbox_foreign_keys_enforce_new_rows_and_validate_separately(): void
    {
        $this->events(1);
        $constraints = $this->table(<<<'SQL'
            SELECT conname FROM pg_constraint
            WHERE conrelid='public.billium_outbox_event'::regclass AND contype='f'
            ORDER BY conname
            SQL);
        self::assertCount(2, $constraints);
        foreach (['contract_revision' => 'missing-contract', 'owner_module_id' => 'wrong-owner'] as $field => $value) {
            // Clone a valid committed event, changing only one FK component and the row/event identities.
            self::assertSame('23503', self::sqlState(TestEnvironment::pdo(owner: true), <<<SQL
                INSERT INTO public.billium_outbox_event OVERRIDING SYSTEM VALUE
                SELECT (jsonb_populate_record(NULL::public.billium_outbox_event,
                    to_jsonb(e) || jsonb_build_object('id',e.id+1000,'event_id','01K7M6JX1H25Y2HF8WR2E9F4ZP',
                        '{$field}','{$value}'))).*
                FROM public.billium_outbox_event e LIMIT 1
                SQL));
        }
        $owner = $this->owner();
        $owner->beginTransaction();
        try {
            foreach ($constraints as $constraint) {
                $name = str_replace('"', '""', self::text($constraint['conname']));
                $owner->statement('ALTER TABLE public.billium_outbox_event VALIDATE CONSTRAINT "'.$name.'"');
            }
            self::assertSame(2, self::integer($this->value(<<<'SQL'
                SELECT count(*) FROM pg_constraint
                WHERE conrelid='public.billium_outbox_event'::regclass AND contype='f' AND convalidated
                SQL)));
        } finally {
            // Restore its initial state: older installations may already have validated these FKs.
            $owner->rollBack();
        }
    }
}

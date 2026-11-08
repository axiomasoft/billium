<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\EventContracts;

use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Data\Modules\ModuleDescriptor;
use Billium\Core\Enums\Modules\BootstrapMode;
use Billium\Platform\Contracts\EventContractRegistrar;
use Billium\Platform\Data\EventContracts\EventContractConflict;
use Billium\Platform\Data\EventContracts\EventContractRegistration;
use Billium\Platform\Data\EventContracts\EventContractRegistrationPlan;
use Billium\Platform\Internal\Installation\Rows;
use Illuminate\Database\Connection;
use LogicException;

final readonly class DatabaseEventContractRegistrar implements EventContractRegistrar
{
    public function __construct(private Connection $db, private ModuleRegistry $modules) {}

    public function plan(ModuleDescriptor $descriptor, bool $enabled): EventContractRegistrationPlan
    {
        $known = $this->modules->descriptor($descriptor->moduleId);
        if ($known !== null && ($known->moduleApiVersion !== $descriptor->moduleApiVersion || $known->schemaNamespace !== $descriptor->schemaNamespace
            || array_any($descriptor->publicEvents, static fn ($declaration): bool => ! array_any($known->publicEvents,
                static fn ($owned): bool => $owned->key() === $declaration->key() && EventContractRegistration::from($known->moduleId, $owned)->sameContract(EventContractRegistration::from($descriptor->moduleId, $declaration)))))) {
            return new EventContractRegistrationPlan([], [], [new EventContractConflict($descriptor->moduleId, $descriptor->moduleId, EventContractConflict::CHANGED_CONTRACT)]);
        }

        return EventContractRegistrationPlan::between($descriptor, $this->registrations(),
            array_map(static fn (ModuleDescriptor $module): string => $module->moduleId, $this->modules->manifest()->modules), $enabled);
    }

    /** @return list<EventContractRegistration> */
    public function registrations(): array
    {
        return array_values(array_map(self::registration(...), $this->db->select('SELECT * FROM public.billium_event_contract')));
    }

    public static function registration(mixed $row): EventContractRegistration
    {
        return new EventContractRegistration(
            Rows::string(Rows::field($row, 'owner_module_id')), Rows::string(Rows::field($row, 'contract_revision')),
            Rows::string(Rows::field($row, 'envelope_schema')), Rows::string(Rows::field($row, 'event_type')),
            Rows::string(Rows::field($row, 'payload_schema_ref')), Rows::string(Rows::field($row, 'payload_schema_digest')),
            BootstrapMode::from(Rows::string(Rows::field($row, 'bootstrap_mode'))), Rows::nullableString(Rows::field($row, 'snapshot_source_key')),
            Rows::string(Rows::field($row, 'retention_policy_ref')), Rows::string(Rows::field($row, 'status')),
        );
    }

    public function register(EventContractRegistrationPlan $plan): void
    {
        if (! $plan->isApplicable()) {
            throw new LogicException('module.event_contract_conflict');
        }
        // No alternate connection or credential escalation: only the explicit host owner command can call this writer.
        $owner = $this->db->selectOne("SELECT current_user = pg_get_userbyid(relowner) AS allowed FROM pg_class WHERE oid = 'public.billium_event_contract'::regclass");
        if (Rows::field($owner, 'allowed') !== true) {
            throw new LogicException('Event registration requires the installation/upgrade owner connection');
        }
        $this->db->transaction(function () use ($plan): void {
            $this->db->statement('LOCK TABLE public.billium_event_contract IN SHARE ROW EXCLUSIVE MODE');
            $existing = [];
            foreach ($this->registrations() as $row) {
                $existing[$row->key()] = $row;
            }
            foreach ([...$plan->toRegister, ...$plan->unchanged, ...$plan->toHistorical] as $row) {
                $descriptor = $this->modules->descriptor($row->ownerModuleId);
                $matches = $descriptor !== null && array_any($descriptor->publicEvents,
                    static fn ($declaration): bool => EventContractRegistration::from($row->ownerModuleId, $declaration)->key() === $row->key()
                        && EventContractRegistration::from($row->ownerModuleId, $declaration)->sameContract($row));
                if (! $matches || isset($existing[$row->key()]) && ! $existing[$row->key()]->sameContract($row)) {
                    throw new LogicException('module.event_contract_conflict: stale plan or foreign schema owner');
                }
            }
            foreach ($plan->toRegister as $row) {
                $this->db->statement(<<<'SQL'
                    INSERT INTO public.billium_event_contract
                    (contract_revision,envelope_schema,event_type,owner_module_id,payload_schema_ref,payload_schema_digest,
                     bootstrap_mode,snapshot_source_key,retention_policy_ref,status)
                    VALUES (?,?,?,?,?,?,?,?,?,'registered')
                    ON CONFLICT (contract_revision,envelope_schema,event_type) DO UPDATE SET status = 'registered'
                    SQL, [$row->contractRevision, $row->envelopeSchema, $row->eventType, $row->ownerModuleId,
                    $row->payloadSchemaRef, $row->payloadSchemaDigest, $row->bootstrapMode->value, $row->snapshotSourceKey, $row->retentionPolicyRef]);
            }
            foreach ($plan->toHistorical as $row) {
                $this->db->update("UPDATE public.billium_event_contract SET status = 'historical' WHERE contract_revision = ? AND envelope_schema = ? AND event_type = ?",
                    [$row->contractRevision, $row->envelopeSchema, $row->eventType]);
            }
        });
    }
}

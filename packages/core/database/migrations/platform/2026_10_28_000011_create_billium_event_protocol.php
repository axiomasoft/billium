<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // D63: the platform owns both lanes. Registration is an explicit owner operation, never worker boot.
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_event_contract (
                contract_revision text NOT NULL,
                envelope_schema text NOT NULL,
                event_type text NOT NULL,
                owner_module_id text NOT NULL,
                payload_schema_ref text NOT NULL,
                payload_schema_digest text NOT NULL CHECK (payload_schema_digest ~ '^sha256:[0-9a-f]{64}$'),
                bootstrap_mode text NOT NULL CHECK (bootstrap_mode IN ('snapshot','replay_only','non_bootstrappable')),
                snapshot_source_key text,
                retention_policy_ref text NOT NULL,
                status text NOT NULL CHECK (status IN ('registered','historical')),
                PRIMARY KEY (contract_revision, envelope_schema, event_type),
                CHECK ((bootstrap_mode = 'snapshot') = (snapshot_source_key IS NOT NULL)),
                CHECK (envelope_schema <> 'billium.event.v1' OR
                    (contract_revision = 'events-2026-10-v1' AND event_type IN
                     ('payment.outcome.v1','obligation.created.v1','obligation.adjusted.v1','invoice.issued.v1',
                      'credit_note.issued.v1','allocation.applied.v1','discrepancy.recorded.v1')))
            );
            CREATE OR REPLACE FUNCTION public.billium_event_contract_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' OR (to_jsonb(NEW) - 'status') IS DISTINCT FROM (to_jsonb(OLD) - 'status') THEN
                    RAISE EXCEPTION 'event contract is immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER billium_event_contract_immutable BEFORE UPDATE OR DELETE ON public.billium_event_contract
                FOR EACH ROW EXECUTE FUNCTION public.billium_event_contract_immutable();

            ALTER TABLE public.billium_outbox_event ADD COLUMN contract_revision text NOT NULL DEFAULT 'events-2026-10-v1';
            ALTER TABLE public.billium_outbox_event ADD COLUMN owner_module_id text NOT NULL DEFAULT 'ledger';
            ALTER TABLE public.billium_outbox_event ADD COLUMN source_scope text;
            -- No silent rewriting of pre-D63 noncanonical events or ambiguous installation history.
            DO $$ BEGIN
                IF EXISTS (SELECT 1 FROM public.billium_outbox_event)
                   AND (SELECT count(*) FROM public.billium_installation_state) <> 1 THEN
                    RAISE EXCEPTION 'D63 upgrade requires unambiguous installation history';
                END IF;
            END $$;
            ALTER TABLE public.billium_outbox_event DISABLE TRIGGER billium_outbox_event_append_only;
            UPDATE public.billium_outbox_event SET source_scope = (
                SELECT jsonb_build_object('installationRef',installation_ref,'channel','events','sourceAuthority','billium',
                    'sellerRef',billium_outbox_event.seller_ref,'environment',billium_outbox_event.environment,'lineage',incarnation::text)::text
                FROM public.billium_installation_state LIMIT 1
            );
            ALTER TABLE public.billium_outbox_event ENABLE TRIGGER billium_outbox_event_append_only;
            ALTER TABLE public.billium_outbox_event ALTER COLUMN source_scope SET NOT NULL;

            CREATE TABLE public.billium_outbox_delivery (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                event_id text NOT NULL REFERENCES public.billium_outbox_event(event_id),
                destination_ref text NOT NULL CHECK (length(destination_ref) BETWEEN 1 AND 200),
                status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','in_flight','delivered','parked')),
                available_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                claim_until timestamptz,
                claim_token text,
                attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
                consecutive_failures integer NOT NULL DEFAULT 0 CHECK (consecutive_failures >= 0),
                last_error_class text,
                park_reason text,
                delivered_at timestamptz,
                UNIQUE (event_id,destination_ref),
                CHECK ((status = 'in_flight') = (claim_token IS NOT NULL AND claim_until IS NOT NULL)),
                CHECK ((status = 'delivered') = (delivered_at IS NOT NULL))
            );
            CREATE INDEX billium_outbox_delivery_due ON public.billium_outbox_delivery(available_at,id)
                WHERE status IN ('pending','in_flight');

            CREATE TABLE public.billium_outbox_command (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                command_ref text NOT NULL UNIQUE CHECK (length(command_ref) BETWEEN 1 AND 200),
                business_ref text NOT NULL CHECK (length(business_ref) BETWEEN 1 AND 200),
                owner_module_id text NOT NULL,
                command_schema text NOT NULL CHECK (command_schema ~ '^billium\.command\.v[0-9]+$'),
                command_type text NOT NULL,
                source_scope text NOT NULL,
                payload_digest text NOT NULL CHECK (payload_digest ~ '^sha256:[0-9a-f]{64}$'),
                payload jsonb NOT NULL CHECK (jsonb_typeof(payload) = 'object' AND octet_length(payload::text) <= 65536),
                tx_id xid8 NOT NULL DEFAULT pg_current_xact_id(),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp()
            );
            CREATE TRIGGER billium_outbox_command_append_only BEFORE UPDATE OR DELETE ON public.billium_outbox_command
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            CREATE TABLE public.billium_outbox_command_delivery (
                LIKE public.billium_outbox_delivery INCLUDING DEFAULTS INCLUDING IDENTITY INCLUDING CONSTRAINTS INCLUDING INDEXES,
                FOREIGN KEY (event_id) REFERENCES public.billium_outbox_command(command_ref)
            );
            CREATE INDEX billium_outbox_command_delivery_due ON public.billium_outbox_command_delivery(available_at,id)
                WHERE status IN ('pending','in_flight');
            CREATE OR REPLACE FUNCTION public.billium_delivery_identity_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.id <> OLD.id OR NEW.event_id <> OLD.event_id OR NEW.destination_ref <> OLD.destination_ref THEN
                    RAISE EXCEPTION 'delivery identity is immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER billium_delivery_identity_immutable BEFORE UPDATE ON public.billium_outbox_delivery
                FOR EACH ROW EXECUTE FUNCTION public.billium_delivery_identity_immutable();
            CREATE TRIGGER billium_command_delivery_identity_immutable BEFORE UPDATE ON public.billium_outbox_command_delivery
                FOR EACH ROW EXECUTE FUNCTION public.billium_delivery_identity_immutable();

            CREATE TABLE public.billium_feed_subscription (
                subscription_ref text PRIMARY KEY,
                source_scope text NOT NULL,
                selection_digest text NOT NULL CHECK (selection_digest ~ '^sha256:[0-9a-f]{64}$'),
                selection jsonb NOT NULL,
                required boolean NOT NULL DEFAULT false,
                floor_tx_id xid8 NOT NULL DEFAULT '0',
                floor_id bigint NOT NULL DEFAULT 0,
                closed_at timestamptz
            );
            CREATE TABLE public.billium_feed_bootstrap_lease (
                lease_ref text PRIMARY KEY,
                subscription_ref text NOT NULL REFERENCES public.billium_feed_subscription(subscription_ref),
                source_scope text NOT NULL,
                selection_digest text NOT NULL,
                floor_tx_id xid8 NOT NULL,
                floor_id bigint NOT NULL,
                snapshot_ref text NOT NULL,
                snapshot_digest text,
                cursor_token text,
                expires_at timestamptz NOT NULL,
                acknowledged_at timestamptz,
                state text NOT NULL DEFAULT 'active' CHECK (state IN ('active','acknowledged','expired'))
            );
            CREATE OR REPLACE FUNCTION public.billium_feed_floor_monotonic() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (NEW.floor_tx_id,NEW.floor_id) < (OLD.floor_tx_id,OLD.floor_id) THEN
                    RAISE EXCEPTION 'consumer floor cannot regress; open a new subscription' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER billium_feed_floor_monotonic BEFORE UPDATE ON public.billium_feed_subscription
                FOR EACH ROW EXECUTE FUNCTION public.billium_feed_floor_monotonic();
            -- D52/D60: only privileged maintenance may delete content; a caller-set GUC alone grants nothing.
            CREATE OR REPLACE FUNCTION public.billium_outbox_retention_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND current_setting('billium.outbox_prune',true) = 'on'
                   AND current_user = pg_get_userbyid((SELECT relowner FROM pg_class WHERE oid = TG_RELID)) THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'outbox content is immutable' USING ERRCODE = '55000';
            END $$;
            DROP TRIGGER billium_outbox_event_append_only ON public.billium_outbox_event;
            CREATE TRIGGER billium_outbox_event_append_only BEFORE UPDATE OR DELETE ON public.billium_outbox_event
                FOR EACH ROW EXECUTE FUNCTION public.billium_outbox_retention_guard();
            REVOKE ALL ON FUNCTION public.billium_event_contract_immutable(), public.billium_delivery_identity_immutable(),
                public.billium_outbox_retention_guard(), public.billium_feed_floor_monotonic() FROM PUBLIC;
            SQL);

        $manifest = json_decode(file_get_contents(dirname(__DIR__, 3).'/resources/modules.json') ?: '', true, 512, JSON_THROW_ON_ERROR);
        foreach ($manifest['modules'] as $module) {
            if ($module['moduleId'] !== 'ledger') {
                continue;
            }
            foreach ($module['publicEvents'] as $event) {
                DB::table('billium_event_contract')->insert([
                    'contract_revision' => $event['eventContractRevision'], 'envelope_schema' => $event['envelopeSchema'],
                    'event_type' => $event['eventType'], 'owner_module_id' => 'ledger', 'payload_schema_ref' => $event['payloadSchemaRef'],
                    'payload_schema_digest' => $event['payloadSchemaDigest'], 'bootstrap_mode' => $event['bootstrapMode'],
                    'snapshot_source_key' => $event['snapshotSourceKey'], 'retention_policy_ref' => $event['retentionPolicyRef'], 'status' => 'registered',
                ]);
            }
        }
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.billium_outbox_event ADD CONSTRAINT billium_outbox_event_contract_fk
                FOREIGN KEY (contract_revision,schema_version,type)
                REFERENCES public.billium_event_contract(contract_revision,envelope_schema,event_type);
            ALTER TABLE public.billium_event_contract ADD UNIQUE (contract_revision,envelope_schema,event_type,owner_module_id);
            ALTER TABLE public.billium_outbox_event ADD FOREIGN KEY (contract_revision,schema_version,type,owner_module_id)
                REFERENCES public.billium_event_contract(contract_revision,envelope_schema,event_type,owner_module_id);
            CREATE UNIQUE INDEX billium_public_effect_revision ON public.billium_outbox_event
                (source_scope,type,aggregate_ref,aggregate_revision) WHERE schema_version = 'billium.event.v2';
            INSERT INTO public.billium_outbox_delivery(event_id,destination_ref)
                SELECT event_id,'default' FROM public.billium_outbox_event;
            SQL);
        RuntimeGrants::grant('SELECT', 'public.billium_event_contract');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_outbox_command');
        foreach (['billium_outbox_delivery', 'billium_outbox_command_delivery'] as $table) {
            RuntimeGrants::grant('SELECT, INSERT, UPDATE', 'public.'.$table);
        }
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_feed_subscription');
        RuntimeGrants::grant('UPDATE (floor_tx_id, floor_id)', 'public.billium_feed_subscription');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_feed_bootstrap_lease');
        RuntimeGrants::grant('UPDATE (snapshot_digest, cursor_token, state, acknowledged_at)', 'public.billium_feed_bootstrap_lease');
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

/**
 * Platform core: idempotency receipts, the outbox event log and its replay watermark, the money commit log,
 * installation and module state, and financial sessions.
 */
return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Shared backstop of immutable platform history (outbox events, commit log, transitions): no UPDATE/DELETE.
            CREATE OR REPLACE FUNCTION public.billium_append_only() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                RAISE EXCEPTION 'billium: % is append-only (% refused)', TG_TABLE_NAME, TG_OP USING ERRCODE = '55000';
            END $$;
            REVOKE ALL ON FUNCTION public.billium_append_only() FROM PUBLIC;

            -- The claim is the first write of the root Tx; claim and complete commit together.
            CREATE TABLE public.billium_idempotency_receipt (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                environment text NOT NULL CHECK (environment <> '' AND length(environment) <= 200),
                seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
                command_kind text NOT NULL CHECK (command_kind ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$'),
                command_key text NOT NULL CHECK (command_key <> '' AND length(command_key) <= 200),
                digest text NOT NULL CHECK (digest <> '' AND length(digest) <= 200),
                canonicalization_version text NOT NULL DEFAULT 'jcs-rfc8785+sha256'
                    CHECK (canonicalization_version = 'jcs-rfc8785+sha256'),
                outcome text NOT NULL CHECK (outcome IN ('in_progress', 'accepted', 'refused')),
                result jsonb NULL CHECK (result IS NULL OR (jsonb_typeof(result) = 'object' AND octet_length(result::text) <= 65536)),
                created_at timestamptz NOT NULL DEFAULT now(),
                completed_at timestamptz NULL,
                expires_at timestamptz NULL,
                financial_scope jsonb,
                input_digest text,
                original_execution_binding_ref text NULL,
                CONSTRAINT billium_idempotency_receipt_scope_key UNIQUE (environment, seller_ref, command_kind, command_key),
                CONSTRAINT billium_idempotency_receipt_completed CHECK (
                    (outcome = 'in_progress') = (result IS NULL) AND (outcome = 'in_progress') = (completed_at IS NULL)
                ),
                CONSTRAINT billium_idempotency_financial_scope CHECK (
                    financial_scope IS NULL OR (
                    jsonb_typeof(financial_scope)='object'
                    AND financial_scope ?& ARRAY['installationRef','sellerRef','environment','intentOwnerNamespace','sourceAuthority']
                    AND financial_scope - ARRAY['installationRef','sellerRef','environment','intentOwnerNamespace','sourceAuthority']='{}'::jsonb
                    AND jsonb_typeof(financial_scope->'installationRef')='string'
                    AND length(financial_scope->>'installationRef') BETWEEN 1 AND 200
                    AND jsonb_typeof(financial_scope->'sellerRef')='string'
                    AND financial_scope->>'sellerRef'=seller_ref
                    AND jsonb_typeof(financial_scope->'environment')='string'
                    AND financial_scope->>'environment'=environment
                    AND (financial_scope->'intentOwnerNamespace'='null'::jsonb OR (
                    jsonb_typeof(financial_scope->'intentOwnerNamespace')='string'
                    AND length(financial_scope->>'intentOwnerNamespace') BETWEEN 1 AND 200))
                    AND (financial_scope->'sourceAuthority'='null'::jsonb OR (
                    jsonb_typeof(financial_scope->'sourceAuthority')='string'
                    AND length(financial_scope->>'sourceAuthority') BETWEEN 1 AND 200))
                    )
                    ),
                CONSTRAINT billium_idempotency_input_digest CHECK (
                    input_digest IS NULL OR (
                    financial_scope IS NOT NULL
                    AND octet_length(input_digest) = 71
                    AND input_digest ~ '^sha256:[0-9a-f]{64}$'
                    )
                    ),
                CONSTRAINT billium_receipt_original_execution_binding_shape
                    CHECK (original_execution_binding_ref IS NULL OR (
                    length(original_execution_binding_ref) BETWEEN 1 AND 200
                    AND financial_scope IS NOT NULL
                    AND command_kind IN ('payment.execute.v1','payment.authorize.v1','payment.capture.v1',
                    'payment.void_authorization.v1','payment.refund.v1','payment.cancel.v1')
                    ))
            );

            CREATE OR REPLACE FUNCTION public.billium_idempotency_receipt_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium: idempotency receipts are never deleted by runtime' USING ERRCODE = '55000';
                END IF;
                IF OLD.outcome <> 'in_progress' OR NEW.outcome = 'in_progress'
                   OR (NEW.id, NEW.environment, NEW.seller_ref, NEW.command_kind, NEW.command_key, NEW.digest,
                       NEW.canonicalization_version, NEW.created_at, NEW.expires_at)
                      IS DISTINCT FROM
                      (OLD.id, OLD.environment, OLD.seller_ref, OLD.command_kind, OLD.command_key, OLD.digest,
                       OLD.canonicalization_version, OLD.created_at, OLD.expires_at) THEN
                    RAISE EXCEPTION 'billium: a completed receipt and its identity are immutable' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_idempotency_receipt_guard() FROM PUBLIC;

            CREATE TRIGGER billium_idempotency_receipt_guard BEFORE UPDATE OR DELETE ON public.billium_idempotency_receipt
                FOR EACH ROW EXECUTE FUNCTION public.billium_idempotency_receipt_guard();

            CREATE INDEX billium_idempotency_opaque_receipt_scope
                ON public.billium_idempotency_receipt(financial_scope,(result->>'class'),(result#>>'{body,receiptRef}'),created_at,id)
                WHERE outcome='accepted' AND financial_scope IS NOT NULL;

            -- The platform owns both lanes. Registration is an explicit owner operation, never worker boot.
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
                      'credit_note.issued.v1','allocation.applied.v1','discrepancy.recorded.v1'))),
                UNIQUE (contract_revision,envelope_schema,event_type,owner_module_id)
            );

            CREATE OR REPLACE FUNCTION public.billium_event_contract_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' OR (to_jsonb(NEW) - 'status') IS DISTINCT FROM (to_jsonb(OLD) - 'status') THEN
                    RAISE EXCEPTION 'event contract is immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_event_contract_immutable() FROM PUBLIC;

            CREATE TRIGGER billium_event_contract_immutable BEFORE UPDATE OR DELETE ON public.billium_event_contract
                FOR EACH ROW EXECUTE FUNCTION public.billium_event_contract_immutable();

            -- Immutable event content written in the owning root Tx; (tx_id, id) is the feed cursor.
            CREATE TABLE public.billium_outbox_event (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                event_id text NOT NULL CHECK (event_id <> '' AND length(event_id) <= 200),
                seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
                environment text NOT NULL CHECK (environment <> '' AND length(environment) <= 200),
                type text NOT NULL CHECK (type ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$' AND length(type) <= 200),
                schema_version text NOT NULL CHECK (schema_version ~ '^[a-z][a-z0-9_.-]*\.v[0-9]+$' AND length(schema_version) <= 200),
                aggregate_ref text NOT NULL CHECK (aggregate_ref <> '' AND length(aggregate_ref) <= 200),
                aggregate_revision bigint NOT NULL CHECK (aggregate_revision >= 0),
                occurred_at timestamptz NOT NULL,
                digest text NOT NULL CHECK (digest ~ '^sha256:[0-9a-f]{64}$'),
                payload jsonb NOT NULL CHECK (jsonb_typeof(payload) = 'object' AND octet_length(payload::text) <= 65536),
                tx_id xid8 NOT NULL DEFAULT pg_current_xact_id(),
                created_at timestamptz NOT NULL DEFAULT now(),
                contract_revision text NOT NULL DEFAULT 'events-2026-10-v1',
                owner_module_id text NOT NULL DEFAULT 'ledger',
                -- Written in the RFC 8785 form FeedScope::key() produces (members sorted, no whitespace), not as jsonb text.
                source_scope text NOT NULL,
                CONSTRAINT billium_outbox_event_event_id_key UNIQUE (event_id),
                CONSTRAINT billium_outbox_event_contract_fk FOREIGN KEY (contract_revision,schema_version,type)
                    REFERENCES public.billium_event_contract(contract_revision,envelope_schema,event_type),
                FOREIGN KEY (contract_revision,schema_version,type,owner_module_id)
                    REFERENCES public.billium_event_contract(contract_revision,envelope_schema,event_type,owner_module_id)
            );

            CREATE INDEX billium_outbox_event_cursor ON public.billium_outbox_event (tx_id, id);

            CREATE UNIQUE INDEX billium_public_effect_revision ON public.billium_outbox_event
                (source_scope,type,aggregate_ref,aggregate_revision) WHERE schema_version = 'billium.event.v2';

            -- A feed reads one seller and environment in cursor order; the bare cursor index made every reader
            -- filter out the events of all other sellers sharing the outbox.
            CREATE INDEX billium_outbox_event_seller_cursor
                ON public.billium_outbox_event (seller_ref,environment,tx_id,id);

            -- The scope key repeats seller and environment. Without correlated statistics the planner multiplies their
            -- selectivities, expects only a handful of matches and sorts the whole seller instead of walking the index.
            CREATE STATISTICS public.billium_outbox_event_scope_stats (dependencies, mcv)
                ON seller_ref, environment, (source_scope::jsonb) FROM public.billium_outbox_event;

            CREATE INDEX billium_outbox_event_scope_cursor ON public.billium_outbox_event ((source_scope::jsonb), tx_id, id);

            -- Conservative replay floor per scope, not a proof that deleted rows formed a prefix.
            CREATE TABLE public.billium_outbox_watermark (
                id bigint PRIMARY KEY CHECK (id >= 1),
                scope text NOT NULL CHECK (scope <> '' AND length(scope) <= 200),
                pruned_tx_id xid8 NOT NULL DEFAULT '0',
                pruned_id bigint NOT NULL DEFAULT 0 CHECK (pruned_id >= 0),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT billium_outbox_watermark_scope_key UNIQUE (scope)
            );

            INSERT INTO public.billium_outbox_watermark (id, scope) VALUES (1, 'installation');

            -- Append-only diagnostic history; max(commit_no) is never a freshness or activation proof.
            CREATE TABLE public.billium_money_commit_log (
                commit_no bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                kind text NOT NULL CHECK (kind ~ '^[a-z][a-z0-9_.]*$' AND length(kind) <= 200),
                session_epoch bigint NOT NULL CHECK (session_epoch >= 1),
                occurred_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE TRIGGER billium_money_commit_log_append_only BEFORE UPDATE OR DELETE ON public.billium_money_commit_log
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            -- Diagnostic/adopted metadata, never a standalone fence; rank 20 only for explicit admin transitions.
            CREATE TABLE public.billium_installation_state (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
                incarnation bigint NOT NULL DEFAULT 1 CHECK (incarnation >= 1),
                routing_epoch bigint NOT NULL DEFAULT 0 CHECK (routing_epoch >= 0),
                dispatch_enabled boolean NOT NULL DEFAULT false,
                writer_checkpoint text NULL CHECK (writer_checkpoint IS NULL OR length(writer_checkpoint) <= 200),
                lease_epoch bigint NOT NULL DEFAULT 0 CHECK (lease_epoch >= 0),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT billium_installation_state_installation_key UNIQUE (installation_ref)
            );

            -- One adopted OwnerSession per epoch. No process token or credential is stored; ordinary roots hold the
            -- current row FOR SHARE to commit, a seal takes it FOR UPDATE. Transitions are guarded here as a backstop.
            CREATE TABLE public.billium_financial_session (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
                epoch bigint NOT NULL CHECK (epoch >= 1),
                external_owner_session_ref text NOT NULL CHECK (external_owner_session_ref <> '' AND length(external_owner_session_ref) <= 200),
                deployment_generation bigint NOT NULL CHECK (deployment_generation >= 1),
                external_attachment_generation bigint NOT NULL CHECK (external_attachment_generation >= 1),
                authority_epoch bigint NOT NULL CHECK (authority_epoch >= 1),
                state text NOT NULL CHECK (state IN ('recovery', 'active', 'sealing', 'sealed')),
                activation_certificate_ref text NULL CHECK (activation_certificate_ref IS NULL OR (activation_certificate_ref <> '' AND length(activation_certificate_ref) <= 200)),
                sealed_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT billium_financial_session_epoch_key UNIQUE (installation_ref, epoch),
                CONSTRAINT billium_financial_session_sealed CHECK ((state = 'sealed') = (sealed_at IS NOT NULL)),
                CONSTRAINT billium_financial_session_certified CHECK (state = 'recovery' OR activation_certificate_ref IS NOT NULL)
            );

            CREATE UNIQUE INDEX billium_financial_session_one_live ON public.billium_financial_session (installation_ref)
                WHERE state IN ('active', 'sealing');

            CREATE OR REPLACE FUNCTION public.billium_financial_session_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.state <> 'recovery' OR NEW.activation_certificate_ref IS NOT NULL OR NEW.sealed_at IS NOT NULL
                       OR NEW.epoch <> coalesce((SELECT max(s.epoch) FROM public.billium_financial_session s WHERE s.installation_ref=NEW.installation_ref),0)+1
                    THEN RAISE EXCEPTION 'billium: a new epoch opens in recovery and requires activation' USING ERRCODE='55000'; END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium: financial sessions are never deleted' USING ERRCODE = '55000';
                END IF;
                IF (NEW.id, NEW.installation_ref, NEW.epoch, NEW.external_owner_session_ref, NEW.deployment_generation,
                    NEW.external_attachment_generation, NEW.authority_epoch, NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.id, OLD.installation_ref, OLD.epoch, OLD.external_owner_session_ref, OLD.deployment_generation,
                    OLD.external_attachment_generation, OLD.authority_epoch, OLD.created_at)
                   OR (OLD.activation_certificate_ref IS NOT NULL AND NEW.activation_certificate_ref IS DISTINCT FROM OLD.activation_certificate_ref)
                   OR (OLD.sealed_at IS NOT NULL AND NEW.sealed_at IS DISTINCT FROM OLD.sealed_at) THEN
                    RAISE EXCEPTION 'billium: financial session identity is immutable' USING ERRCODE = '55000';
                END IF;
                IF NEW.state IS DISTINCT FROM OLD.state AND NOT (
                       (OLD.state = 'recovery' AND NEW.state = 'active')
                    OR (OLD.state = 'active' AND NEW.state IN ('sealing', 'sealed', 'recovery'))
                    OR (OLD.state = 'sealing' AND NEW.state IN ('sealed', 'recovery'))
                ) THEN
                    RAISE EXCEPTION 'billium: financial session % -> % is not a transition', OLD.state, NEW.state USING ERRCODE = '55000';
                END IF;
                IF NEW.state = 'active' AND OLD.state = 'recovery' AND EXISTS (
                    SELECT 1 FROM public.billium_financial_session s
                    WHERE s.installation_ref = NEW.installation_ref AND s.epoch > NEW.epoch
                ) THEN
                    RAISE EXCEPTION 'billium: only the latest epoch may be activated' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_financial_session_guard() FROM PUBLIC;

            CREATE TRIGGER billium_financial_session_guard BEFORE INSERT OR UPDATE OR DELETE ON public.billium_financial_session
                FOR EACH ROW EXECUTE FUNCTION public.billium_financial_session_guard();

            -- Immutable history of session transitions with their external admission/evidence references.
            CREATE TABLE public.billium_financial_session_event (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                session_id bigint NOT NULL REFERENCES public.billium_financial_session (id),
                transition_ref text NOT NULL CHECK (transition_ref <> '' AND length(transition_ref) <= 200),
                transition text NOT NULL CHECK (transition IN ('opened', 'activated', 'sealing', 'sealed', 'superseded')),
                external_admission_ref text NULL CHECK (external_admission_ref IS NULL OR length(external_admission_ref) <= 200),
                evidence_ref text NULL CHECK (evidence_ref IS NULL OR length(evidence_ref) <= 200),
                actor_ref text NOT NULL CHECK (actor_ref <> '' AND length(actor_ref) <= 200),
                occurred_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT billium_financial_session_event_transition_key UNIQUE (session_id, transition_ref)
            );

            CREATE TRIGGER billium_financial_session_event_append_only BEFORE UPDATE OR DELETE ON public.billium_financial_session_event
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            -- Admission holds these rows FOR SHARE (rank 25), a transition FOR UPDATE; every change bumps generation.
            CREATE TABLE public.billium_module_state (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
                module_id text NOT NULL CHECK (module_id ~ '^[a-z][a-z0-9_]*$' AND length(module_id) <= 64),
                state text NOT NULL CHECK (state IN ('accepting', 'draining', 'recovery_only', 'retired')),
                config_revision bigint NOT NULL CHECK (config_revision >= 1),
                schema_version integer NOT NULL CHECK (schema_version >= 0),
                generation bigint NOT NULL CHECK (generation >= 1),
                last_transition_ref text NULL CHECK (last_transition_ref IS NULL OR (last_transition_ref <> '' AND length(last_transition_ref) <= 200)),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT billium_module_state_module_key UNIQUE (installation_ref, module_id)
            );

            CREATE OR REPLACE FUNCTION public.billium_module_state_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium: module state is never deleted' USING ERRCODE = '55000';
                END IF;
                IF (NEW.id, NEW.installation_ref, NEW.module_id) IS DISTINCT FROM (OLD.id, OLD.installation_ref, OLD.module_id)
                   OR NEW.generation <> OLD.generation + 1
                   OR NEW.last_transition_ref IS NULL
                   OR NEW.last_transition_ref IS NOT DISTINCT FROM OLD.last_transition_ref THEN
                    RAISE EXCEPTION 'billium: a module change is one transition with the next generation' USING ERRCODE = '55000';
                END IF;
                IF NEW.state IS DISTINCT FROM OLD.state AND NOT (
                       (OLD.state = 'accepting' AND NEW.state = 'draining')
                    OR (OLD.state = 'draining' AND NEW.state IN ('recovery_only', 'accepting'))
                    OR (OLD.state = 'recovery_only' AND NEW.state = 'retired')
                ) THEN
                    RAISE EXCEPTION 'billium: module % -> % is not a lifecycle step', OLD.state, NEW.state USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_module_state_guard() FROM PUBLIC;

            CREATE TRIGGER billium_module_state_guard BEFORE UPDATE OR DELETE ON public.billium_module_state
                FOR EACH ROW EXECUTE FUNCTION public.billium_module_state_guard();

            -- Append-only lifecycle history; a transition never deletes facts or impersonates PSP cancellation.
            CREATE TABLE public.billium_module_transitions (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                transition_ref text NOT NULL CHECK (transition_ref <> '' AND length(transition_ref) <= 200),
                installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
                module_id text NOT NULL CHECK (module_id ~ '^[a-z][a-z0-9_]*$'),
                from_state text NULL CHECK (from_state IN ('accepting', 'draining', 'recovery_only', 'retired')),
                to_state text NOT NULL CHECK (to_state IN ('accepting', 'draining', 'recovery_only', 'retired')),
                from_generation bigint NULL CHECK (from_generation >= 1),
                to_generation bigint NOT NULL CHECK (to_generation >= 1),
                actor_ref text NOT NULL CHECK (actor_ref <> '' AND length(actor_ref) <= 200),
                evidence_ref text NULL CHECK (evidence_ref IS NULL OR (evidence_ref <> '' AND length(evidence_ref) <= 200)),
                reason text NOT NULL CHECK (reason <> '' AND length(reason) <= 1000),
                occurred_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT billium_module_transitions_ref_key UNIQUE (transition_ref),
                CONSTRAINT billium_module_transitions_generation CHECK (
                    (from_generation IS NULL AND from_state IS NULL AND to_generation = 1)
                    OR to_generation = from_generation + 1
                )
            );

            CREATE INDEX billium_module_transitions_module ON public.billium_module_transitions (installation_ref, module_id, to_generation);

            CREATE TRIGGER billium_module_transitions_append_only BEFORE UPDATE OR DELETE ON public.billium_module_transitions
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
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

        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_idempotency_receipt');
        RuntimeGrants::grant('UPDATE (outcome, result, completed_at)', 'public.billium_idempotency_receipt');
        RuntimeGrants::grant('SELECT', 'public.billium_event_contract');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_outbox_event');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_outbox_watermark');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_money_commit_log');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_installation_state');
        RuntimeGrants::grant('SELECT', 'public.billium_financial_session');
        // PostgreSQL row locks require UPDATE on at least one column; this timestamp grants no state transition.
        RuntimeGrants::grant('UPDATE (updated_at)', 'public.billium_financial_session');
        RuntimeGrants::grant('SELECT', 'public.billium_financial_session_event');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_module_state');
        RuntimeGrants::grant('UPDATE (state, config_revision, schema_version, generation, last_transition_ref, updated_at)', 'public.billium_module_state');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_module_transitions');
    }

    public function down(): never
    {
        throw new LogicException('Billium migrations are forward-only.');
    }
};

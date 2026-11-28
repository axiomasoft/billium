<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

/**
 * Feed and inbox: feed partitions and bootstrap, inbox custody and conflicts, event contracts, outbox and command
 * delivery, feed subscriptions, observation scopes and command leases.
 */
return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- A partition belongs to one compound source (channel, authority, seller, environment, ref); the lineage
            -- is its current incarnation. Two frontiers: custody (contiguous durable arrivals) and accounted (durable dispositions).
            -- Staged bootstrap session and items live here with the partition they replace (one migration, one lifecycle).
            CREATE TABLE public.billium_feed_partition (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                channel text NOT NULL CHECK (channel ~ '^[a-z][a-z0-9_.]*$' AND length(channel) <= 200),
                source_authority text NOT NULL CHECK (source_authority <> '' AND length(source_authority) <= 200),
                source_lineage text NOT NULL CHECK (source_lineage <> '' AND length(source_lineage) <= 200),
                seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
                environment text NOT NULL CHECK (environment <> '' AND length(environment) <= 200),
                partition_ref text NOT NULL CHECK (partition_ref <> '' AND length(partition_ref) <= 200),
                generation integer NOT NULL DEFAULT 1 CHECK (generation >= 1),
                custody_sequence bigint NOT NULL DEFAULT 0 CHECK (custody_sequence >= 0),
                accounted_sequence bigint NOT NULL DEFAULT 0 CHECK (accounted_sequence >= 0),
                state text NOT NULL DEFAULT 'active' CHECK (state IN ('active', 'recovery_required', 'retired')),
                recovery_reason text NULL CHECK (recovery_reason IS NULL OR length(recovery_reason) <= 200),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT billium_feed_partition_frontiers CHECK (accounted_sequence <= custody_sequence),
                CONSTRAINT billium_feed_partition_source_key UNIQUE (channel, source_authority, source_lineage, seller_ref, environment, partition_ref, generation)
            );

            -- A source namespace has one live partition whatever its lineage: a changed lineage is detected, never a second partition.
            CREATE UNIQUE INDEX billium_feed_partition_live_key ON public.billium_feed_partition
                (channel, source_authority, seller_ref, environment, partition_ref) WHERE state <> 'retired';

            CREATE OR REPLACE FUNCTION public.billium_feed_partition_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium: feed partitions are never deleted' USING ERRCODE = '55000';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    IF EXISTS (SELECT 1 FROM public.billium_feed_partition p
                        WHERE (p.channel,p.source_authority,p.seller_ref,p.environment,p.partition_ref)=
                              (NEW.channel,NEW.source_authority,NEW.seller_ref,NEW.environment,NEW.partition_ref) AND p.state='retired')
                       AND NOT EXISTS (SELECT 1 FROM public.billium_feed_bootstrap_session b
                           JOIN public.billium_feed_partition p ON p.id=b.old_partition_id
                           WHERE (b.channel,b.source_authority,b.seller_ref,b.environment,b.partition_ref,b.source_lineage)=
                                 (NEW.channel,NEW.source_authority,NEW.seller_ref,NEW.environment,NEW.partition_ref,NEW.source_lineage)
                             AND b.status='ready' AND p.state='retired' AND NEW.generation=p.generation+1
                             AND NEW.custody_sequence=b.cursor_sequence AND NEW.accounted_sequence=b.cursor_sequence
                             AND NOT EXISTS (SELECT 1 FROM public.billium_feed_bootstrap_item i WHERE i.session_id=b.id AND i.state='staged')
                             AND NOT EXISTS (SELECT 1 FROM public.billium_feed_bootstrap_session used WHERE used.old_partition_id=p.id AND used.status='adopted'))
                    THEN RAISE EXCEPTION 'billium: a retired namespace requires ready bootstrap adoption' USING ERRCODE='55000'; END IF;
                    RETURN NEW;
                END IF;
                IF NEW.state='retired' AND OLD.state <> 'retired' AND NOT EXISTS (
                    SELECT 1 FROM public.billium_feed_bootstrap_session b
                    WHERE b.old_partition_id=OLD.id AND b.status='ready'
                      AND (b.channel,b.source_authority,b.seller_ref,b.environment)=
                          (OLD.channel,OLD.source_authority,OLD.seller_ref,OLD.environment)
                      AND ((b.partition_ref=OLD.partition_ref AND NOT EXISTS (
                              SELECT FROM public.billium_feed_observation_scope_link WHERE partition_id=OLD.id OR bootstrap_session_id=b.id))
                          OR EXISTS (SELECT FROM public.billium_feed_observation_scope_link prior
                              JOIN public.billium_feed_observation_scope_link manifest ON manifest.scope_id=prior.scope_id
                              WHERE prior.partition_id=OLD.id AND manifest.bootstrap_session_id=b.id))
                      AND NOT EXISTS (SELECT 1 FROM public.billium_feed_bootstrap_item i WHERE i.session_id=b.id AND i.state='staged'))
                THEN RAISE EXCEPTION 'billium: retirement requires ready bootstrap adoption' USING ERRCODE='55000'; END IF;
                IF (NEW.id, NEW.channel, NEW.source_authority, NEW.source_lineage, NEW.seller_ref, NEW.environment, NEW.partition_ref, NEW.generation, NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.id, OLD.channel, OLD.source_authority, OLD.source_lineage, OLD.seller_ref, OLD.environment, OLD.partition_ref, OLD.generation, OLD.created_at) THEN
                    RAISE EXCEPTION 'billium: feed partition source identity is immutable' USING ERRCODE = '55000';
                END IF;
                IF NEW.custody_sequence < OLD.custody_sequence OR NEW.accounted_sequence < OLD.accounted_sequence THEN
                    RAISE EXCEPTION 'billium: partition frontiers only advance (recovery adopts a new partition)' USING ERRCODE = '55000';
                END IF;
                IF OLD.state = 'retired' AND NEW.state <> 'retired' THEN
                    RAISE EXCEPTION 'billium: a retired partition stays retired' USING ERRCODE = '55000';
                END IF;
                IF OLD.state = 'recovery_required' AND NEW.state = 'active' THEN
                    RAISE EXCEPTION 'billium: recovery is left by adopting a verified bootstrap, never by reactivation' USING ERRCODE = '55000';
                END IF;
                IF OLD.state <> 'active' AND (NEW.accounted_sequence <> OLD.accounted_sequence OR NEW.custody_sequence <> OLD.custody_sequence) THEN
                    RAISE EXCEPTION 'billium: a partition outside active state does not advance' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_feed_partition_guard() FROM PUBLIC;

            CREATE TRIGGER billium_feed_partition_guard BEFORE INSERT OR UPDATE OR DELETE ON public.billium_feed_partition
                FOR EACH ROW EXECUTE FUNCTION public.billium_feed_partition_guard();

            CREATE TABLE public.billium_feed_bootstrap_session (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                bootstrap_ref text NOT NULL CHECK (bootstrap_ref <> '' AND length(bootstrap_ref) <= 200),
                channel text NOT NULL CHECK (channel ~ '^[a-z][a-z0-9_.]*$' AND length(channel) <= 200),
                source_authority text NOT NULL CHECK (source_authority <> '' AND length(source_authority) <= 200),
                source_lineage text NOT NULL CHECK (source_lineage <> '' AND length(source_lineage) <= 200),
                seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
                environment text NOT NULL CHECK (environment <> '' AND length(environment) <= 200),
                partition_ref text NOT NULL CHECK (partition_ref <> '' AND length(partition_ref) <= 200),
                cursor_sequence bigint NOT NULL CHECK (cursor_sequence >= 0),
                snapshot_digest text NOT NULL CHECK (snapshot_digest ~ '^sha256:[0-9a-f]{64}$'),
                item_count integer NOT NULL CHECK (item_count >= 0),
                source_retention_lease_ref text NOT NULL CHECK (source_retention_lease_ref <> '' AND length(source_retention_lease_ref) <= 200),
                status text NOT NULL DEFAULT 'loading' CHECK (status IN ('loading', 'verified', 'applying', 'ready', 'adopted', 'failed')),
                old_partition_id bigint NULL REFERENCES public.billium_feed_partition (id),
                new_partition_id bigint NULL REFERENCES public.billium_feed_partition (id),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT billium_feed_bootstrap_session_ref_key UNIQUE (bootstrap_ref),
                CONSTRAINT billium_feed_bootstrap_session_adopted CHECK ((status = 'adopted') = (new_partition_id IS NOT NULL))
            );

            -- One unfinished bootstrap per source namespace: the tail is held for exactly one staged snapshot.
            CREATE UNIQUE INDEX billium_feed_bootstrap_session_open ON public.billium_feed_bootstrap_session
                (channel, source_authority, seller_ref, environment, partition_ref) WHERE status IN ('loading', 'verified', 'applying', 'ready');

            CREATE TABLE public.billium_feed_bootstrap_item (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                session_id bigint NOT NULL REFERENCES public.billium_feed_bootstrap_session (id),
                operation_ref text NOT NULL CHECK (operation_ref <> '' AND length(operation_ref) <= 200),
                revision bigint NOT NULL CHECK (revision >= 0),
                material_digest text NOT NULL CHECK (material_digest ~ '^sha256:[0-9a-f]{64}$'),
                payload jsonb NOT NULL CHECK (jsonb_typeof(payload) = 'object' AND octet_length(payload::text) <= 65536),
                state text NOT NULL DEFAULT 'staged' CHECK (state IN ('staged', 'applied', 'parked', 'discrepancy')),
                result_ref text NULL CHECK (result_ref IS NULL OR (result_ref <> '' AND length(result_ref) <= 200)),
                disposed_at timestamptz NULL,
                CONSTRAINT billium_feed_bootstrap_item_key UNIQUE (session_id, operation_ref, revision),
                CONSTRAINT billium_feed_bootstrap_item_disposed CHECK ((state = 'staged') = (disposed_at IS NULL))
            );

            CREATE OR REPLACE FUNCTION public.billium_feed_bootstrap_item_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE s public.billium_feed_bootstrap_session;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium: bootstrap snapshot items are never deleted' USING ERRCODE = '55000';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    SELECT * INTO s FROM public.billium_feed_bootstrap_session WHERE id = NEW.session_id FOR SHARE;
                    IF NOT FOUND OR s.status <> 'loading'
                       OR (NEW.payload->>'sourceAuthority',NEW.payload->>'sourceLineage',NEW.payload->>'sellerRef',NEW.payload->>'environment',NEW.payload->>'partitionRef',
                           NEW.payload->>'operationRef',NEW.payload->>'operationRevision')
                          IS DISTINCT FROM (s.source_authority,s.source_lineage,s.seller_ref,s.environment,s.partition_ref,NEW.operation_ref,NEW.revision::text)
                    THEN RAISE EXCEPTION 'billium: bootstrap item differs from its manifest namespace' USING ERRCODE = '23514'; END IF;
                    RETURN NEW;
                END IF;
                IF (NEW.id, NEW.session_id, NEW.operation_ref, NEW.revision, NEW.material_digest, NEW.payload)
                   IS DISTINCT FROM (OLD.id, OLD.session_id, OLD.operation_ref, OLD.revision, OLD.material_digest, OLD.payload) THEN
                    RAISE EXCEPTION 'billium: snapshot content is immutable' USING ERRCODE = '55000';
                END IF;
                IF OLD.state <> 'staged' THEN
                    RAISE EXCEPTION 'billium: an item disposition is durable' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_feed_bootstrap_item_guard() FROM PUBLIC;

            CREATE TRIGGER billium_feed_bootstrap_item_guard BEFORE INSERT OR UPDATE OR DELETE ON public.billium_feed_bootstrap_item
                FOR EACH ROW EXECUTE FUNCTION public.billium_feed_bootstrap_item_guard();

            -- Applying a bootstrap takes the session's staged items one by one in manifest order. The session key
            -- index also holds every applied item, so each step re-read and sorted the whole session.
            CREATE INDEX billium_feed_bootstrap_item_staged
                ON public.billium_feed_bootstrap_item (session_id,id) WHERE state = 'staged';

            CREATE OR REPLACE FUNCTION public.billium_feed_bootstrap_session_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium: bootstrap sessions are never deleted' USING ERRCODE = '55000';
                END IF;
                IF (NEW.id, NEW.bootstrap_ref, NEW.channel, NEW.source_authority, NEW.source_lineage, NEW.seller_ref, NEW.environment, NEW.partition_ref,
                    NEW.cursor_sequence, NEW.snapshot_digest, NEW.item_count, NEW.source_retention_lease_ref, NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.id, OLD.bootstrap_ref, OLD.channel, OLD.source_authority, OLD.source_lineage, OLD.seller_ref, OLD.environment, OLD.partition_ref,
                    OLD.cursor_sequence, OLD.snapshot_digest, OLD.item_count, OLD.source_retention_lease_ref, OLD.created_at) THEN
                    RAISE EXCEPTION 'billium: bootstrap scope and snapshot identity are immutable' USING ERRCODE = '55000';
                END IF;
                IF OLD.status IN ('adopted', 'failed') THEN
                    RAISE EXCEPTION 'billium: a finished bootstrap session is immutable' USING ERRCODE = '55000';
                END IF;
                IF NEW.status = 'adopted' AND OLD.status <> 'ready' THEN
                    RAISE EXCEPTION 'billium: only a ready bootstrap (every item disposed) is adopted' USING ERRCODE = '55000';
                END IF;
                IF NEW.status = 'ready' AND EXISTS (SELECT 1 FROM public.billium_feed_bootstrap_item WHERE session_id = NEW.id AND state = 'staged') THEN
                    RAISE EXCEPTION 'billium: a bootstrap with undisposed items is not ready' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_feed_bootstrap_session_guard() FROM PUBLIC;

            CREATE TRIGGER billium_feed_bootstrap_session_guard BEFORE UPDATE OR DELETE ON public.billium_feed_bootstrap_session
                FOR EACH ROW EXECUTE FUNCTION public.billium_feed_bootstrap_session_guard();

            -- Raw custody of an authenticated delivery, separate from its economic application. Identity and content are
            -- immutable; status, retry and result columns move only through explicit transitions, and `applied` is terminal.
            CREATE TABLE public.billium_inbox_message (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                channel text NOT NULL CHECK (channel ~ '^[a-z][a-z0-9_.]*$' AND length(channel) <= 200),
                source_authority text NOT NULL CHECK (source_authority <> '' AND length(source_authority) <= 200),
                source_lineage text NOT NULL CHECK (source_lineage <> '' AND length(source_lineage) <= 200),
                seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
                environment text NOT NULL CHECK (environment <> '' AND length(environment) <= 200),
                external_event_id text NOT NULL CHECK (external_event_id <> '' AND length(external_event_id) <= 200),
                content_digest text NOT NULL CHECK (content_digest ~ '^sha256:[0-9a-f]{64}$'),
                payload jsonb NOT NULL CHECK (jsonb_typeof(payload) = 'object' AND octet_length(payload::text) <= 65536),
                partition_id bigint NULL REFERENCES public.billium_feed_partition (id),
                partition_ref text NOT NULL CHECK (partition_ref <> '' AND length(partition_ref) <= 200),
                source_sequence bigint NOT NULL CHECK (source_sequence >= 1),
                operation_ref text NOT NULL CHECK (operation_ref <> '' AND length(operation_ref) <= 200),
                operation_revision bigint NOT NULL CHECK (operation_revision >= 0),
                status text NOT NULL CHECK (status IN ('ready', 'parked_gap', 'parked_dependency', 'applied', 'quarantined', 'held_recovery')),
                attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
                next_attempt_at timestamptz NULL,
                handler_version text NULL CHECK (handler_version IS NULL OR length(handler_version) <= 200),
                park_kind text NULL CHECK (park_kind IS NULL OR length(park_kind) <= 100),
                park_ref text NULL CHECK (park_ref IS NULL OR length(park_ref) <= 200),
                last_error_class text NULL CHECK (last_error_class IS NULL OR length(last_error_class) <= 200),
                result_ref text NULL CHECK (result_ref IS NULL OR (result_ref <> '' AND length(result_ref) <= 200)),
                received_at timestamptz NOT NULL DEFAULT now(),
                applied_at timestamptz NULL,
                CONSTRAINT billium_inbox_message_identity_key UNIQUE (channel, source_authority, source_lineage, seller_ref, environment, external_event_id),
                CONSTRAINT billium_inbox_message_applied CHECK ((status = 'applied') = (applied_at IS NOT NULL)
                    AND (status <> 'applied' OR result_ref IS NOT NULL)),
                CONSTRAINT billium_inbox_message_parked CHECK ((status = 'parked_dependency') = (park_kind IS NOT NULL))
            );

            -- One live delivery per sequence slot of a source partition; a different event claiming a taken slot is held_recovery.
            CREATE UNIQUE INDEX billium_inbox_message_slot ON public.billium_inbox_message
                (channel, source_authority, source_lineage, seller_ref, environment, partition_ref, source_sequence)
                WHERE status NOT IN ('held_recovery', 'quarantined');

            CREATE INDEX billium_inbox_message_partition ON public.billium_inbox_message (partition_id, source_sequence);

            CREATE INDEX billium_inbox_message_due ON public.billium_inbox_message (next_attempt_at, id) WHERE status = 'parked_dependency';

            CREATE INDEX billium_inbox_message_operation ON public.billium_inbox_message (seller_ref, environment, operation_ref, operation_revision)
                WHERE status = 'parked_dependency';

            CREATE OR REPLACE FUNCTION public.billium_inbox_message_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium: inbox custody is never deleted by runtime' USING ERRCODE = '55000';
                END IF;
                IF (NEW.id, NEW.channel, NEW.source_authority, NEW.source_lineage, NEW.seller_ref, NEW.environment, NEW.external_event_id,
                    NEW.content_digest, NEW.payload, NEW.partition_ref, NEW.source_sequence, NEW.operation_ref, NEW.operation_revision, NEW.received_at)
                   IS DISTINCT FROM
                   (OLD.id, OLD.channel, OLD.source_authority, OLD.source_lineage, OLD.seller_ref, OLD.environment, OLD.external_event_id,
                    OLD.content_digest, OLD.payload, OLD.partition_ref, OLD.source_sequence, OLD.operation_ref, OLD.operation_revision, OLD.received_at) THEN
                    RAISE EXCEPTION 'billium: inbox delivery identity and content are immutable' USING ERRCODE = '55000';
                END IF;
                IF OLD.status = 'applied' THEN
                    RAISE EXCEPTION 'billium: an applied inbox message is final' USING ERRCODE = '55000';
                END IF;
                IF OLD.partition_id IS NOT NULL AND NEW.partition_id IS DISTINCT FROM OLD.partition_id
                   AND NOT EXISTS (SELECT 1 FROM public.billium_feed_partition p WHERE p.id = OLD.partition_id AND p.state = 'retired') THEN
                    RAISE EXCEPTION 'billium: a message moves only from a retired partition to the adopted one' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_inbox_message_guard() FROM PUBLIC;

            CREATE TRIGGER billium_inbox_message_guard BEFORE UPDATE OR DELETE ON public.billium_inbox_message
                FOR EACH ROW EXECUTE FUNCTION public.billium_inbox_message_guard();

            CREATE INDEX billium_inbox_message_pending_op_idx ON public.billium_inbox_message (seller_ref, environment, channel, source_authority, operation_ref, ((payload->>'installationRef')), operation_revision DESC, id DESC) WHERE status IN ('ready', 'parked_gap', 'parked_dependency');

            -- The same event identity with another content digest keeps the original untouched and this delivery here.
            CREATE TABLE public.billium_inbox_conflict (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                inbox_id bigint NOT NULL REFERENCES public.billium_inbox_message (id),
                conflicting_digest text NOT NULL CHECK (conflicting_digest ~ '^sha256:[0-9a-f]{64}$'),
                payload jsonb NOT NULL CHECK (jsonb_typeof(payload) = 'object' AND octet_length(payload::text) <= 65536),
                received_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT billium_inbox_conflict_delivery_key UNIQUE (inbox_id, conflicting_digest)
            );

            CREATE TRIGGER billium_inbox_conflict_append_only BEFORE UPDATE OR DELETE ON public.billium_inbox_conflict
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

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
            REVOKE ALL ON FUNCTION public.billium_delivery_identity_immutable() FROM PUBLIC;

            CREATE TRIGGER billium_delivery_identity_immutable BEFORE UPDATE ON public.billium_outbox_delivery
                FOR EACH ROW EXECUTE FUNCTION public.billium_delivery_identity_immutable();

            CREATE INDEX billium_outbox_delivery_destination_due
                ON public.billium_outbox_delivery(destination_ref,available_at,id) WHERE status='pending';

            CREATE INDEX billium_outbox_delivery_destination_expired
                ON public.billium_outbox_delivery(destination_ref,claim_until,id) WHERE status='in_flight';

            -- Prune proves an event has no undelivered delivery. The planner hashes that proof over every delivery row,
            -- so each prune batch read the whole delivered history; open deliveries are the small, hot part.
            CREATE INDEX billium_outbox_delivery_open_event
                ON public.billium_outbox_delivery (event_id) WHERE status <> 'delivered';

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
                IF OLD.closed_at IS NOT NULL AND NEW.closed_at IS DISTINCT FROM OLD.closed_at THEN
                    RAISE EXCEPTION 'closed subscription cannot reopen; open a new subscription' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_feed_floor_monotonic() FROM PUBLIC;

            CREATE TRIGGER billium_feed_floor_monotonic BEFORE UPDATE ON public.billium_feed_subscription
                FOR EACH ROW EXECUTE FUNCTION public.billium_feed_floor_monotonic();

            -- Only privileged maintenance may delete content; a caller-set GUC alone grants nothing.
            CREATE OR REPLACE FUNCTION public.billium_outbox_retention_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND current_setting('billium.outbox_prune',true) = 'on'
                   AND current_user = pg_get_userbyid((SELECT relowner FROM pg_class WHERE oid = TG_RELID)) THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'outbox content is immutable' USING ERRCODE = '55000';
            END $$;
            REVOKE ALL ON FUNCTION public.billium_outbox_retention_guard() FROM PUBLIC;

            CREATE TRIGGER billium_outbox_event_append_only BEFORE UPDATE OR DELETE ON public.billium_outbox_event
                FOR EACH ROW EXECUTE FUNCTION public.billium_outbox_retention_guard();

            -- Runtime has no direct session DML. Each capability performs the row change and its event together.
            CREATE OR REPLACE FUNCTION public.billium_open_financial_session(
                p_installation text,p_owner text,p_deployment bigint,p_attachment bigint,p_authority bigint,
                p_transition text,p_evidence text,p_actor text)
            RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE s public.billium_financial_session; v_id bigint;
            BEGIN
                IF coalesce(p_transition,'')='' OR coalesce(p_evidence,'')='' OR coalesce(p_actor,'')=''
                THEN RAISE EXCEPTION 'billium: session opening needs transition evidence' USING ERRCODE='23514'; END IF;
                PERFORM 1 FROM public.billium_installation_state WHERE installation_ref=p_installation FOR UPDATE;
                IF NOT FOUND THEN RAISE EXCEPTION 'billium: unknown installation' USING ERRCODE='23503'; END IF;
                SELECT * INTO s FROM public.billium_financial_session WHERE installation_ref=p_installation ORDER BY epoch DESC LIMIT 1 FOR UPDATE;
                IF s.state IN ('active','sealing') THEN
                    UPDATE public.billium_financial_session SET state='recovery',updated_at=now() WHERE id=s.id;
                    INSERT INTO public.billium_financial_session_event(session_id,transition_ref,transition,evidence_ref,actor_ref)
                    VALUES(s.id,p_transition,'superseded',p_evidence,p_actor);
                END IF;
                INSERT INTO public.billium_financial_session(installation_ref,epoch,external_owner_session_ref,deployment_generation,
                    external_attachment_generation,authority_epoch,state)
                VALUES(p_installation,coalesce(s.epoch,0)+1,p_owner,p_deployment,p_attachment,p_authority,'recovery') RETURNING id INTO v_id;
                INSERT INTO public.billium_financial_session_event(session_id,transition_ref,transition,evidence_ref,actor_ref)
                VALUES(v_id,p_transition,'opened',p_evidence,p_actor);
            END $$;
            REVOKE ALL ON FUNCTION public.billium_open_financial_session(p_installation text, p_owner text, p_deployment bigint, p_attachment bigint, p_authority bigint, p_transition text, p_evidence text, p_actor text) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.billium_transition_financial_session(
                p_installation text,p_epoch bigint,p_state text,p_certificate text,p_transition text,p_admission text,p_evidence text,p_actor text)
            RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE s public.billium_financial_session; v_transition text;
            BEGIN
                IF coalesce(p_transition,'')='' OR coalesce(p_actor,'')=''
                THEN RAISE EXCEPTION 'billium: session transition needs an identity' USING ERRCODE='23514'; END IF;
                SELECT * INTO s FROM public.billium_financial_session WHERE installation_ref=p_installation ORDER BY epoch DESC LIMIT 1 FOR UPDATE;
                IF s.id IS NULL OR s.epoch IS DISTINCT FROM p_epoch
                THEN RAISE EXCEPTION 'billium: only the current epoch may transition' USING ERRCODE='55000'; END IF;
                IF p_state='active' AND s.state='recovery' AND coalesce(p_certificate,'')<>'' AND coalesce(p_admission,'')<>'' AND coalesce(p_evidence,'')<>'' THEN
                    v_transition := 'activated';
                ELSIF p_state='sealing' AND s.state='active' THEN
                    v_transition := 'sealing';
                ELSIF p_state='sealed' AND s.state IN ('active','sealing') THEN
                    v_transition := 'sealed';
                ELSE
                    RAISE EXCEPTION 'billium: invalid session transition or missing activation evidence' USING ERRCODE='55000';
                END IF;
                UPDATE public.billium_financial_session SET state=p_state,updated_at=now(),
                    activation_certificate_ref=CASE WHEN p_state='active' THEN p_certificate ELSE activation_certificate_ref END,
                    sealed_at=CASE WHEN p_state='sealed' THEN now() ELSE sealed_at END WHERE id=s.id;
                INSERT INTO public.billium_financial_session_event(session_id,transition_ref,transition,external_admission_ref,evidence_ref,actor_ref)
                VALUES(s.id,p_transition,v_transition,p_admission,p_evidence,p_actor);
            END $$;
            REVOKE ALL ON FUNCTION public.billium_transition_financial_session(p_installation text, p_epoch bigint, p_state text, p_certificate text, p_transition text, p_admission text, p_evidence text, p_actor text) FROM PUBLIC;

            -- Adoption inserts its successor before marking the session adopted. At commit both must agree;
            -- ready evidence alone never authorizes a replacement partition to become live.
            CREATE OR REPLACE FUNCTION public.billium_feed_partition_adoption_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM public.billium_feed_partition p
                    WHERE (p.channel,p.source_authority,p.seller_ref,p.environment,p.partition_ref)=
                          (NEW.channel,NEW.source_authority,NEW.seller_ref,NEW.environment,NEW.partition_ref) AND p.state='retired')
                   AND NOT EXISTS (SELECT 1 FROM public.billium_feed_bootstrap_session b
                       JOIN public.billium_feed_partition p ON p.id=b.old_partition_id
                       WHERE b.status='adopted' AND b.new_partition_id=NEW.id AND NEW.generation=p.generation+1
                         AND (b.channel,b.source_authority,b.seller_ref,b.environment,b.partition_ref,b.source_lineage)=
                             (NEW.channel,NEW.source_authority,NEW.seller_ref,NEW.environment,NEW.partition_ref,NEW.source_lineage)
                         AND NEW.custody_sequence=b.cursor_sequence AND NEW.accounted_sequence=b.cursor_sequence
                         AND NOT EXISTS (SELECT 1 FROM public.billium_feed_bootstrap_item i WHERE i.session_id=b.id AND i.state='staged'))
                THEN RAISE EXCEPTION 'billium: a successor must commit with its bootstrap adoption' USING ERRCODE='55000'; END IF;
                RETURN NULL;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_feed_partition_adoption_guard() FROM PUBLIC;

            CREATE CONSTRAINT TRIGGER billium_feed_partition_adoption_guard AFTER INSERT ON public.billium_feed_partition
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.billium_feed_partition_adoption_guard();

            -- A previously staged snapshot also has to meet the namespace/count invariant before it can advance.
            CREATE OR REPLACE FUNCTION public.billium_feed_bootstrap_manifest_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF NEW.status IN ('verified','applying','ready','adopted') AND (
                    (SELECT count(*) FROM public.billium_feed_bootstrap_item WHERE session_id=NEW.id) <> NEW.item_count
                    OR EXISTS (SELECT 1 FROM public.billium_feed_bootstrap_item i WHERE i.session_id=NEW.id
                        AND (i.payload->>'sourceAuthority',i.payload->>'sourceLineage',i.payload->>'sellerRef',i.payload->>'environment',i.payload->>'partitionRef',
                             i.payload->>'operationRef',i.payload->>'operationRevision')
                            IS DISTINCT FROM (NEW.source_authority,NEW.source_lineage,NEW.seller_ref,NEW.environment,NEW.partition_ref,i.operation_ref,i.revision::text)))
                THEN RAISE EXCEPTION 'billium: bootstrap manifest is incomplete or crosses namespaces' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_feed_bootstrap_manifest_guard() FROM PUBLIC;

            CREATE TRIGGER billium_feed_bootstrap_manifest_guard BEFORE UPDATE OF status ON public.billium_feed_bootstrap_session
                FOR EACH ROW EXECUTE FUNCTION public.billium_feed_bootstrap_manifest_guard();

            CREATE TABLE public.billium_feed_observation_root_scope (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                root_key text NOT NULL UNIQUE CHECK (root_key ~ '^sha256:[0-9a-f]{64}$'),
                channel text NOT NULL CHECK (channel <> ''),
                installation_ref text NOT NULL CHECK (installation_ref <> ''),
                seller_ref text NOT NULL CHECK (seller_ref <> ''),
                environment text NOT NULL CHECK (environment <> ''),
                source_authority text NOT NULL CHECK (source_authority <> ''),
                provider_account_ref text NOT NULL CHECK (provider_account_ref <> ''),
                root_operation_ref text NOT NULL CHECK (root_operation_ref <> ''),
                intent_owner_namespace text NOT NULL CHECK (intent_owner_namespace <> ''),
                first_source_lineage text NOT NULL CHECK (first_source_lineage <> ''),
                first_partition_ref text NOT NULL CHECK (first_partition_ref <> ''),
                first_proof_digest text NOT NULL CHECK (first_proof_digest ~ '^sha256:[0-9a-f]{64}$')
            );

            CREATE TRIGGER billium_feed_root_scope_immutable BEFORE UPDATE OR DELETE ON public.billium_feed_observation_root_scope
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE TABLE public.billium_feed_observation_scope_link (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                scope_id bigint NOT NULL REFERENCES public.billium_feed_observation_root_scope(id),
                inbox_id bigint UNIQUE REFERENCES public.billium_inbox_message(id),
                partition_id bigint UNIQUE REFERENCES public.billium_feed_partition(id),
                bootstrap_session_id bigint UNIQUE REFERENCES public.billium_feed_bootstrap_session(id),
                proof_digest text NOT NULL CHECK (proof_digest ~ '^sha256:[0-9a-f]{64}$'),
                CHECK (num_nonnulls(inbox_id,partition_id,bootstrap_session_id) = 1)
            );

            CREATE INDEX billium_feed_scope_link_root ON public.billium_feed_observation_scope_link(scope_id);

            CREATE TRIGGER billium_feed_scope_link_immutable BEFORE UPDATE OR DELETE ON public.billium_feed_observation_scope_link
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE FUNCTION public.billium_qualified_retirement_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
                IF EXISTS (SELECT FROM public.billium_feed_observation_scope_link WHERE partition_id=OLD.id)
                    AND NOT EXISTS (
                        SELECT FROM public.billium_feed_bootstrap_session b
                        JOIN public.billium_feed_partition successor ON successor.id=b.new_partition_id
                        JOIN public.billium_feed_observation_scope_link prior ON prior.partition_id=OLD.id
                        JOIN public.billium_feed_observation_scope_link manifest ON manifest.bootstrap_session_id=b.id AND manifest.scope_id=prior.scope_id
                        JOIN public.billium_feed_observation_scope_link replacement ON replacement.partition_id=successor.id AND replacement.scope_id=prior.scope_id
                        WHERE b.old_partition_id=OLD.id AND b.status='adopted' AND successor.generation=OLD.generation+1
                          AND (b.channel,b.source_authority,b.seller_ref,b.environment,b.partition_ref,b.source_lineage)=
                              (successor.channel,successor.source_authority,successor.seller_ref,successor.environment,successor.partition_ref,successor.source_lineage)
                          AND b.cursor_sequence=successor.accounted_sequence AND successor.custody_sequence>=b.cursor_sequence
                          AND NOT EXISTS (SELECT FROM public.billium_feed_bootstrap_item i WHERE i.session_id=b.id AND i.state='staged'))
                THEN RAISE EXCEPTION 'billium: qualified retirement requires committed adoption of the same root' USING ERRCODE='55000'; END IF;
                RETURN NULL;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_qualified_retirement_guard() FROM PUBLIC;

            CREATE CONSTRAINT TRIGGER billium_qualified_retirement_guard AFTER UPDATE ON public.billium_feed_partition
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (OLD.state <> 'retired' AND NEW.state='retired')
                EXECUTE FUNCTION public.billium_qualified_retirement_guard();

            CREATE TABLE public.billium_feed_observation_command (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK (installation_ref <> ''),
                command_ref text NOT NULL CHECK (command_ref <> ''),
                binding_digest text NOT NULL CHECK (binding_digest ~ '^sha256:[0-9a-f]{64}$'),
                proof_digest text NOT NULL CHECK (proof_digest ~ '^sha256:[0-9a-f]{64}$'),
                intent_owner_namespace text NOT NULL CHECK (intent_owner_namespace <> ''),
                scope_id bigint REFERENCES public.billium_feed_observation_root_scope(id),
                disposition text NOT NULL CHECK (disposition IN ('received','conflict')),
                payload jsonb NOT NULL CHECK (jsonb_typeof(payload)='object'),
                CHECK ((disposition='received')=(scope_id IS NOT NULL)),
                UNIQUE (installation_ref,command_ref,binding_digest)
            );

            CREATE UNIQUE INDEX billium_observation_received_command_key
                ON public.billium_feed_observation_command(installation_ref,command_ref) WHERE disposition='received';

            CREATE TRIGGER billium_feed_observation_command_immutable BEFORE UPDATE OR DELETE ON public.billium_feed_observation_command
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE FUNCTION public.billium_idempotency_scope_immutable() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
                IF NEW.financial_scope IS DISTINCT FROM OLD.financial_scope THEN
                    RAISE EXCEPTION 'billium: financial receipt scope is immutable' USING ERRCODE='55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_idempotency_scope_immutable() FROM PUBLIC;

            CREATE TRIGGER billium_idempotency_scope_immutable BEFORE UPDATE ON public.billium_idempotency_receipt
                FOR EACH ROW EXECUTE FUNCTION public.billium_idempotency_scope_immutable();

            CREATE FUNCTION public.billium_idempotency_input_digest_immutable() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
                IF NEW.input_digest IS DISTINCT FROM OLD.input_digest THEN
                    RAISE EXCEPTION 'billium: command receipt input digest is immutable' USING ERRCODE='55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_idempotency_input_digest_immutable() FROM PUBLIC;

            CREATE TRIGGER billium_idempotency_input_digest_immutable BEFORE UPDATE OF input_digest ON public.billium_idempotency_receipt
                FOR EACH ROW EXECUTE FUNCTION public.billium_idempotency_input_digest_immutable();

            CREATE FUNCTION public.billium_receipt_original_execution_binding_immutable() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF OLD.original_execution_binding_ref IS DISTINCT FROM NEW.original_execution_binding_ref THEN
                    RAISE EXCEPTION 'billium: original execution binding is immutable from claim'
                        USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_receipt_original_execution_binding_immutable() FROM PUBLIC;

            CREATE TRIGGER billium_receipt_original_execution_binding_immutable BEFORE UPDATE
                ON public.billium_idempotency_receipt FOR EACH ROW
                EXECUTE FUNCTION public.billium_receipt_original_execution_binding_immutable();

            CREATE TABLE public.billium_feed_command_lease (
                lease_key        text        PRIMARY KEY CHECK (lease_key ~ '^sha256:[0-9a-f]{64}$'),
                installation_ref text        NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
                seller_ref       text        NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
                environment      text        NOT NULL CHECK (environment IN ('test', 'live')),
                lease_token      text        NULL CHECK (lease_token IS NULL OR length(lease_token) = 48),
                lease_epoch      bigint      NOT NULL DEFAULT 1 CHECK (lease_epoch >= 1),
                owner_ref        text        NULL CHECK (owner_ref IS NULL OR (owner_ref <> '' AND length(owner_ref) <= 200)),
                acquired_at      timestamptz NOT NULL DEFAULT clock_timestamp(),
                lease_until      timestamptz NOT NULL
            );

            CREATE INDEX idx_billium_feed_command_lease_active_expiry
                ON public.billium_feed_command_lease (lease_until)
                WHERE lease_token IS NOT NULL;

            CREATE TABLE public.billium_feed_bootstrap_pin_auth (
                lease_ref         text PRIMARY KEY REFERENCES public.billium_feed_bootstrap_lease(lease_ref),
                pin_secret_hash   text NOT NULL CHECK (pin_secret_hash ~ '^[0-9a-f]{64}$'),
                command_lease_key text NULL CHECK (command_lease_key IS NULL OR command_lease_key ~ '^sha256:[0-9a-f]{64}$')
            );

            CREATE OR REPLACE FUNCTION public.billium_feed_register_bootstrap_pin(
                p_lease_ref         text,
                p_pin_secret_hash   text,
                p_command_lease_key text DEFAULT NULL
            ) RETURNS void
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $$
            DECLARE
                v_rls_on boolean;
                v_inst   text := nullif(pg_catalog.current_setting('billium.installation_ref', true), '');
                v_seller text := nullif(pg_catalog.current_setting('billium.seller_ref', true), '');
                v_env    text := nullif(pg_catalog.current_setting('billium.environment', true), '');
                v_exists boolean;
            BEGIN
                SELECT c.relrowsecurity INTO v_rls_on
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public' AND c.relname = 'billium_feed_bootstrap_lease';

                IF COALESCE(v_rls_on, false) AND (v_inst IS NULL OR v_seller IS NULL OR v_env IS NULL) THEN
                    RAISE EXCEPTION 'RLS session context required for pin registration' USING ERRCODE = '42501';
                END IF;

                SELECT EXISTS (
                    SELECT 1 FROM public.billium_feed_bootstrap_lease
                    WHERE lease_ref = p_lease_ref
                      AND state = 'active'
                      AND snapshot_digest IS NULL
                      AND (
                          NOT COALESCE(v_rls_on, false) OR (
                              (source_scope::jsonb->>'installationRef') = v_inst
                              AND (source_scope::jsonb->>'sellerRef') = v_seller
                              AND (source_scope::jsonb->>'environment') = v_env
                          )
                      )
                ) INTO v_exists;

                IF NOT v_exists THEN
                    RAISE EXCEPTION 'active bootstrap pin not found for registration' USING ERRCODE = '22023';
                END IF;

                INSERT INTO public.billium_feed_bootstrap_pin_auth (lease_ref, pin_secret_hash, command_lease_key)
                VALUES (p_lease_ref, p_pin_secret_hash, p_command_lease_key);
            END;
            $$;
            REVOKE ALL ON FUNCTION public.billium_feed_register_bootstrap_pin(p_lease_ref text, p_pin_secret_hash text, p_command_lease_key text) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.billium_feed_extend_bootstrap_pin(
                p_lease_ref        text,
                p_subscription_ref text,
                p_snapshot_ref     text,
                p_pin_secret       text,
                p_ttl_seconds      integer,
                p_lease_token      text DEFAULT NULL
            ) RETURNS timestamptz
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $$
            DECLARE
                v_new_expires       timestamptz;
                v_lease_epoch       bigint;
                v_stored_secret     text;
                v_command_lease_key text;
                v_rls_on            boolean;
                v_inst              text := nullif(pg_catalog.current_setting('billium.installation_ref', true), '');
                v_seller            text := nullif(pg_catalog.current_setting('billium.seller_ref', true), '');
                v_env               text := nullif(pg_catalog.current_setting('billium.environment', true), '');
            BEGIN
                IF p_ttl_seconds IS NULL OR p_ttl_seconds < 1 OR p_ttl_seconds > 300 THEN
                    RAISE EXCEPTION 'bootstrap pin TTL extension out of bounds (1..300s)' USING ERRCODE = '22023';
                END IF;

                IF p_pin_secret IS NULL OR p_pin_secret = '' THEN
                    RETURN NULL;
                END IF;

                SELECT c.relrowsecurity INTO v_rls_on
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public' AND c.relname = 'billium_feed_bootstrap_lease';

                IF COALESCE(v_rls_on, false) AND (v_inst IS NULL OR v_seller IS NULL OR v_env IS NULL) THEN
                    RETURN NULL;
                END IF;

                SELECT a.pin_secret_hash, a.command_lease_key
                INTO v_stored_secret, v_command_lease_key
                FROM public.billium_feed_bootstrap_pin_auth a
                WHERE a.lease_ref = p_lease_ref
                FOR UPDATE;

                IF v_stored_secret IS NULL
                   OR v_stored_secret <> pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(p_pin_secret, 'UTF8')), 'hex') THEN
                    RETURN NULL;
                END IF;

                IF v_command_lease_key IS NOT NULL THEN
                    IF p_lease_token IS NULL THEN
                        RETURN NULL;
                    END IF;

                    UPDATE public.billium_feed_command_lease
                    SET lease_until = pg_catalog.clock_timestamp() + (p_ttl_seconds * interval '1 second')
                    WHERE lease_key = v_command_lease_key
                      AND lease_token = p_lease_token
                      AND lease_until > pg_catalog.clock_timestamp()
                      AND (NOT COALESCE(v_rls_on, false) OR (installation_ref = v_inst AND seller_ref = v_seller AND environment = v_env))
                    RETURNING lease_epoch INTO v_lease_epoch;

                    IF v_lease_epoch IS NULL THEN
                        RETURN NULL;
                    END IF;
                ELSIF p_lease_token IS NOT NULL THEN
                    RETURN NULL;
                END IF;

                UPDATE public.billium_feed_bootstrap_lease
                SET expires_at = pg_catalog.clock_timestamp() + (p_ttl_seconds * interval '1 second')
                WHERE lease_ref = p_lease_ref
                  AND subscription_ref = p_subscription_ref
                  AND snapshot_ref = p_snapshot_ref
                  AND state = 'active'
                  AND snapshot_digest IS NULL
                  AND expires_at > pg_catalog.clock_timestamp()
                  AND (
                      NOT COALESCE(v_rls_on, false) OR (
                          (source_scope::jsonb->>'installationRef') = v_inst
                          AND (source_scope::jsonb->>'sellerRef') = v_seller
                          AND (source_scope::jsonb->>'environment') = v_env
                      )
                  )
                RETURNING expires_at INTO v_new_expires;

                RETURN v_new_expires;
            END;
            $$;
            REVOKE ALL ON FUNCTION public.billium_feed_extend_bootstrap_pin(p_lease_ref text, p_subscription_ref text, p_snapshot_ref text, p_pin_secret text, p_ttl_seconds integer, p_lease_token text) FROM PUBLIC;
            SQL);

        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_feed_partition');
        RuntimeGrants::grant('UPDATE (custody_sequence, accounted_sequence, state, recovery_reason, updated_at)', 'public.billium_feed_partition');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_feed_bootstrap_session');
        RuntimeGrants::grant('UPDATE (status, old_partition_id, new_partition_id, updated_at)', 'public.billium_feed_bootstrap_session');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_feed_bootstrap_item');
        RuntimeGrants::grant('UPDATE (state, result_ref, disposed_at)', 'public.billium_feed_bootstrap_item');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_inbox_message');
        RuntimeGrants::grant('UPDATE (partition_id, status, attempts, next_attempt_at, handler_version, park_kind, park_ref, last_error_class, result_ref, applied_at)', 'public.billium_inbox_message');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_inbox_conflict');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE', 'public.billium_outbox_delivery');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_outbox_command');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE', 'public.billium_outbox_command_delivery');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_feed_subscription');
        // closed_at is one-way (trigger); closing releases the subscription's prune pin.
        RuntimeGrants::grant('UPDATE (floor_tx_id, floor_id, closed_at)', 'public.billium_feed_subscription');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_feed_bootstrap_lease');
        RuntimeGrants::grant('UPDATE (snapshot_digest, cursor_token, acknowledged_at, state)', 'public.billium_feed_bootstrap_lease');
        RuntimeGrants::grant('EXECUTE', 'FUNCTION public.billium_open_financial_session(p_installation text, p_owner text, p_deployment bigint, p_attachment bigint, p_authority bigint, p_transition text, p_evidence text, p_actor text)');
        RuntimeGrants::grant('EXECUTE', 'FUNCTION public.billium_transition_financial_session(p_installation text, p_epoch bigint, p_state text, p_certificate text, p_transition text, p_admission text, p_evidence text, p_actor text)');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_feed_observation_root_scope');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_feed_observation_scope_link');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_feed_observation_command');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE', 'public.billium_feed_command_lease');
        RuntimeGrants::revokeAll('public.billium_feed_bootstrap_pin_auth');
        RuntimeGrants::grant('EXECUTE', 'FUNCTION public.billium_feed_register_bootstrap_pin(p_lease_ref text, p_pin_secret_hash text, p_command_lease_key text)');
        RuntimeGrants::grant('EXECUTE', 'FUNCTION public.billium_feed_extend_bootstrap_pin(p_lease_ref text, p_subscription_ref text, p_snapshot_ref text, p_pin_secret text, p_ttl_seconds integer, p_lease_token text)');
    }

    public function down(): never
    {
        throw new LogicException('Billium migrations are forward-only.');
    }
};

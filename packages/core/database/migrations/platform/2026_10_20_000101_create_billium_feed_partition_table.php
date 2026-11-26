<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        // REQ-CON-27/D60: a partition belongs to one compound source (channel, authority, seller, environment, ref); the lineage
        // is its current incarnation. Two frontiers: custody (contiguous durable arrivals) and accounted (durable dispositions).
        // Staged bootstrap session and items live here with the partition they replace (one migration, one lifecycle).
        DB::unprepared(<<<'SQL'
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
            CREATE TRIGGER billium_feed_partition_guard BEFORE UPDATE OR DELETE ON public.billium_feed_partition
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
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium: bootstrap snapshot items are never deleted' USING ERRCODE = '55000';
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
            CREATE TRIGGER billium_feed_bootstrap_item_guard BEFORE UPDATE OR DELETE ON public.billium_feed_bootstrap_item
                FOR EACH ROW EXECUTE FUNCTION public.billium_feed_bootstrap_item_guard();

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
            SQL);
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_feed_partition');
        RuntimeGrants::grant('UPDATE (custody_sequence, accounted_sequence, state, recovery_reason, updated_at)', 'public.billium_feed_partition');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_feed_bootstrap_session');
        RuntimeGrants::grant('UPDATE (status, new_partition_id, old_partition_id, updated_at)', 'public.billium_feed_bootstrap_session');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_feed_bootstrap_item');
        RuntimeGrants::grant('UPDATE (state, result_ref, disposed_at)', 'public.billium_feed_bootstrap_item');
    }

    public function down(): void
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

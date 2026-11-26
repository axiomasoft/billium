<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        // INV-08/D60: raw custody of an authenticated delivery, separate from its economic application. Identity and content are
        // immutable; status, retry and result columns move only through explicit transitions, and `applied` is terminal.
        DB::unprepared(<<<'SQL'
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
            SQL);
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_inbox_message');
        RuntimeGrants::grant(
            'UPDATE (partition_id, status, attempts, next_attempt_at, handler_version, park_kind, park_ref, last_error_class, result_ref, applied_at)',
            'public.billium_inbox_message',
        );
    }

    public function down(): void
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_collection_notification (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test', 'live')),
                message_id text NOT NULL,
                dunning_case_id bigint NOT NULL,
                case_ref text NOT NULL,
                step_id bigint NULL REFERENCES public.billium_collection_step(id),
                step_ref text NULL,
                step_no integer NOT NULL DEFAULT 0 CHECK (step_no >= 0),
                template_ref text NOT NULL,
                template_revision text NOT NULL,
                recipient_channel text NOT NULL CHECK (recipient_channel IN ('email', 'sms', 'webhook', 'push', 'in_app')),
                recipient_ref text NOT NULL,
                payload jsonb NOT NULL CHECK (
                    jsonb_typeof(payload) = 'object'
                    AND payload::text !~ '[0-9]{13,19}'
                    AND payload::text !~ '(^|[^0-9A-Za-z])([0-9][ .-]?){13,19}($|[^0-9A-Za-z])'
                    AND payload::text !~* '"(cvv2?|cvc2?|pan|card_?cvv|card_?number|api_?key|password|secret)"[[:space:]]*:'
                ),
                payload_digest text NOT NULL CHECK (payload_digest ~ '^sha256:[0-9a-f]{64}$'),
                status text NOT NULL CHECK (status IN ('pending', 'delivered', 'failed_delivery')),
                notification_revision bigint NOT NULL DEFAULT 1 CHECK (notification_revision >= 1),
                delivery_attempts integer NOT NULL DEFAULT 0 CHECK (delivery_attempts >= 0),
                transport_ack_ref text NULL,
                transport_acked_at timestamptz NULL,
                last_delivery_error text NULL,
                last_attempt_at timestamptz NULL,
                enqueued_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                UNIQUE (seller_ref, environment, message_id),
                UNIQUE (dunning_case_id, step_no, template_ref, template_revision, recipient_channel),
                FOREIGN KEY (dunning_case_id, seller_ref, environment)
                    REFERENCES public.billium_collection_dunning_case (id, seller_ref, environment),
                CHECK (
                    (step_no = 0 AND step_id IS NULL AND step_ref IS NULL)
                    OR (step_no >= 1 AND step_id IS NOT NULL AND step_ref IS NOT NULL)
                ),
                CHECK (status <> 'delivered' OR (transport_ack_ref IS NOT NULL AND transport_acked_at IS NOT NULL)),
                CHECK (status = 'delivered' OR (transport_ack_ref IS NULL AND transport_acked_at IS NULL))
            );

            CREATE INDEX billium_collection_notification_due_idx
                ON public.billium_collection_notification (seller_ref, environment, status, enqueued_at, id);

            CREATE INDEX billium_collection_notification_case_idx
                ON public.billium_collection_notification (seller_ref, environment, dunning_case_id, step_no);

            CREATE TABLE public.billium_collection_host_signal (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test', 'live')),
                signal_ref text NOT NULL,
                dunning_case_id bigint NOT NULL,
                case_ref text NOT NULL,
                billing_account_ref text NOT NULL,
                obligation_ref text NOT NULL,
                component_ref text NULL,
                signal_kind text NOT NULL CHECK (
                    signal_kind IN (
                        'grace',
                        'read_only',
                        'renewal_succeeded',
                        'renewal_failed',
                        'customer_action_required',
                        'manual_intervention'
                    )
                ),
                signal_dedupe_key text NOT NULL,
                case_revision bigint NOT NULL CHECK (case_revision >= 1),
                policy_ref text NOT NULL,
                policy_version integer NOT NULL CHECK (policy_version >= 1),
                signal_only boolean NOT NULL DEFAULT true CHECK (signal_only = true),
                payload jsonb NOT NULL CHECK (
                    jsonb_typeof(payload) = 'object'
                    AND payload::text !~ '[0-9]{13,19}'
                    AND payload::text !~ '(^|[^0-9A-Za-z])([0-9][ .-]?){13,19}($|[^0-9A-Za-z])'
                    AND payload::text !~* '"(cvv2?|cvc2?|pan|card_?cvv|card_?number|api_?key|password|secret)"[[:space:]]*:'
                ),
                signal_digest text NOT NULL CHECK (signal_digest ~ '^sha256:[0-9a-f]{64}$'),
                effective_at timestamptz NOT NULL,
                recorded_at timestamptz NOT NULL,
                UNIQUE (seller_ref, environment, signal_ref),
                UNIQUE (dunning_case_id, signal_kind, signal_dedupe_key),
                FOREIGN KEY (dunning_case_id, seller_ref, environment)
                    REFERENCES public.billium_collection_dunning_case (id, seller_ref, environment)
            );

            CREATE INDEX billium_collection_host_signal_case_idx
                ON public.billium_collection_host_signal (seller_ref, environment, dunning_case_id, id);

            CREATE INDEX billium_collection_host_signal_account_idx
                ON public.billium_collection_host_signal (seller_ref, environment, billing_account_ref, id);

            CREATE TABLE public.billium_collection_manual_intervention (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test', 'live')),
                intervention_ref text NOT NULL,
                dunning_case_id bigint NOT NULL,
                case_ref text NOT NULL,
                step_id bigint NULL REFERENCES public.billium_collection_step(id),
                step_ref text NULL,
                action text NOT NULL CHECK (action IN ('pause_case', 'resume_case', 'reschedule_next_attempt', 'cancel_case')),
                actor_ref text NOT NULL,
                privilege_ref text NOT NULL,
                reason text NOT NULL CHECK (btrim(reason) <> ''),
                recovery_proof_ref text NOT NULL CHECK (btrim(recovery_proof_ref) <> ''),
                recovery_proof_digest text NOT NULL CHECK (recovery_proof_digest ~ '^sha256:[0-9a-f]{64}$'),
                from_case_revision bigint NOT NULL CHECK (from_case_revision >= 1),
                to_case_revision bigint NOT NULL CHECK (to_case_revision = from_case_revision + 1),
                before_status text NOT NULL CHECK (before_status IN ('open', 'active', 'paused')),
                after_status text NOT NULL CHECK (after_status IN ('open', 'paused', 'cancelled')),
                before_digest text NOT NULL CHECK (before_digest ~ '^sha256:[0-9a-f]{64}$'),
                after_digest text NOT NULL CHECK (after_digest ~ '^sha256:[0-9a-f]{64}$'),
                receipt_digest text NOT NULL CHECK (receipt_digest ~ '^sha256:[0-9a-f]{64}$'),
                signal_ref text NOT NULL,
                recorded_at timestamptz NOT NULL,
                UNIQUE (seller_ref, environment, intervention_ref),
                UNIQUE (dunning_case_id, from_case_revision),
                FOREIGN KEY (dunning_case_id, seller_ref, environment)
                    REFERENCES public.billium_collection_dunning_case (id, seller_ref, environment),
                FOREIGN KEY (seller_ref, environment, signal_ref)
                    REFERENCES public.billium_collection_host_signal (seller_ref, environment, signal_ref)
            );

            CREATE INDEX billium_collection_manual_intervention_case_idx
                ON public.billium_collection_manual_intervention (seller_ref, environment, dunning_case_id, to_case_revision);

            CREATE OR REPLACE FUNCTION public.billium_collection_notification_guard()
            RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium_collection_notification cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF (
                    NEW.seller_ref, NEW.environment, NEW.message_id, NEW.dunning_case_id, NEW.case_ref,
                    NEW.step_id, NEW.step_ref, NEW.step_no, NEW.template_ref, NEW.template_revision,
                    NEW.recipient_channel, NEW.recipient_ref, NEW.payload, NEW.payload_digest, NEW.enqueued_at
                ) IS DISTINCT FROM (
                    OLD.seller_ref, OLD.environment, OLD.message_id, OLD.dunning_case_id, OLD.case_ref,
                    OLD.step_id, OLD.step_ref, OLD.step_no, OLD.template_ref, OLD.template_revision,
                    OLD.recipient_channel, OLD.recipient_ref, OLD.payload, OLD.payload_digest, OLD.enqueued_at
                ) THEN
                    RAISE EXCEPTION 'billium_collection_notification identity and payload are immutable' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'delivered' THEN
                    RAISE EXCEPTION 'billium_collection_notification cannot transition out of delivered state' USING ERRCODE = '23514';
                END IF;
                IF NEW.notification_revision <> OLD.notification_revision + 1 THEN
                    RAISE EXCEPTION 'billium_collection_notification revision must advance monotonically by 1' USING ERRCODE = '23514';
                END IF;
                IF NEW.delivery_attempts < OLD.delivery_attempts THEN
                    RAISE EXCEPTION 'billium_collection_notification delivery_attempts cannot regress' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;

            REVOKE ALL ON FUNCTION public.billium_collection_notification_guard() FROM PUBLIC;

            CREATE TRIGGER billium_collection_notification_trg
                BEFORE UPDATE OR DELETE ON public.billium_collection_notification
                FOR EACH ROW EXECUTE FUNCTION public.billium_collection_notification_guard();

            CREATE TRIGGER billium_collection_host_signal_append_only
                BEFORE UPDATE OR DELETE ON public.billium_collection_host_signal
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE TRIGGER billium_collection_manual_intervention_append_only
                BEFORE UPDATE OR DELETE ON public.billium_collection_manual_intervention
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            SQL);

        $runtime = config('billium.connection') ?? config('database.default');
        $username = DB::connection($runtime)->getConfig('username');
        if (! is_string($username) || $username === '') {
            throw new LogicException('Collection runtime role is required');
        }
        $role = '"'.str_replace('"', '""', $username).'"';

        DB::unprepared('REVOKE ALL ON TABLE public.billium_collection_notification FROM PUBLIC, '.$role);
        DB::unprepared('GRANT SELECT, INSERT, UPDATE ON TABLE public.billium_collection_notification TO '.$role);

        foreach (['collection_host_signal', 'collection_manual_intervention'] as $table) {
            DB::unprepared('REVOKE ALL ON TABLE public.billium_'.$table.' FROM PUBLIC, '.$role);
            DB::unprepared('GRANT SELECT, INSERT ON TABLE public.billium_'.$table.' TO '.$role);
        }
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

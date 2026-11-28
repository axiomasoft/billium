<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

/**
 * Collection policies and cases: dunning policies, recurring owners, dunning cases, policy migrations and steps.
 */
return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.billium_collection_dunning_policy (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                policy_ref text NOT NULL,
                policy_version integer NOT NULL CHECK (policy_version >= 1),
                allowed_channels jsonb NOT NULL CHECK (jsonb_typeof(allowed_channels) = 'array' AND jsonb_array_length(allowed_channels) > 0),
                ordered_steps jsonb NOT NULL CHECK (jsonb_typeof(ordered_steps) = 'array' AND jsonb_array_length(ordered_steps) > 0),
                max_business_decline_attempts integer NOT NULL CHECK (max_business_decline_attempts >= 1),
                max_delivery_retries integer NOT NULL CHECK (max_delivery_retries >= 1),
                retryable_decline_categories jsonb NOT NULL CHECK (jsonb_typeof(retryable_decline_categories) = 'array'),
                recovery_horizon_seconds bigint NOT NULL CHECK (recovery_horizon_seconds > 0),
                grace_signal_after_seconds bigint NULL CHECK (
                    grace_signal_after_seconds IS NULL
                    OR (grace_signal_after_seconds >= 0 AND grace_signal_after_seconds <= recovery_horizon_seconds)
                ),
                read_only_signal_after_seconds bigint NULL CHECK (
                    read_only_signal_after_seconds IS NULL
                    OR (read_only_signal_after_seconds >= 0 AND read_only_signal_after_seconds <= recovery_horizon_seconds)
                ),
                min_outstanding_minor bigint NOT NULL CHECK (min_outstanding_minor >= 0),
                threshold_currency text NULL CHECK (threshold_currency IS NULL OR threshold_currency ~ '^[A-Z]{3}$'),
                supersedes_policy_id bigint NULL REFERENCES public.billium_collection_dunning_policy(id),
                policy_digest text NOT NULL,
                created_at timestamptz NOT NULL,
                UNIQUE (seller_ref, environment, policy_ref, policy_version),
                UNIQUE (id, seller_ref, environment, policy_ref, policy_version),
                CHECK (min_outstanding_minor = 0 OR threshold_currency IS NOT NULL),
                CHECK (
                    grace_signal_after_seconds IS NULL
                    OR read_only_signal_after_seconds IS NULL
                    OR grace_signal_after_seconds <= read_only_signal_after_seconds
                )
            );

            CREATE INDEX billium_collection_dunning_policy_lookup_idx
                ON public.billium_collection_dunning_policy (seller_ref, environment, policy_ref, policy_version DESC);

            CREATE TRIGGER billium_collection_dunning_policy_immutable
                BEFORE UPDATE OR DELETE ON public.billium_collection_dunning_policy
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE TABLE public.billium_collection_recurring_owner (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                agreement_ref text NOT NULL,
                component_ref text NOT NULL,
                owner_mode text NOT NULL CHECK (owner_mode IN ('billium_scheduler','provider_autonomous_subscription')),
                owner_ref text NOT NULL,
                owner_revision bigint NOT NULL CHECK (owner_revision >= 1),
                status text NOT NULL CHECK (status IN ('active','retired')),
                retired_reason text NULL,
                config_digest text NOT NULL,
                recorded_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                UNIQUE (seller_ref, environment, component_ref, owner_revision),
                CHECK (status = 'active' OR retired_reason IS NOT NULL),
                CHECK (status <> 'active' OR retired_reason IS NULL)
            );

            CREATE UNIQUE INDEX billium_collection_recurring_owner_active_uidx
                ON public.billium_collection_recurring_owner (seller_ref, environment, component_ref)
                WHERE status = 'active';

            CREATE INDEX billium_collection_recurring_owner_agreement_idx
                ON public.billium_collection_recurring_owner (seller_ref, environment, agreement_ref, component_ref);

            CREATE TABLE public.billium_collection_dunning_case (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                case_ref text NOT NULL,
                billing_account_ref text NOT NULL,
                obligation_ref text NOT NULL,
                component_ref text NULL,
                collection_generation integer NOT NULL CHECK (collection_generation >= 1),
                policy_id bigint NOT NULL,
                policy_ref text NOT NULL,
                policy_version integer NOT NULL CHECK (policy_version >= 1),
                currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
                initial_amount_minor bigint NOT NULL CHECK (initial_amount_minor > 0),
                current_open_minor bigint NOT NULL CHECK (current_open_minor >= 0),
                status text NOT NULL CHECK (status IN ('open','active','parked_unknown','paused','exhausted','settled','cancelled')),
                case_revision bigint NOT NULL CHECK (case_revision >= 1),
                cancellation_generation bigint NOT NULL CHECK (cancellation_generation >= 0),
                business_decline_attempts_used integer NOT NULL DEFAULT 0 CHECK (business_decline_attempts_used >= 0),
                max_business_decline_attempts integer NOT NULL CHECK (max_business_decline_attempts >= 1),
                active_step_no integer NULL CHECK (active_step_no IS NULL OR active_step_no >= 1),
                last_completed_step_no integer NOT NULL DEFAULT 0 CHECK (last_completed_step_no >= 0),
                next_attempt_at timestamptz NULL,
                recovery_deadline_at timestamptz NOT NULL,
                parked_reason text NULL,
                parked_step_ref text NULL,
                closed_reason text NULL,
                closed_at timestamptz NULL,
                opened_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                case_digest text NOT NULL,
                UNIQUE (seller_ref, environment, case_ref),
                UNIQUE (seller_ref, environment, obligation_ref, collection_generation),
                UNIQUE (id, seller_ref, environment),
                FOREIGN KEY (policy_id, seller_ref, environment, policy_ref, policy_version)
                    REFERENCES public.billium_collection_dunning_policy (id, seller_ref, environment, policy_ref, policy_version),
                CHECK (business_decline_attempts_used <= max_business_decline_attempts),
                CHECK (recovery_deadline_at > opened_at),
                CHECK (status <> 'parked_unknown' OR (parked_reason IS NOT NULL AND parked_step_ref IS NOT NULL)),
                CHECK (status = 'parked_unknown' OR (parked_reason IS NULL AND parked_step_ref IS NULL)),
                CHECK (status NOT IN ('exhausted','settled','cancelled') OR closed_at IS NOT NULL),
                CHECK (status IN ('exhausted','settled','cancelled') OR closed_at IS NULL)
            );

            CREATE INDEX billium_collection_dunning_case_due_idx
                ON public.billium_collection_dunning_case (seller_ref, status, next_attempt_at, id)
                WHERE next_attempt_at IS NOT NULL;

            CREATE INDEX billium_collection_dunning_case_account_idx
                ON public.billium_collection_dunning_case (seller_ref, environment, billing_account_ref, status);

            CREATE INDEX billium_collection_dunning_case_policy_idx
                ON public.billium_collection_dunning_case (seller_ref, environment, policy_ref, policy_version);

            CREATE TABLE public.billium_collection_case_policy_migration (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                migration_ref text NOT NULL,
                dunning_case_id bigint NOT NULL,
                case_ref text NOT NULL,
                from_policy_id bigint NOT NULL,
                from_policy_ref text NOT NULL,
                from_policy_version integer NOT NULL CHECK (from_policy_version >= 1),
                to_policy_id bigint NOT NULL,
                to_policy_ref text NOT NULL,
                to_policy_version integer NOT NULL CHECK (to_policy_version >= 1),
                from_case_revision bigint NOT NULL CHECK (from_case_revision >= 1),
                to_case_revision bigint NOT NULL CHECK (to_case_revision = from_case_revision + 1),
                reason text NOT NULL,
                actor_ref text NOT NULL,
                migration_digest text NOT NULL,
                migrated_at timestamptz NOT NULL,
                UNIQUE (seller_ref, environment, migration_ref),
                UNIQUE (dunning_case_id, from_case_revision),
                CHECK (from_policy_id <> to_policy_id),
                FOREIGN KEY (dunning_case_id, seller_ref, environment)
                    REFERENCES public.billium_collection_dunning_case (id, seller_ref, environment),
                FOREIGN KEY (from_policy_id, seller_ref, environment, from_policy_ref, from_policy_version)
                    REFERENCES public.billium_collection_dunning_policy (id, seller_ref, environment, policy_ref, policy_version),
                FOREIGN KEY (to_policy_id, seller_ref, environment, to_policy_ref, to_policy_version)
                    REFERENCES public.billium_collection_dunning_policy (id, seller_ref, environment, policy_ref, policy_version)
            );

            CREATE INDEX billium_collection_case_policy_migration_case_idx
                ON public.billium_collection_case_policy_migration (seller_ref, environment, dunning_case_id, to_case_revision);

            CREATE TRIGGER billium_collection_case_policy_migration_immutable
                BEFORE UPDATE OR DELETE ON public.billium_collection_case_policy_migration
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE TABLE public.billium_collection_step (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                dunning_case_id bigint NOT NULL,
                case_ref text NOT NULL,
                step_ref text NOT NULL,
                step_no integer NOT NULL CHECK (step_no >= 1),
                policy_id bigint NOT NULL,
                policy_ref text NOT NULL,
                policy_version integer NOT NULL CHECK (policy_version >= 1),
                channel text NOT NULL CHECK (channel IN ('saved_method','mandate','payment_link')),
                business_payment_intent_ref text NOT NULL,
                operation_ref text NOT NULL,
                step_authorization_ref text NULL,
                currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
                amount_minor bigint NOT NULL CHECK (amount_minor > 0),
                status text NOT NULL CHECK (status IN ('planned','authorized','dispatched','unknown','succeeded','failed_terminal','cancelled')),
                step_revision bigint NOT NULL CHECK (step_revision >= 1),
                delivery_attempts_used integer NOT NULL DEFAULT 0 CHECK (delivery_attempts_used >= 0),
                max_delivery_retries integer NOT NULL CHECK (max_delivery_retries >= 1),
                prior_step_id bigint NULL REFERENCES public.billium_collection_step(id),
                decline_category text NULL CHECK (
                    decline_category IS NULL
                    OR decline_category IN (
                        'insufficient_funds','temporary_network','issuer_unavailable','soft_decline',
                        'card_expired','do_not_honor','fraud_suspected','invalid_account','revoked_mandate','hard_decline'
                    )
                ),
                decline_reason text NULL,
                outcome_evidence_digest text NULL,
                scheduled_at timestamptz NOT NULL,
                last_delivery_attempt_at timestamptz NULL,
                terminal_at timestamptz NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                step_digest text NOT NULL,
                UNIQUE (seller_ref, environment, step_ref),
                UNIQUE (dunning_case_id, step_no),
                UNIQUE (seller_ref, environment, business_payment_intent_ref),
                UNIQUE (seller_ref, environment, operation_ref),
                FOREIGN KEY (dunning_case_id, seller_ref, environment)
                    REFERENCES public.billium_collection_dunning_case (id, seller_ref, environment),
                FOREIGN KEY (policy_id, seller_ref, environment, policy_ref, policy_version)
                    REFERENCES public.billium_collection_dunning_policy (id, seller_ref, environment, policy_ref, policy_version),
                CHECK (delivery_attempts_used <= max_delivery_retries),
                CHECK (status <> 'failed_terminal' OR (decline_category IS NOT NULL AND outcome_evidence_digest IS NOT NULL AND terminal_at IS NOT NULL)),
                CHECK (status NOT IN ('succeeded','failed_terminal','cancelled') OR terminal_at IS NOT NULL),
                CHECK (status IN ('succeeded','failed_terminal','cancelled') OR terminal_at IS NULL),
                CHECK (step_no = 1 OR prior_step_id IS NOT NULL),
                CHECK (step_no > 1 OR prior_step_id IS NULL)
            );

            CREATE INDEX billium_collection_step_case_idx
                ON public.billium_collection_step (seller_ref, environment, dunning_case_id, step_no);

            CREATE INDEX billium_collection_step_due_idx
                ON public.billium_collection_step (seller_ref, environment, status, scheduled_at);

            CREATE OR REPLACE FUNCTION public.billium_collection_recurring_owner_guard()
            RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium_collection_recurring_owner cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF (
                    NEW.seller_ref, NEW.environment, NEW.agreement_ref, NEW.component_ref,
                    NEW.owner_mode, NEW.owner_ref, NEW.config_digest, NEW.recorded_at
                ) IS DISTINCT FROM (
                    OLD.seller_ref, OLD.environment, OLD.agreement_ref, OLD.component_ref,
                    OLD.owner_mode, OLD.owner_ref, OLD.config_digest, OLD.recorded_at
                ) THEN
                    RAISE EXCEPTION 'billium_collection_recurring_owner identity and mode are immutable' USING ERRCODE = '23514';
                END IF;
                IF NEW.owner_revision <> OLD.owner_revision + 1 THEN
                    RAISE EXCEPTION 'billium_collection_recurring_owner revision must advance monotonically by 1' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'retired' THEN
                    RAISE EXCEPTION 'billium_collection_recurring_owner cannot transition out of retired state' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'active' AND NEW.status <> 'retired' THEN
                    RAISE EXCEPTION 'billium_collection_recurring_owner active row may only transition to retired' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_collection_recurring_owner_guard() FROM PUBLIC;

            CREATE TRIGGER billium_collection_recurring_owner_trg
                BEFORE UPDATE OR DELETE ON public.billium_collection_recurring_owner
                FOR EACH ROW EXECUTE FUNCTION public.billium_collection_recurring_owner_guard();

            CREATE OR REPLACE FUNCTION public.billium_collection_dunning_case_guard()
            RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium_collection_dunning_case cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF (
                    NEW.seller_ref, NEW.environment, NEW.case_ref, NEW.billing_account_ref,
                    NEW.obligation_ref, NEW.component_ref, NEW.collection_generation,
                    NEW.currency, NEW.initial_amount_minor, NEW.opened_at
                ) IS DISTINCT FROM (
                    OLD.seller_ref, OLD.environment, OLD.case_ref, OLD.billing_account_ref,
                    OLD.obligation_ref, OLD.component_ref, OLD.collection_generation,
                    OLD.currency, OLD.initial_amount_minor, OLD.opened_at
                ) THEN
                    RAISE EXCEPTION 'billium_collection_dunning_case identity is immutable' USING ERRCODE = '23514';
                END IF;
                IF OLD.status IN ('exhausted','settled','cancelled') THEN
                    RAISE EXCEPTION 'billium_collection_dunning_case cannot transition out of terminal % state', OLD.status USING ERRCODE = '23514';
                END IF;
                IF NEW.case_revision <> OLD.case_revision + 1 THEN
                    RAISE EXCEPTION 'billium_collection_dunning_case revision must advance monotonically by 1' USING ERRCODE = '23514';
                END IF;
                IF NEW.cancellation_generation < OLD.cancellation_generation THEN
                    RAISE EXCEPTION 'billium_collection_dunning_case cancellation_generation cannot regress' USING ERRCODE = '23514';
                END IF;
                IF NEW.business_decline_attempts_used < OLD.business_decline_attempts_used THEN
                    RAISE EXCEPTION 'billium_collection_dunning_case business_decline_attempts_used cannot regress' USING ERRCODE = '23514';
                END IF;
                IF NEW.last_completed_step_no < OLD.last_completed_step_no THEN
                    RAISE EXCEPTION 'billium_collection_dunning_case last_completed_step_no cannot regress' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'parked_unknown' AND NEW.status = 'parked_unknown' THEN
                    RAISE EXCEPTION 'billium_collection_dunning_case cannot mutate while remaining parked_unknown' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'parked_unknown'
                    AND (NEW.policy_id, NEW.policy_ref, NEW.policy_version) IS DISTINCT FROM (OLD.policy_id, OLD.policy_ref, OLD.policy_version) THEN
                    RAISE EXCEPTION 'billium_collection_dunning_case cannot migrate policy while parked_unknown' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_collection_dunning_case_guard() FROM PUBLIC;

            CREATE TRIGGER billium_collection_dunning_case_trg
                BEFORE UPDATE OR DELETE ON public.billium_collection_dunning_case
                FOR EACH ROW EXECUTE FUNCTION public.billium_collection_dunning_case_guard();

            CREATE OR REPLACE FUNCTION public.billium_collection_step_guard()
            RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                allowed boolean := false;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'billium_collection_step cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF (
                    NEW.seller_ref, NEW.environment, NEW.dunning_case_id, NEW.case_ref,
                    NEW.step_ref, NEW.step_no, NEW.policy_id, NEW.policy_ref, NEW.policy_version,
                    NEW.channel, NEW.business_payment_intent_ref, NEW.operation_ref,
                    NEW.currency, NEW.amount_minor, NEW.prior_step_id,
                    NEW.max_delivery_retries, NEW.scheduled_at, NEW.created_at, NEW.step_digest
                ) IS DISTINCT FROM (
                    OLD.seller_ref, OLD.environment, OLD.dunning_case_id, OLD.case_ref,
                    OLD.step_ref, OLD.step_no, OLD.policy_id, OLD.policy_ref, OLD.policy_version,
                    OLD.channel, OLD.business_payment_intent_ref, OLD.operation_ref,
                    OLD.currency, OLD.amount_minor, OLD.prior_step_id,
                    OLD.max_delivery_retries, OLD.scheduled_at, OLD.created_at, OLD.step_digest
                ) THEN
                    RAISE EXCEPTION 'billium_collection_step immutable fields cannot change' USING ERRCODE = '23514';
                END IF;
                IF OLD.step_authorization_ref IS NOT NULL AND NEW.step_authorization_ref IS DISTINCT FROM OLD.step_authorization_ref THEN
                    RAISE EXCEPTION 'billium_collection_step step_authorization_ref is write-once' USING ERRCODE = '23514';
                END IF;
                IF NEW.step_revision <> OLD.step_revision + 1 THEN
                    RAISE EXCEPTION 'billium_collection_step revision must advance monotonically by 1' USING ERRCODE = '23514';
                END IF;
                IF NEW.delivery_attempts_used < OLD.delivery_attempts_used THEN
                    RAISE EXCEPTION 'billium_collection_step delivery_attempts_used cannot regress' USING ERRCODE = '23514';
                END IF;
                IF OLD.status IN ('succeeded','failed_terminal','cancelled') THEN
                    RAISE EXCEPTION 'billium_collection_step cannot transition out of terminal % state', OLD.status USING ERRCODE = '23514';
                END IF;

                IF OLD.status = 'planned' AND NEW.status IN ('planned','authorized','dispatched','unknown','cancelled') THEN
                    allowed := true;
                ELSIF OLD.status = 'authorized' AND NEW.status IN ('authorized','dispatched','unknown','cancelled') THEN
                    allowed := true;
                ELSIF OLD.status = 'dispatched' AND NEW.status IN ('dispatched','unknown','succeeded','failed_terminal') THEN
                    allowed := true;
                ELSIF OLD.status = 'unknown' AND NEW.status IN ('succeeded','failed_terminal') THEN
                    allowed := true;
                END IF;

                IF NOT allowed THEN
                    RAISE EXCEPTION 'billium_collection_step invalid status transition % -> %', OLD.status, NEW.status USING ERRCODE = '23514';
                END IF;

                IF NEW.delivery_attempts_used > OLD.delivery_attempts_used
                    AND (OLD.status NOT IN ('planned','authorized','dispatched') OR NEW.status NOT IN ('planned','authorized','dispatched')) THEN
                    RAISE EXCEPTION 'billium_collection_step delivery retries are forbidden in % -> % transition', OLD.status, NEW.status USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_collection_step_guard() FROM PUBLIC;

            CREATE TRIGGER billium_collection_step_trg
                BEFORE UPDATE OR DELETE ON public.billium_collection_step
                FOR EACH ROW EXECUTE FUNCTION public.billium_collection_step_guard();
            SQL);

        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_collection_dunning_policy');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE', 'public.billium_collection_recurring_owner');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE', 'public.billium_collection_dunning_case');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_collection_case_policy_migration');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE', 'public.billium_collection_step');
    }

    public function down(): never
    {
        throw new LogicException('Billium migrations are forward-only.');
    }
};

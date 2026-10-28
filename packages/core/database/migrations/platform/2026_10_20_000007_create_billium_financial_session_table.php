<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // D54/D62: one adopted OwnerSession per epoch. No process token or credential is stored; ordinary roots hold the
        // current row FOR SHARE to commit, a seal takes it FOR UPDATE. Transitions are guarded here as a backstop.
        DB::unprepared(<<<'SQL'
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

            CREATE FUNCTION public.billium_financial_session_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
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
                    RAISE EXCEPTION 'billium: only the latest epoch may be activated (D54)' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_financial_session_guard() FROM PUBLIC;
            CREATE TRIGGER billium_financial_session_guard BEFORE UPDATE OR DELETE ON public.billium_financial_session
                FOR EACH ROW EXECUTE FUNCTION public.billium_financial_session_guard();
            SQL);
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_financial_session');
        RuntimeGrants::grant('UPDATE (state, activation_certificate_ref, sealed_at, updated_at)', 'public.billium_financial_session');
    }

    public function down(): void
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

<?php

declare(strict_types=1);

use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.billium_execution_authorization ADD COLUMN release_evidence_ref text;
            ALTER TABLE public.billium_execution_authorization ADD CONSTRAINT ex_authorization_release_basis
                CHECK (released_minor=0 OR release_evidence_ref IS NOT NULL OR expiry_evidence_ref IS NOT NULL) NOT VALID;
            ALTER TABLE public.billium_execution_authorization ADD CONSTRAINT ex_authorization_expiry_pair
                CHECK ((provider_expires_at IS NULL)=(expiry_evidence_ref IS NULL)) NOT VALID;
            ALTER TABLE public.billium_execution_authorization ADD CONSTRAINT ex_authorization_release_reference
                CHECK (release_evidence_ref IS NULL OR length(release_evidence_ref) BETWEEN 1 AND 200) NOT VALID;
            ALTER TABLE public.billium_execution_authorization DROP CONSTRAINT billium_execution_authorization_check1;
            DROP TRIGGER billium_execution_authorization_immutable ON public.billium_execution_authorization;
            CREATE TRIGGER billium_execution_authorization_immutable BEFORE UPDATE OR DELETE
                ON public.billium_execution_authorization FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable
                    ('authorized_minor','released_minor','provider_expires_at','expiry_evidence_ref','release_evidence_ref','state','revision');
            CREATE FUNCTION public.billium_execution_authorization_release_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
                IF NEW.released_minor=0 THEN RETURN NEW; END IF;
                IF TG_OP='UPDATE' AND NEW.release_evidence_ref IS NULL AND OLD.release_evidence_ref IS NULL
                    AND NEW.released_minor=OLD.released_minor
                    AND NEW.expiry_evidence_ref IS NOT DISTINCT FROM OLD.expiry_evidence_ref
                    AND NEW.provider_expires_at IS NOT DISTINCT FROM OLD.provider_expires_at THEN
                    RETURN NEW;
                END IF;
                IF NEW.release_evidence_ref IS NULL OR NOT EXISTS (
                    SELECT 1 FROM public.billium_execution_provider_fact f
                    JOIN public.billium_execution_operation o
                        ON (o.installation_ref,o.seller_ref,o.environment,o.operation_ref)
                            =(f.installation_ref,f.seller_ref,f.environment,f.operation_ref)
                    WHERE (f.installation_ref,f.seller_ref,f.environment)=(NEW.installation_ref,NEW.seller_ref,NEW.environment)
                        AND o.root_operation_ref=NEW.root_operation_ref
                        AND f.evidence_refs ? NEW.release_evidence_ref
                        AND f.normalized_snapshot->>'bindingDigest'=o.binding_digest
                        AND f.normalized_snapshot->>'currency'=NEW.currency
                        AND jsonb_typeof(f.normalized_snapshot->'operationAmount')='object'
                        AND f.normalized_snapshot->>'normalizedStatus' IN ('succeeded','failed','declined','no_effect')
                        AND (f.normalized_snapshot#>>'{authorizedTotal,minor}')::numeric=NEW.authorized_minor
                        AND (f.normalized_snapshot#>>'{voidedTotal,minor}')::numeric=NEW.released_minor
                ) THEN
                    RAISE EXCEPTION 'billium: authorization release requires its original accepted provider fact' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_authorization_release_guard() FROM PUBLIC;
            CREATE TRIGGER billium_execution_authorization_release_guard BEFORE INSERT OR UPDATE
                ON public.billium_execution_authorization FOR EACH ROW EXECUTE FUNCTION public.billium_execution_authorization_release_guard();
            ALTER TABLE public.billium_execution_admission_conflict ADD CONSTRAINT ex_execution_conflict_command_kind
                CHECK (command_kind IN ('payment.execute.v1','payment.authorize.v1','payment.capture.v1','payment.void_authorization.v1','payment.refund.v1')) NOT VALID;
            ALTER TABLE public.billium_execution_admission_conflict DROP CONSTRAINT billium_execution_admission_conflict_command_kind_check;
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

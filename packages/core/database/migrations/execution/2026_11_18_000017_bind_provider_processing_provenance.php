<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.billium_execution_provider_work ADD COLUMN operation_hint text;
            ALTER TABLE public.billium_execution_provider_work ADD CONSTRAINT ex_provider_work_hint_length
                CHECK (length(operation_hint) BETWEEN 1 AND 200) NOT VALID;

            CREATE TABLE public.billium_execution_provider_identity (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL,
                seller_ref text NOT NULL,
                environment text NOT NULL,
                attempt_ref text NOT NULL,
                provider_id text NOT NULL CHECK (length(provider_id) BETWEEN 1 AND 200),
                merchant_account_ref text NOT NULL CHECK (length(merchant_account_ref) BETWEEN 1 AND 200),
                provider_namespace text NOT NULL CHECK (provider_namespace <> ''),
                provider_operation_id text NOT NULL CHECK (provider_operation_id <> ''),
                evidence_ref text NOT NULL,
                UNIQUE (installation_ref,seller_ref,environment,id),
                UNIQUE (installation_ref,seller_ref,environment,attempt_ref),
                FOREIGN KEY (installation_ref,seller_ref,environment,attempt_ref) REFERENCES public.billium_execution_attempt(installation_ref,seller_ref,environment,attempt_ref),
                FOREIGN KEY (installation_ref,seller_ref,environment,evidence_ref) REFERENCES public.billium_execution_provider_evidence(installation_ref,seller_ref,environment,evidence_ref)
            );
            CREATE FUNCTION public.billium_execution_provider_identity_key(text,text,text,text,text) RETURNS bytea
                LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE SET search_path=pg_catalog AS $$
                SELECT sha256(convert_to(jsonb_build_array($1,$2,$3,$4,$5)::text,'UTF8'))
                $$;
            REVOKE ALL ON FUNCTION public.billium_execution_provider_identity_key(text,text,text,text,text) FROM PUBLIC;
            CREATE UNIQUE INDEX billium_execution_assigned_operation ON public.billium_execution_provider_identity
                (public.billium_execution_provider_identity_key(environment,provider_id,merchant_account_ref,provider_namespace,provider_operation_id));
            CREATE TRIGGER billium_execution_provider_identity_immutable BEFORE UPDATE OR DELETE
                ON public.billium_execution_provider_identity FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            CREATE TABLE public.billium_execution_certified_absence (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL,
                seller_ref text NOT NULL,
                environment text NOT NULL,
                attempt_ref text NOT NULL,
                evidence_ref text NOT NULL,
                certificate_ref text NOT NULL CHECK (length(certificate_ref) BETWEEN 1 AND 200),
                certificate_digest text NOT NULL,
                UNIQUE (installation_ref,seller_ref,environment,id),
                UNIQUE (installation_ref,seller_ref,environment,attempt_ref),
                FOREIGN KEY (installation_ref,seller_ref,environment,attempt_ref) REFERENCES public.billium_execution_attempt(installation_ref,seller_ref,environment,attempt_ref),
                FOREIGN KEY (installation_ref,seller_ref,environment,evidence_ref) REFERENCES public.billium_execution_provider_evidence(installation_ref,seller_ref,environment,evidence_ref)
            );
            CREATE TRIGGER billium_execution_certified_absence_immutable BEFORE UPDATE OR DELETE
                ON public.billium_execution_certified_absence FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            CREATE OR REPLACE FUNCTION public.billium_execution_reservation_proof_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
                IF NEW.state='released' OR (NEW.state='consumed' AND NEW.consumed_minor<NEW.requested_minor) THEN
                    IF NEW.state='released' AND NEW.consumed_minor<>0 THEN
                        RAISE EXCEPTION 'billium: released reservation has a positive effect' USING ERRCODE='23514';
                    END IF;
                    PERFORM 1 FROM public.billium_execution_operation o
                    JOIN public.billium_execution_attempt a USING (installation_ref,seller_ref,environment,operation_ref)
                    JOIN public.billium_execution_provider_evidence e USING (installation_ref,seller_ref,environment,intent_owner_namespace)
                    WHERE o.installation_ref=NEW.installation_ref AND o.seller_ref=NEW.seller_ref AND o.environment=NEW.environment
                        AND o.operation_ref=NEW.operation_ref AND o.kind=NEW.kind AND o.root_operation_ref=NEW.root_operation_ref
                        AND o.requested_minor=NEW.requested_minor AND o.currency=NEW.currency AND e.evidence_ref=NEW.release_evidence_ref
                        AND e.provider_id=a.provider_id AND e.merchant_account_ref=a.merchant_account_ref
                        AND (
                            (NEW.state='released' AND EXISTS (SELECT 1 FROM public.billium_execution_certified_absence c
                                WHERE c.installation_ref=a.installation_ref AND c.seller_ref=a.seller_ref AND c.environment=a.environment
                                    AND c.attempt_ref=a.attempt_ref AND c.evidence_ref=e.evidence_ref))
                            OR EXISTS (SELECT 1 FROM public.billium_execution_provider_fact f
                                WHERE f.installation_ref=o.installation_ref AND f.seller_ref=o.seller_ref AND f.environment=o.environment
                                    AND f.operation_ref=o.operation_ref AND f.evidence_refs ? e.evidence_ref
                                    AND f.normalized_snapshot->>'providerReference'=a.provider_reference
                                    AND f.normalized_snapshot->>'merchantAccountRef'=a.merchant_account_ref
                                    AND f.normalized_snapshot->>'bindingDigest'=o.binding_digest
                                    AND f.normalized_snapshot->>'kind'=o.kind AND f.normalized_snapshot->>'currency'=o.currency
                                    AND f.normalized_snapshot#>>'{operationAmount,currency,code}'=o.currency
                                    AND f.normalized_snapshot#>>'{operationAmount,minor}'=NEW.consumed_minor::text
                                    AND ((NEW.state='released' AND f.normalized_snapshot->>'normalizedStatus' IN ('declined','failed','no_effect'))
                                      OR (NEW.state='consumed' AND f.normalized_snapshot->>'normalizedStatus'='succeeded'))));
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'billium: reservation requires original accepted terminal proof' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            SQL);
        RuntimeGrants::grant('EXECUTE', 'FUNCTION public.billium_execution_provider_identity_key(text,text,text,text,text)');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_provider_identity');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_certified_absence');
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

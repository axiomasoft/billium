<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

/**
 * Execution provider provenance: provider identities, certified absences and child identities.
 */
return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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
                UNIQUE (installation_ref,seller_ref,environment,attempt_ref),
                FOREIGN KEY (installation_ref,seller_ref,environment,attempt_ref) REFERENCES public.billium_execution_attempt(installation_ref,seller_ref,environment,attempt_ref),
                FOREIGN KEY (installation_ref,seller_ref,environment,evidence_ref) REFERENCES public.billium_execution_provider_evidence(installation_ref,seller_ref,environment,evidence_ref)
            );

            CREATE FUNCTION public.billium_execution_provider_identity_key(text,text,text,text,text) RETURNS bytea
                LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE SET search_path=pg_catalog AS $$
                SELECT sha256(convert_to(jsonb_build_array($1,$2,$3,$4,$5)::text,'UTF8'))
                $$;
            REVOKE ALL ON FUNCTION public.billium_execution_provider_identity_key(text, text, text, text, text) FROM PUBLIC;

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
                UNIQUE (installation_ref,seller_ref,environment,attempt_ref),
                FOREIGN KEY (installation_ref,seller_ref,environment,attempt_ref) REFERENCES public.billium_execution_attempt(installation_ref,seller_ref,environment,attempt_ref),
                FOREIGN KEY (installation_ref,seller_ref,environment,evidence_ref) REFERENCES public.billium_execution_provider_evidence(installation_ref,seller_ref,environment,evidence_ref)
            );

            CREATE TRIGGER billium_execution_certified_absence_immutable BEFORE UPDATE OR DELETE
                ON public.billium_execution_certified_absence FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            CREATE FUNCTION public.billium_execution_accept_provider_fact(f jsonb, i jsonb) RETURNS boolean
            LANGUAGE plpgsql SECURITY INVOKER SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
                INSERT INTO public.billium_execution_provider_fact
                    (installation_ref,seller_ref,environment,fact_ref,operation_ref,provider_identity_scope,fact_identity,evidence_mode,material_digest,normalized_snapshot,evidence_refs)
                    VALUES (f->>'installation_ref',f->>'seller_ref',f->>'environment',f->>'fact_ref',f->>'operation_ref',f->>'provider_identity_scope',
                        f->>'fact_identity',f->>'evidence_mode',f->>'material_digest',f->'normalized_snapshot',f->'evidence_refs') ON CONFLICT DO NOTHING;
                IF NOT FOUND THEN RETURN false; END IF;
                IF i IS NOT NULL THEN
                    INSERT INTO public.billium_execution_provider_identity
                        (installation_ref,seller_ref,environment,attempt_ref,provider_id,merchant_account_ref,provider_namespace,provider_operation_id,evidence_ref)
                        VALUES (f->>'installation_ref',f->>'seller_ref',f->>'environment',i->>'attempt_ref',i->>'provider_id',i->>'merchant_account_ref',
                            i->>'provider_namespace',i->>'provider_operation_id',i->>'evidence_ref') ON CONFLICT DO NOTHING;
                    IF NOT FOUND AND NOT EXISTS (SELECT 1 FROM public.billium_execution_provider_identity
                        WHERE installation_ref=f->>'installation_ref' AND seller_ref=f->>'seller_ref' AND environment=f->>'environment'
                            AND attempt_ref=i->>'attempt_ref' AND provider_id=i->>'provider_id' AND merchant_account_ref=i->>'merchant_account_ref'
                            AND provider_namespace=i->>'provider_namespace' AND provider_operation_id=i->>'provider_operation_id') THEN
                        RAISE EXCEPTION 'billium: conflicting assigned provider operation' USING ERRCODE='BX001';
                    END IF;
                END IF;
                RETURN true;
            EXCEPTION WHEN SQLSTATE 'BX001' THEN
                -- Roll back both inserts from this statement while preserving the raw delivery and its quarantine transaction.
                RETURN false;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_accept_provider_fact(f jsonb, i jsonb) FROM PUBLIC;

            CREATE FUNCTION public.billium_execution_reservation_actual_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
                IF NEW.state='released' OR (NEW.state='consumed' AND NEW.consumed_minor<NEW.requested_minor) THEN
                    IF EXISTS (SELECT 1 FROM public.billium_execution_provider_fact f
                        WHERE f.installation_ref=NEW.installation_ref AND f.seller_ref=NEW.seller_ref AND f.environment=NEW.environment
                            AND f.operation_ref=NEW.operation_ref AND f.normalized_snapshot->>'bindingDigest' IS NOT NULL
                            AND (f.normalized_snapshot#>>'{operationAmount,minor}')::bigint > NEW.consumed_minor) THEN
                        RAISE EXCEPTION 'billium: reservation release contradicts original observed amount' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_reservation_actual_guard() FROM PUBLIC;

            CREATE TRIGGER billium_execution_reservation_actual_guard BEFORE UPDATE ON public.billium_execution_reservation
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_reservation_actual_guard();

            CREATE TABLE public.billium_execution_child_identity (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK (length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK (length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK (length(environment) BETWEEN 1 AND 200),
                intent_owner_namespace text NOT NULL CHECK (length(intent_owner_namespace) BETWEEN 1 AND 200),
                source_context text NOT NULL CHECK (source_context <> ''),
                business_intent_ref text NOT NULL CHECK (length(business_intent_ref) BETWEEN 1 AND 200),
                operation_kind text NOT NULL CHECK (operation_kind IN ('capture','void_authorization','refund')),
                business_identity_digest text GENERATED ALWAYS AS
                    (public.billium_execution_business_identity_digest(intent_owner_namespace,environment,source_context,business_intent_ref,operation_kind)) STORED,
                operation_ref text NOT NULL CHECK (length(operation_ref) BETWEEN 1 AND 200),
                root_operation_ref text NOT NULL CHECK (length(root_operation_ref) BETWEEN 1 AND 200),
                semantic_digest text NOT NULL CHECK (semantic_digest ~ '^sha256:[a-f0-9]{64}$'),
                canonicalization_version text NOT NULL CHECK (canonicalization_version <> ''),
                binding jsonb NOT NULL CHECK (jsonb_typeof(binding)='object'),
                requested_minor bigint NOT NULL CHECK (requested_minor > 0),
                currency text NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
                currency_exponent integer NOT NULL CHECK (currency_exponent BETWEEN 0 AND 9),
                reason_code text CHECK (length(reason_code) BETWEEN 1 AND 200),
                category text CHECK (length(category) BETWEEN 1 AND 200),
                receipt_ref text NOT NULL CHECK (length(receipt_ref) BETWEEN 1 AND 200),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                retention_policy text NOT NULL DEFAULT 'execution-business-identity-indefinite-v1'
                    CHECK (retention_policy='execution-business-identity-indefinite-v1'),
                retention_deadline_at timestamptz CHECK (retention_deadline_at IS NULL),
                CHECK (operation_ref<>root_operation_ref),
                CHECK ((operation_kind='refund')=(category IS NOT NULL)),
                CHECK ((operation_kind<>'capture')=(reason_code IS NOT NULL)),
                CHECK ((binding#>>'{scope,installationRef}',binding#>>'{scope,sellerRef}',binding#>>'{scope,environment}',
                        binding#>>'{scope,intentOwnerNamespace}',binding->>'sourceContext',binding->>'rootOperationRef',binding->>'canonicalizationVersion')
                    IS NOT DISTINCT FROM (installation_ref,seller_ref,environment,intent_owner_namespace,source_context,root_operation_ref,canonicalization_version)
                    AND (binding->>'executionProfile' IN ('remote-billium','standalone-billium')) IS TRUE),
                UNIQUE (business_identity_digest),
                UNIQUE (installation_ref,seller_ref,environment,operation_ref),
                FOREIGN KEY (installation_ref,seller_ref,environment,operation_ref)
                    REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref)
                    DEFERRABLE INITIALLY DEFERRED,
                FOREIGN KEY (installation_ref,seller_ref,environment,root_operation_ref)
                    REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref)
            );

            CREATE TRIGGER billium_execution_child_identity_immutable BEFORE UPDATE OR DELETE
                ON public.billium_execution_child_identity FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();

            CREATE INDEX billium_execution_child_identity_root
                ON public.billium_execution_child_identity(installation_ref,seller_ref,environment,root_operation_ref,operation_kind,id);

            CREATE FUNCTION public.billium_execution_child_operation_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            DECLARE c public.billium_execution_child_identity;
            BEGIN
                IF NEW.operation_ref<>NEW.root_operation_ref THEN
                    SELECT * INTO c FROM public.billium_execution_child_identity
                        WHERE installation_ref=NEW.installation_ref AND seller_ref=NEW.seller_ref
                            AND environment=NEW.environment AND operation_ref=NEW.operation_ref;
                    IF c.id IS NULL OR (c.root_operation_ref,c.operation_kind,c.binding,c.semantic_digest,
                            c.requested_minor,c.currency,c.currency_exponent,c.source_context,c.intent_owner_namespace)
                        IS DISTINCT FROM (NEW.root_operation_ref,NEW.kind,NEW.binding,NEW.economic_digest,
                            NEW.requested_minor,NEW.currency,NEW.currency_exponent,NEW.source_context,NEW.intent_owner_namespace) THEN
                        RAISE EXCEPTION 'billium: child requires its original immutable business identity' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_child_operation_guard() FROM PUBLIC;

            CREATE CONSTRAINT TRIGGER billium_execution_child_operation_guard
                AFTER INSERT ON public.billium_execution_operation DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_child_operation_guard();

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
            SQL);

        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_provider_identity');
        RuntimeGrants::grant('EXECUTE', 'FUNCTION public.billium_execution_provider_identity_key(text, text, text, text, text)');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_certified_absence');
        RuntimeGrants::grant('EXECUTE', 'FUNCTION public.billium_execution_accept_provider_fact(f jsonb, i jsonb)');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_child_identity');
    }

    public function down(): never
    {
        throw new LogicException('Billium migrations are forward-only.');
    }
};

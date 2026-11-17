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
            SQL);
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_child_identity');
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

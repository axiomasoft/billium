<?php

declare(strict_types=1);

use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Version zero did not retain the independent wire source.context. Never invent a context for history.
            LOCK TABLE public.billium_execution_business_binding, public.billium_execution_operation IN ACCESS EXCLUSIVE MODE;
            DO $$
            BEGIN
                IF EXISTS (SELECT FROM public.billium_execution_business_binding) OR EXISTS (SELECT FROM public.billium_execution_operation) THEN
                    RAISE EXCEPTION 'billium: original source context requires verified historical reconciliation before upgrade' USING ERRCODE='55000';
                END IF;
            END $$;

            ALTER TABLE public.billium_execution_business_binding ADD COLUMN source_context text;
            ALTER TABLE public.billium_execution_operation ADD COLUMN source_context text;
            ALTER TABLE public.billium_execution_business_binding ADD CONSTRAINT ex_business_source_context CHECK (
                source_context IS NOT NULL AND source_context <> '' AND
                (jsonb_typeof(binding->'sourceContext'), binding->>'sourceContext', binding->>'businessIntentRef',
                 binding->>'rootOperationRef', binding->>'semanticDigest', binding->>'canonicalizationVersion',
                 binding#>>'{scope,installationRef}', binding#>>'{scope,sellerRef}', binding#>>'{scope,environment}',
                 binding#>>'{scope,intentOwnerNamespace}') IS NOT DISTINCT FROM
                ('string', source_context, business_intent_ref, operation_ref, semantic_digest, canonicalization_version,
                 installation_ref, seller_ref, environment, intent_owner_namespace)
                AND (binding->>'executionProfile' IN ('remote-billium','standalone-billium')) IS TRUE
            ) NOT VALID;
            ALTER TABLE public.billium_execution_operation ADD CONSTRAINT ex_operation_source_context CHECK (
                source_context IS NOT NULL AND source_context <> '' AND
                (jsonb_typeof(binding->'sourceContext'), binding->>'sourceContext', binding->>'businessIntentRef') IS NOT DISTINCT FROM
                ('string', source_context, business_intent_ref)
                AND (binding->>'executionProfile' IN ('remote-billium','standalone-billium')) IS TRUE
            ) NOT VALID;

            CREATE UNIQUE INDEX billium_execution_context_business_identity ON public.billium_execution_business_binding
                (intent_owner_namespace,environment,source_context,business_intent_ref,operation_kind);
            DROP INDEX public.billium_execution_business_identity;

            CREATE OR REPLACE FUNCTION public.billium_execution_root_identity_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            DECLARE b public.billium_execution_business_binding;
            BEGIN
                IF NEW.operation_ref=NEW.root_operation_ref THEN
                    SELECT * INTO b FROM public.billium_execution_business_binding
                        WHERE intent_owner_namespace=NEW.intent_owner_namespace AND environment=NEW.environment
                        AND source_context=NEW.source_context AND business_intent_ref=NEW.business_intent_ref AND operation_kind='charge';
                    IF b.id IS NULL OR b.tombstone_reason IS NOT NULL OR
                        (b.installation_ref,b.seller_ref,b.operation_ref,b.semantic_digest,b.binding) IS DISTINCT FROM
                        (NEW.installation_ref,NEW.seller_ref,NEW.operation_ref,NEW.economic_digest,NEW.binding) THEN
                        RAISE EXCEPTION 'billium: root operation requires original durable business identity' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

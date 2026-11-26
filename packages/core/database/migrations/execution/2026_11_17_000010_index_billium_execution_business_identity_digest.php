<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- RFC 8785 for this closed schema of string fields: constant UTF-16 key order and JSON string escaping.
            CREATE FUNCTION public.billium_execution_business_identity_digest(text,text,text,text,text) RETURNS text
            LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE SET search_path=pg_catalog,pg_temp AS $$
                SELECT 'sha256:' || encode(sha256(convert_to(
                    '{"businessIntentRef":' || to_json($4)::text ||
                    ',"environment":' || to_json($2)::text ||
                    ',"intentOwnerNamespace":' || to_json($1)::text ||
                    ',"operationKind":' || to_json($5)::text ||
                    ',"schema":"billium.execution.business-identity.v1","sourceContext":' || to_json($3)::text || '}',
                    'UTF8')), 'hex')
            $$;
            REVOKE ALL ON FUNCTION public.billium_execution_business_identity_digest(text,text,text,text,text) FROM PUBLIC;

            ALTER TABLE public.billium_execution_business_binding ADD COLUMN business_identity_digest text
                GENERATED ALWAYS AS (public.billium_execution_business_identity_digest(intent_owner_namespace,environment,source_context,business_intent_ref,operation_kind)) STORED;
            CREATE UNIQUE INDEX billium_execution_business_identity_digest_unique ON public.billium_execution_business_binding (business_identity_digest);
            DROP INDEX public.billium_execution_context_business_identity;

            CREATE OR REPLACE FUNCTION public.billium_execution_root_identity_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            DECLARE b public.billium_execution_business_binding;
            BEGIN
                IF NEW.operation_ref=NEW.root_operation_ref THEN
                    SELECT * INTO b FROM public.billium_execution_business_binding
                        WHERE business_identity_digest=public.billium_execution_business_identity_digest(NEW.intent_owner_namespace, NEW.environment, NEW.source_context, NEW.business_intent_ref, 'charge')
                        AND intent_owner_namespace=NEW.intent_owner_namespace AND environment=NEW.environment
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
        RuntimeGrants::grant('EXECUTE', 'FUNCTION public.billium_execution_business_identity_digest(text,text,text,text,text)');
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

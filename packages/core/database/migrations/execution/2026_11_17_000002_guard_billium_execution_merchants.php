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
            ALTER TABLE public.billium_execution_merchant_revision ADD COLUMN continuity_evidence_ref text;
            ALTER TABLE public.billium_execution_merchant_revision ADD CONSTRAINT ex_merchant_continuity_required CHECK (continuity_evidence_ref IS NOT NULL AND length(continuity_evidence_ref) BETWEEN 1 AND 200) NOT VALID;

            CREATE FUNCTION public.billium_execution_merchant_policy_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            DECLARE m public.billium_execution_merchant_revision; conflict_ref text;
            BEGIN
                SELECT * INTO m FROM public.billium_execution_merchant_revision
                WHERE installation_ref=NEW.installation_ref AND seller_ref=NEW.seller_ref
                    AND environment=NEW.environment AND revision_ref=NEW.revision_ref;
                IF m.id IS NULL THEN RAISE EXCEPTION 'billium: merchant revision absent' USING ERRCODE='23503'; END IF;
                IF TG_TABLE_NAME='billium_execution_merchant_credential_revision' THEN
                    IF NEW.merchant_account_ref<>m.merchant_account_ref OR NEW.identity_continuity_evidence_ref=''
                    THEN RAISE EXCEPTION 'billium: credential account continuity mismatch' USING ERRCODE='23514'; END IF;
                    IF NEW.predecessor_ref IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM public.billium_execution_merchant_credential_revision p
                        WHERE p.installation_ref=NEW.installation_ref AND p.seller_ref=NEW.seller_ref AND p.environment=NEW.environment
                            AND p.revision_ref=NEW.revision_ref AND p.purpose=NEW.purpose
                            AND p.credential_revision_ref=NEW.predecessor_ref AND p.merchant_account_ref=NEW.merchant_account_ref)
                    THEN RAISE EXCEPTION 'billium: credential predecessor substitution' USING ERRCODE='23514'; END IF;
                ELSE
                    IF (NEW.purpose='new') <> (NEW.action IN ('enable_new','stop_new'))
                       OR (NEW.action='enable_recovery' AND (NEW.retention_until IS NULL OR NEW.retention_until<=NEW.effective_at))
                    THEN RAISE EXCEPTION 'billium: lifecycle action/purpose/horizon mismatch' USING ERRCODE='23514'; END IF;
                    -- The transaction lock serializes competing active revisions, including raw SQL writers.
                    PERFORM pg_advisory_xact_lock(hashtextextended(NEW.installation_ref||'/'||NEW.seller_ref||'/'||NEW.environment||'/'||m.webhook_route_ref,7419));
                    IF NEW.action='enable_new' THEN
                        SELECT r.revision_ref INTO conflict_ref FROM public.billium_execution_merchant_revision r
                        JOIN LATERAL (SELECT e.action FROM public.billium_execution_merchant_lifecycle_event e
                            WHERE e.installation_ref=r.installation_ref AND e.seller_ref=r.seller_ref AND e.environment=r.environment
                                AND e.revision_ref=r.revision_ref AND e.purpose='new' AND e.effective_at<=NEW.effective_at
                            ORDER BY e.effective_at DESC,e.id DESC LIMIT 1) latest ON true
                        WHERE r.installation_ref=m.installation_ref AND r.seller_ref=m.seller_ref AND r.environment=m.environment
                            AND r.webhook_route_ref=m.webhook_route_ref AND r.revision_ref<>m.revision_ref
                            AND r.valid_from<=NEW.effective_at AND (r.valid_until IS NULL OR r.valid_until>NEW.effective_at)
                            AND latest.action='enable_new' LIMIT 1;
                        IF conflict_ref IS NOT NULL THEN RAISE EXCEPTION 'billium: competing active merchant revision' USING ERRCODE='23514'; END IF;
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_merchant_policy_guard() FROM PUBLIC;
            CREATE TRIGGER billium_execution_merchant_policy_guard BEFORE INSERT ON public.billium_execution_merchant_lifecycle_event
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_merchant_policy_guard();
            CREATE TRIGGER billium_execution_credential_policy_guard BEFORE INSERT ON public.billium_execution_merchant_credential_revision
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_merchant_policy_guard();

            ALTER TABLE public.billium_execution_provider_evidence ADD CONSTRAINT ex_evidence_secure_custody
                CHECK (secure_payload_ref IS NOT NULL AND length(secure_payload_ref) BETWEEN 1 AND 200 AND raw_payload IS NULL
                    AND octet_length(sanitized_payload::text)<=65536
                    AND sanitized_payload::text !~* '"(pan|cvc|cvv|password|secret|accessToken|refreshToken|providerToken)"[[:space:]]*:') NOT VALID;

            CREATE FUNCTION public.billium_execution_operation_binding_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            DECLARE r public.billium_execution_operation;
            BEGIN
                IF (NEW.binding->>'merchantAccountRef',NEW.binding->>'merchantRevisionRef',NEW.binding->>'driverId',
                    NEW.binding->>'driverContractVersion',NEW.binding->>'capabilityDigest',NEW.binding->>'rootOperationRef',
                    NEW.binding->'scope'->>'installationRef',NEW.binding->'scope'->>'sellerRef',NEW.binding->'scope'->>'environment',
                    NEW.binding->'scope'->>'intentOwnerNamespace') IS DISTINCT FROM
                    (NEW.merchant_account_ref,NEW.merchant_revision_ref,NEW.driver_id,NEW.driver_contract_version,NEW.capability_digest,
                    NEW.root_operation_ref,NEW.installation_ref,NEW.seller_ref,NEW.environment,NEW.intent_owner_namespace)
                THEN RAISE EXCEPTION 'billium: binding relational identity mismatch' USING ERRCODE='23514'; END IF;
                IF NEW.root_operation_ref<>NEW.operation_ref THEN
                    SELECT * INTO r FROM public.billium_execution_operation WHERE installation_ref=NEW.installation_ref
                        AND seller_ref=NEW.seller_ref AND environment=NEW.environment AND operation_ref=NEW.root_operation_ref;
                    IF r.id IS NULL OR r.operation_ref<>r.root_operation_ref OR NEW.binding<>r.binding OR NEW.currency<>r.currency
                    THEN RAISE EXCEPTION 'billium: child historical binding mismatch' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_operation_binding_guard() FROM PUBLIC;
            CREATE TRIGGER billium_execution_operation_binding_guard BEFORE INSERT ON public.billium_execution_operation
                FOR EACH ROW EXECUTE FUNCTION public.billium_execution_operation_binding_guard();
            SQL);
        RuntimeGrants::grant('SELECT, INSERT, UPDATE (state, history_from, completeness_receipt_ref, generation)', 'public.billium_execution_namespace');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_merchant_revision');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_capability_snapshot');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_business_binding');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE (state, revision, economic_digest, authorization_ref, next_reconcile_at, last_reconciled_at, reconcile_attempts, reconcile_state)', 'public.billium_execution_operation');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_operation_revision');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE (authorized_limit_minor, confirmed_capture_minor, confirmed_refund_minor, released_authorization_minor, held_capture_minor, held_void_minor, held_refund_minor, financial_hold, version)', 'public.billium_execution_capacity');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE (state, consumed_minor, release_evidence_ref, version)', 'public.billium_execution_reservation');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE (state, lease_owner, lease_fence, lease_expires_at, session_epoch, owner_session_ref, dispatch_ticket_ref, started_at, request_deadline_at, finish_evidence_ref)', 'public.billium_execution_attempt');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_provider_fact');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_discrepancy');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE (last_sequence)', 'public.billium_execution_observation_partition');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE (command_row_id)', 'public.billium_execution_observation_emission');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE (authorized_minor, released_minor, provider_expires_at, expiry_evidence_ref, state, revision)', 'public.billium_execution_authorization');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE (inventory_cursor, completeness_watermark, inventory_digest, manifest_digest, state, unresolved, completed_at)', 'public.billium_execution_reconciliation_run');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_repair_receipt');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE (state, due_at, lease_owner, lease_fence, lease_expires_at, attempt_count, last_error_code, last_error_at)', 'public.billium_execution_dispatch_work');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_provider_evidence');
        RuntimeGrants::grant('SELECT, INSERT, UPDATE (state, due_at, lease_owner, lease_fence, lease_expires_at, attempt_count, last_error_code, last_error_at, result_ref)', 'public.billium_execution_provider_work');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_merchant_lifecycle_event');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_execution_merchant_credential_revision');
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

<?php

declare(strict_types=1);

use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.billium_execution_immutable() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            BEGIN
             IF TG_OP='DELETE' OR (to_jsonb(NEW)-TG_ARGV) IS DISTINCT FROM (to_jsonb(OLD)-TG_ARGV) THEN
              RAISE EXCEPTION 'billium: execution immutable identity/history' USING ERRCODE='55000';
             END IF;
             IF TG_TABLE_NAME='billium_execution_observation_emission' AND OLD.command_row_id IS NOT NULL THEN
              RAISE EXCEPTION 'billium: emission is complete' USING ERRCODE='55000';
             END IF;
             RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_immutable() FROM PUBLIC;
            
            CREATE TABLE public.billium_execution_namespace (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                intent_owner_namespace text NOT NULL,
                state text NOT NULL,
                canonicalization_version text NOT NULL,
                history_from timestamptz,
                completeness_receipt_ref text,
                generation bigint NOT NULL DEFAULT 1,
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,intent_owner_namespace),
                CHECK(state IN ('open','closed','recovery_required')),
                CHECK(generation>=1),
                CHECK(state<>'open' OR completeness_receipt_ref IS NOT NULL),
                CHECK(length(intent_owner_namespace) BETWEEN 1 AND 200),
                CHECK(length(state) BETWEEN 1 AND 200),
                CHECK(length(canonicalization_version) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_namespace_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_namespace FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('state','history_from','completeness_receipt_ref','generation');
            
            CREATE TABLE public.billium_execution_merchant_revision (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                revision_ref text NOT NULL,
                merchant_account_ref text NOT NULL,
                provider_id text NOT NULL,
                driver_id text NOT NULL,
                driver_contract_version text NOT NULL,
                capability_snapshot_ref text NOT NULL,
                capability_digest text NOT NULL,
                credential_ref text NOT NULL,
                credential_version_ref text NOT NULL,
                currency_codes jsonb NOT NULL,
                digest text NOT NULL,
                created_at timestamptz NOT NULL,
                valid_from timestamptz NOT NULL,
                valid_until timestamptz,
                predecessor_ref text,
                api_profile_version text NOT NULL,
                webhook_route_ref text NOT NULL,
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,revision_ref),
                UNIQUE(installation_ref,seller_ref,environment,revision_ref,merchant_account_ref,driver_id,driver_contract_version,capability_digest),
                CHECK(credential_ref ~ '^secret://[A-Za-z0-9][A-Za-z0-9/._:-]*$'),
                CHECK(valid_until IS NULL OR valid_until>valid_from),
                CHECK(jsonb_typeof(currency_codes)='array'),
                CHECK(length(revision_ref) BETWEEN 1 AND 200),
                CHECK(length(merchant_account_ref) BETWEEN 1 AND 200),
                CHECK(length(provider_id) BETWEEN 1 AND 200),
                CHECK(length(driver_id) BETWEEN 1 AND 200),
                CHECK(length(driver_contract_version) BETWEEN 1 AND 200),
                CHECK(length(capability_snapshot_ref) BETWEEN 1 AND 200),
                CHECK(length(capability_digest) BETWEEN 1 AND 200),
                CHECK(length(credential_ref) BETWEEN 1 AND 200),
                CHECK(length(credential_version_ref) BETWEEN 1 AND 200),
                CHECK(length(digest) BETWEEN 1 AND 200),
                CHECK(length(api_profile_version) BETWEEN 1 AND 200),
                CHECK(length(webhook_route_ref) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_merchant_revision_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_merchant_revision FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();
            
            CREATE TABLE public.billium_execution_capability_snapshot (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                snapshot_ref text NOT NULL,
                merchant_account_ref text NOT NULL,
                driver_id text NOT NULL,
                driver_contract_version text NOT NULL,
                capability_version text NOT NULL,
                body jsonb NOT NULL,
                digest text NOT NULL,
                qualification_mode text NOT NULL,
                qualification_evidence_ref text NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                supported_effects text[] NOT NULL,
                currencies text[] NOT NULL,
                capture_mode text NOT NULL,
                partial_refund boolean NOT NULL,
                multiple_refund boolean NOT NULL,
                lookup_by_reference boolean NOT NULL,
                lookup_by_provider_id boolean NOT NULL,
                inventory_complete boolean NOT NULL,
                absence_certification boolean NOT NULL,
                idempotency_retention_seconds bigint,
                lookup_retention_seconds bigint,
                evidence_mode text NOT NULL,
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,snapshot_ref),
                UNIQUE(installation_ref,seller_ref,environment,snapshot_ref,merchant_account_ref,driver_id,driver_contract_version,digest),
                CHECK(qualification_mode IN ('simulated','provider_verified')),
                CHECK(capture_mode IN ('none','full','partial_single','partial_multiple')),
                CHECK(cardinality(supported_effects)>0 AND cardinality(currencies)>0),
                CHECK(NOT absence_certification OR (inventory_complete AND lookup_by_reference)),
                CHECK(idempotency_retention_seconds IS NULL OR idempotency_retention_seconds>0),
                CHECK(lookup_retention_seconds IS NULL OR lookup_retention_seconds>0),
                CHECK(length(snapshot_ref) BETWEEN 1 AND 200),
                CHECK(length(merchant_account_ref) BETWEEN 1 AND 200),
                CHECK(length(driver_id) BETWEEN 1 AND 200),
                CHECK(length(driver_contract_version) BETWEEN 1 AND 200),
                CHECK(length(capability_version) BETWEEN 1 AND 200),
                CHECK(length(digest) BETWEEN 1 AND 200),
                CHECK(length(qualification_mode) BETWEEN 1 AND 200),
                CHECK(length(qualification_evidence_ref) BETWEEN 1 AND 200),
                CHECK(length(capture_mode) BETWEEN 1 AND 200),
                CHECK(length(evidence_mode) BETWEEN 1 AND 200),
                CHECK(length(capture_mode) BETWEEN 1 AND 200)
            );
            

            CREATE TRIGGER billium_execution_capability_snapshot_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_capability_snapshot FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();
            
            CREATE TABLE public.billium_execution_business_binding (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                intent_owner_namespace text NOT NULL,
                business_intent_ref text NOT NULL,
                operation_kind text NOT NULL,
                semantic_digest text NOT NULL,
                canonicalization_version text NOT NULL,
                operation_ref text NOT NULL,
                binding jsonb NOT NULL,
                tombstone_reason text,
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,operation_ref),
                CHECK(operation_kind IN ('charge','capture','void_authorization','refund')),
                CHECK(length(intent_owner_namespace) BETWEEN 1 AND 200),
                CHECK(length(business_intent_ref) BETWEEN 1 AND 200),
                CHECK(length(operation_kind) BETWEEN 1 AND 200),
                CHECK(length(semantic_digest) BETWEEN 1 AND 200),
                CHECK(length(canonicalization_version) BETWEEN 1 AND 200),
                CHECK(length(operation_ref) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_business_binding_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_business_binding FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();
            
            CREATE TABLE public.billium_execution_operation (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                operation_ref text NOT NULL,
                root_operation_ref text NOT NULL,
                kind text NOT NULL,
                binding jsonb NOT NULL,
                binding_digest text NOT NULL,
                business_intent_ref text NOT NULL,
                intent_owner_namespace text NOT NULL,
                merchant_revision_ref text NOT NULL,
                merchant_account_ref text NOT NULL,
                driver_id text NOT NULL,
                driver_contract_version text NOT NULL,
                capability_digest text NOT NULL,
                requested_minor bigint NOT NULL,
                currency text NOT NULL,
                currency_exponent integer NOT NULL,
                state text NOT NULL,
                revision bigint NOT NULL DEFAULT 0,
                economic_digest text NOT NULL,
                authorization_ref text,
                collection_authorization_ref text,
                created_at timestamptz NOT NULL DEFAULT now(),
                next_reconcile_at timestamptz,
                last_reconciled_at timestamptz,
                reconcile_attempts bigint NOT NULL DEFAULT 0,
                reconcile_state text NOT NULL DEFAULT 'due',
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,operation_ref),
                UNIQUE(installation_ref,seller_ref,environment,operation_ref,root_operation_ref,merchant_account_ref,currency),
                CHECK(kind IN ('charge','authorize','capture','void_authorization','refund')),
                CHECK(requested_minor>0 AND revision>=0),
                CHECK((kind IN ('charge','authorize')) = (operation_ref=root_operation_ref)),
                CHECK(state IN ('admitted','pending','unknown','succeeded','failed','cancelled','recovery_required','partially_captured','authorized','captured','refunded','voided','expired','held')),
                CHECK(currency ~ '^[A-Z]{3}$' AND currency_exponent BETWEEN 0 AND 4),
                CHECK(length(operation_ref) BETWEEN 1 AND 200),
                CHECK(length(root_operation_ref) BETWEEN 1 AND 200),
                CHECK(length(kind) BETWEEN 1 AND 200),
                CHECK(length(binding_digest) BETWEEN 1 AND 200),
                CHECK(length(business_intent_ref) BETWEEN 1 AND 200),
                CHECK(length(intent_owner_namespace) BETWEEN 1 AND 200),
                CHECK(length(merchant_revision_ref) BETWEEN 1 AND 200),
                CHECK(length(merchant_account_ref) BETWEEN 1 AND 200),
                CHECK(length(driver_id) BETWEEN 1 AND 200),
                CHECK(length(driver_contract_version) BETWEEN 1 AND 200),
                CHECK(length(capability_digest) BETWEEN 1 AND 200),
                CHECK(length(currency) BETWEEN 1 AND 200),
                CHECK(length(state) BETWEEN 1 AND 200),
                CHECK(length(economic_digest) BETWEEN 1 AND 200),
                CHECK(length(reconcile_state) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_operation_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_operation FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('state','revision','economic_digest','authorization_ref','next_reconcile_at','last_reconciled_at','reconcile_attempts','reconcile_state');
            
            CREATE TABLE public.billium_execution_operation_revision (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                operation_ref text NOT NULL,
                revision bigint NOT NULL,
                material_digest text NOT NULL,
                requested_minor bigint NOT NULL,
                currency text NOT NULL,
                observed jsonb NOT NULL,
                last_consistent_applied jsonb NOT NULL,
                financial_hold jsonb NOT NULL,
                exposure jsonb NOT NULL,
                evidence_refs jsonb NOT NULL,
                receipt_ref text NOT NULL,
                occurred_at timestamptz NOT NULL,
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,operation_ref,revision),
                CHECK(revision>0 AND requested_minor>0),
                CHECK(length(operation_ref) BETWEEN 1 AND 200),
                CHECK(length(material_digest) BETWEEN 1 AND 200),
                CHECK(length(currency) BETWEEN 1 AND 200),
                CHECK(length(receipt_ref) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_operation_revision_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_operation_revision FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();
            
            CREATE TABLE public.billium_execution_capacity (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                root_operation_ref text NOT NULL,
                currency text NOT NULL,
                authorized_limit_minor bigint NOT NULL DEFAULT 0,
                confirmed_capture_minor bigint NOT NULL DEFAULT 0,
                confirmed_refund_minor bigint NOT NULL DEFAULT 0,
                released_authorization_minor bigint NOT NULL DEFAULT 0,
                held_capture_minor bigint NOT NULL DEFAULT 0,
                held_void_minor bigint NOT NULL DEFAULT 0,
                held_refund_minor bigint NOT NULL DEFAULT 0,
                financial_hold boolean NOT NULL DEFAULT false,
                version bigint NOT NULL DEFAULT 0,
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,root_operation_ref),
                CHECK(authorized_limit_minor>=0 AND confirmed_capture_minor>=0 AND confirmed_refund_minor>=0 AND released_authorization_minor>=0 AND held_capture_minor>=0 AND held_void_minor>=0 AND held_refund_minor>=0 AND version>=0),
                CHECK(length(root_operation_ref) BETWEEN 1 AND 200),
                CHECK(length(currency) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_capacity_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_capacity FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('authorized_limit_minor','confirmed_capture_minor','confirmed_refund_minor','released_authorization_minor','held_capture_minor','held_void_minor','held_refund_minor','financial_hold','version');
            
            CREATE TABLE public.billium_execution_reservation (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                reservation_ref text NOT NULL,
                root_operation_ref text NOT NULL,
                operation_ref text NOT NULL,
                kind text NOT NULL,
                requested_minor bigint NOT NULL,
                currency text NOT NULL,
                state text NOT NULL,
                consumed_minor bigint NOT NULL DEFAULT 0,
                release_evidence_ref text,
                version bigint NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,reservation_ref),
                UNIQUE(installation_ref,seller_ref,environment,operation_ref),
                CHECK(kind IN ('capture','void_authorization','refund')),
                CHECK(requested_minor>0 AND consumed_minor>=0 AND consumed_minor<=requested_minor AND version>=0),
                CHECK(state IN ('held','consumed','released')),
                CHECK(state<>'released' OR release_evidence_ref IS NOT NULL),
                CHECK(length(reservation_ref) BETWEEN 1 AND 200),
                CHECK(length(root_operation_ref) BETWEEN 1 AND 200),
                CHECK(length(operation_ref) BETWEEN 1 AND 200),
                CHECK(length(kind) BETWEEN 1 AND 200),
                CHECK(length(currency) BETWEEN 1 AND 200),
                CHECK(length(state) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_reservation_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_reservation FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('state','consumed_minor','release_evidence_ref','version');
            
            CREATE TABLE public.billium_execution_attempt (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                attempt_ref text NOT NULL,
                operation_ref text NOT NULL,
                provider_reference text NOT NULL,
                provider_id text NOT NULL,
                merchant_account_ref text NOT NULL,
                state text NOT NULL DEFAULT 'admitted',
                lease_owner text,
                lease_fence bigint NOT NULL DEFAULT 0,
                lease_expires_at timestamptz,
                session_epoch bigint,
                owner_session_ref text,
                dispatch_ticket_ref text,
                request_digest text NOT NULL,
                started_at timestamptz,
                request_deadline_at timestamptz,
                finish_evidence_ref text,
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,attempt_ref),
                UNIQUE(installation_ref,seller_ref,environment,operation_ref),
                CHECK(state IN ('admitted','dispatching','pending','unknown','succeeded','failed','cancelled')),
                CHECK(lease_fence>=0),
                CHECK(length(attempt_ref) BETWEEN 1 AND 200),
                CHECK(length(operation_ref) BETWEEN 1 AND 200),
                CHECK(length(provider_reference) BETWEEN 1 AND 200),
                CHECK(length(provider_id) BETWEEN 1 AND 200),
                CHECK(length(merchant_account_ref) BETWEEN 1 AND 200),
                CHECK(length(state) BETWEEN 1 AND 200),
                CHECK(length(request_digest) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_attempt_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_attempt FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('state','lease_owner','lease_fence','lease_expires_at','session_epoch','owner_session_ref','dispatch_ticket_ref','started_at','request_deadline_at','finish_evidence_ref');
            
            CREATE TABLE public.billium_execution_provider_fact (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                fact_ref text NOT NULL,
                operation_ref text NOT NULL,
                provider_identity_scope text NOT NULL,
                fact_identity text NOT NULL,
                evidence_mode text NOT NULL,
                material_digest text NOT NULL,
                normalized_snapshot jsonb NOT NULL,
                evidence_refs jsonb NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,fact_ref),
                UNIQUE(installation_ref,seller_ref,environment,provider_identity_scope,fact_identity,material_digest),
                CHECK(evidence_mode IN ('cumulative_snapshot','stable_effect_id')),
                CHECK(length(fact_ref) BETWEEN 1 AND 200),
                CHECK(length(operation_ref) BETWEEN 1 AND 200),
                CHECK(length(provider_identity_scope) BETWEEN 1 AND 200),
                CHECK(length(fact_identity) BETWEEN 1 AND 200),
                CHECK(length(evidence_mode) BETWEEN 1 AND 200),
                CHECK(length(material_digest) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_provider_fact_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_provider_fact FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();
            
            CREATE TABLE public.billium_execution_discrepancy (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                discrepancy_ref text NOT NULL,
                root_operation_ref text NOT NULL,
                operation_ref text,
                kind text NOT NULL,
                evidence_refs jsonb NOT NULL,
                observed jsonb NOT NULL,
                last_consistent_applied jsonb NOT NULL,
                exposure jsonb NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                correction_of_ref text,
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,discrepancy_ref),
                CHECK(length(discrepancy_ref) BETWEEN 1 AND 200),
                CHECK(length(root_operation_ref) BETWEEN 1 AND 200),
                CHECK(length(kind) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_discrepancy_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_discrepancy FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();
            
            CREATE TABLE public.billium_execution_observation_partition (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                source_authority text NOT NULL,
                source_lineage text NOT NULL,
                root_operation_ref text NOT NULL,
                partition_ref text NOT NULL,
                last_sequence bigint NOT NULL DEFAULT 0,
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,source_authority,source_lineage,root_operation_ref),
                UNIQUE(installation_ref,seller_ref,environment,source_authority,source_lineage,partition_ref),
                CHECK(last_sequence>=0),
                CHECK(length(source_authority) BETWEEN 1 AND 200),
                CHECK(length(source_lineage) BETWEEN 1 AND 200),
                CHECK(length(root_operation_ref) BETWEEN 1 AND 200),
                CHECK(length(partition_ref) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_observation_partition_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_observation_partition FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('last_sequence');
            
            CREATE TABLE public.billium_execution_observation_emission (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                emission_ref text NOT NULL,
                source_authority text NOT NULL,
                source_lineage text NOT NULL,
                partition_ref text NOT NULL,
                source_sequence bigint NOT NULL,
                root_operation_ref text NOT NULL,
                operation_ref text NOT NULL,
                operation_revision bigint NOT NULL,
                command_ref text NOT NULL,
                event_id text NOT NULL,
                payload_digest text NOT NULL,
                payload jsonb NOT NULL,
                command_row_id bigint,
                command_schema text NOT NULL DEFAULT 'payment.observation.v1',
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,emission_ref),
                UNIQUE(installation_ref,seller_ref,environment,source_authority,source_lineage,partition_ref,source_sequence),
                UNIQUE(installation_ref,seller_ref,environment,operation_ref,operation_revision,source_lineage,command_schema),
                CHECK(source_sequence>0 AND operation_revision>0),
                CHECK(command_schema='payment.observation.v1'),
                CHECK(length(emission_ref) BETWEEN 1 AND 200),
                CHECK(length(source_authority) BETWEEN 1 AND 200),
                CHECK(length(source_lineage) BETWEEN 1 AND 200),
                CHECK(length(partition_ref) BETWEEN 1 AND 200),
                CHECK(length(root_operation_ref) BETWEEN 1 AND 200),
                CHECK(length(operation_ref) BETWEEN 1 AND 200),
                CHECK(length(command_ref) BETWEEN 1 AND 200),
                CHECK(length(event_id) BETWEEN 1 AND 200),
                CHECK(length(payload_digest) BETWEEN 1 AND 200),
                CHECK(length(command_schema) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_observation_emission_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_observation_emission FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('command_row_id');
            
            CREATE TABLE public.billium_execution_authorization (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                authorization_ref text NOT NULL,
                root_operation_ref text NOT NULL,
                authorized_minor bigint NOT NULL,
                released_minor bigint NOT NULL,
                currency text NOT NULL,
                provider_expires_at timestamptz,
                expiry_evidence_ref text,
                state text NOT NULL,
                revision bigint NOT NULL,
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,authorization_ref),
                UNIQUE(installation_ref,seller_ref,environment,root_operation_ref),
                CHECK(authorized_minor>=0 AND released_minor>=0 AND revision>=0),
                CHECK(state IN ('authorized','partially_captured','captured','voided','expired','pending','unknown')),
                CHECK(released_minor=0 OR expiry_evidence_ref IS NOT NULL),
                CHECK(length(authorization_ref) BETWEEN 1 AND 200),
                CHECK(length(root_operation_ref) BETWEEN 1 AND 200),
                CHECK(length(currency) BETWEEN 1 AND 200),
                CHECK(length(state) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_authorization_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_authorization FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('authorized_minor','released_minor','provider_expires_at','expiry_evidence_ref','state','revision');
            
            CREATE TABLE public.billium_execution_reconciliation_run (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                run_ref text NOT NULL,
                merchant_account_ref text NOT NULL,
                recovery_horizon timestamptz NOT NULL,
                inventory_cursor text,
                completeness_watermark text,
                inventory_digest text,
                manifest_digest text,
                state text NOT NULL,
                unresolved jsonb NOT NULL,
                started_at timestamptz NOT NULL,
                completed_at timestamptz,
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,run_ref),
                CHECK(state IN ('running','incomplete','complete','failed','parked')),
                CHECK(state<>'complete' OR (completeness_watermark IS NOT NULL AND inventory_digest IS NOT NULL AND manifest_digest IS NOT NULL AND completed_at IS NOT NULL)),
                CHECK(length(run_ref) BETWEEN 1 AND 200),
                CHECK(length(merchant_account_ref) BETWEEN 1 AND 200),
                CHECK(length(state) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_reconciliation_run_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_reconciliation_run FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('inventory_cursor','completeness_watermark','inventory_digest','manifest_digest','state','unresolved','completed_at');
            
            CREATE TABLE public.billium_execution_repair_receipt (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                repair_ref text NOT NULL,
                root_operation_ref text NOT NULL,
                authorization_ref text NOT NULL,
                reason text NOT NULL,
                evidence_refs jsonb NOT NULL,
                before_digest text NOT NULL,
                after_digest text NOT NULL,
                correction_refs jsonb NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                action_kind text NOT NULL,
                intent_owner_namespace text NOT NULL,
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,repair_ref),
                CHECK(length(repair_ref) BETWEEN 1 AND 200),
                CHECK(length(root_operation_ref) BETWEEN 1 AND 200),
                CHECK(length(authorization_ref) BETWEEN 1 AND 200),
                CHECK(length(reason) BETWEEN 1 AND 512),
                CHECK(length(before_digest) BETWEEN 1 AND 200),
                CHECK(length(after_digest) BETWEEN 1 AND 200),
                CHECK(length(action_kind) BETWEEN 1 AND 200),
                CHECK(length(intent_owner_namespace) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_repair_receipt_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_repair_receipt FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();
            
            CREATE TABLE public.billium_execution_dispatch_work (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                work_ref text NOT NULL,
                operation_ref text NOT NULL,
                attempt_ref text NOT NULL,
                handler_version text NOT NULL,
                state text NOT NULL DEFAULT 'due',
                due_at timestamptz NOT NULL DEFAULT now(),
                lease_owner text,
                lease_fence bigint NOT NULL DEFAULT 0,
                lease_expires_at timestamptz,
                attempt_count bigint NOT NULL DEFAULT 0,
                last_error_code text,
                last_error_at timestamptz,
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,work_ref),
                UNIQUE(installation_ref,seller_ref,environment,attempt_ref,handler_version),
                CHECK(state IN ('due','leased','processed','parked')),
                CHECK(lease_fence>=0 AND attempt_count>=0),
                CHECK(length(work_ref) BETWEEN 1 AND 200),
                CHECK(length(operation_ref) BETWEEN 1 AND 200),
                CHECK(length(attempt_ref) BETWEEN 1 AND 200),
                CHECK(length(handler_version) BETWEEN 1 AND 200),
                CHECK(length(state) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_dispatch_work_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_dispatch_work FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('state','due_at','lease_owner','lease_fence','lease_expires_at','attempt_count','last_error_code','last_error_at');
            
            CREATE TABLE public.billium_execution_provider_evidence (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                evidence_ref text NOT NULL,
                provider_id text NOT NULL,
                merchant_account_ref text NOT NULL,
                provider_event_id text,
                payload_digest text NOT NULL,
                signature_verdict text NOT NULL,
                handler_contract_version text NOT NULL,
                received_at timestamptz NOT NULL,
                sanitized_payload jsonb NOT NULL,
                secure_payload_ref text,
                raw_payload bytea,
                intent_owner_namespace text NOT NULL,
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,evidence_ref),
                CHECK(length(evidence_ref) BETWEEN 1 AND 200),
                CHECK(length(provider_id) BETWEEN 1 AND 200),
                CHECK(length(merchant_account_ref) BETWEEN 1 AND 200),
                CHECK(length(payload_digest) BETWEEN 1 AND 200),
                CHECK(length(signature_verdict) BETWEEN 1 AND 200),
                CHECK(length(handler_contract_version) BETWEEN 1 AND 200),
                CHECK(length(intent_owner_namespace) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_provider_evidence_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_provider_evidence FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();
            
            CREATE TABLE public.billium_execution_provider_work (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                work_ref text NOT NULL,
                evidence_ref text NOT NULL,
                handler_contract_version text NOT NULL,
                state text NOT NULL DEFAULT 'due',
                attempt_count bigint NOT NULL DEFAULT 0,
                due_at timestamptz NOT NULL DEFAULT now(),
                lease_owner text,
                lease_fence bigint NOT NULL DEFAULT 0,
                lease_expires_at timestamptz,
                last_error_code text,
                last_error_at timestamptz,
                result_ref text,
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,work_ref),
                UNIQUE(installation_ref,seller_ref,environment,evidence_ref,handler_contract_version),
                CHECK(state IN ('due','leased','processed','parked','quarantined')),
                CHECK(lease_fence>=0 AND attempt_count>=0),
                CHECK(state<>'leased' OR (lease_owner IS NOT NULL AND lease_expires_at IS NOT NULL)),
                CHECK(length(work_ref) BETWEEN 1 AND 200),
                CHECK(length(evidence_ref) BETWEEN 1 AND 200),
                CHECK(length(handler_contract_version) BETWEEN 1 AND 200),
                CHECK(length(state) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_provider_work_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_provider_work FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable('state','due_at','lease_owner','lease_fence','lease_expires_at','attempt_count','last_error_code','last_error_at','result_ref');
            
            CREATE TABLE public.billium_execution_merchant_lifecycle_event (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                event_ref text NOT NULL,
                revision_ref text NOT NULL,
                action text NOT NULL,
                purpose text NOT NULL,
                effective_at timestamptz NOT NULL,
                retention_until timestamptz,
                actor_ref text NOT NULL,
                reason text NOT NULL,
                evidence_ref text NOT NULL,
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,event_ref),
                CHECK(action IN ('enable_new','stop_new','enable_recovery','disable_recovery')),
                CHECK(purpose IN ('new','lookup','refund','capture','void_authorization','reconciliation')),
                CHECK(length(event_ref) BETWEEN 1 AND 200),
                CHECK(length(revision_ref) BETWEEN 1 AND 200),
                CHECK(length(action) BETWEEN 1 AND 200),
                CHECK(length(purpose) BETWEEN 1 AND 200),
                CHECK(length(actor_ref) BETWEEN 1 AND 200),
                CHECK(length(reason) BETWEEN 1 AND 512),
                CHECK(length(evidence_ref) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_merchant_lifecycle_event_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_merchant_lifecycle_event FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();
            
            CREATE TABLE public.billium_execution_merchant_credential_revision (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                installation_ref text NOT NULL CHECK(length(installation_ref) BETWEEN 1 AND 200),
                seller_ref text NOT NULL CHECK(length(seller_ref) BETWEEN 1 AND 200),
                environment text NOT NULL CHECK(length(environment) BETWEEN 1 AND 200),
                credential_revision_ref text NOT NULL,
                revision_ref text NOT NULL,
                merchant_account_ref text NOT NULL,
                credential_ref text NOT NULL,
                credential_version_ref text NOT NULL,
                purpose text NOT NULL,
                valid_from timestamptz NOT NULL,
                valid_until timestamptz NOT NULL,
                identity_continuity_evidence_ref text NOT NULL,
                predecessor_ref text,
                UNIQUE(installation_ref,seller_ref,environment,id),
                UNIQUE(installation_ref,seller_ref,environment,credential_revision_ref),
                UNIQUE(installation_ref,seller_ref,environment,revision_ref,credential_version_ref,purpose),
                CHECK(valid_until>valid_from),
                CHECK(credential_ref ~ '^secret://[A-Za-z0-9][A-Za-z0-9/._:-]*$'),
                CHECK(purpose IN ('new','lookup','refund','capture','void_authorization','reconciliation')),
                CHECK(length(credential_revision_ref) BETWEEN 1 AND 200),
                CHECK(length(revision_ref) BETWEEN 1 AND 200),
                CHECK(length(merchant_account_ref) BETWEEN 1 AND 200),
                CHECK(length(credential_ref) BETWEEN 1 AND 200),
                CHECK(length(credential_version_ref) BETWEEN 1 AND 200),
                CHECK(length(purpose) BETWEEN 1 AND 200),
                CHECK(length(identity_continuity_evidence_ref) BETWEEN 1 AND 200)
            );
            
            CREATE TRIGGER billium_execution_merchant_credential_revision_immutable BEFORE UPDATE OR DELETE ON public.billium_execution_merchant_credential_revision FOR EACH ROW EXECUTE FUNCTION public.billium_execution_immutable();
            
            CREATE UNIQUE INDEX billium_execution_business_identity ON public.billium_execution_business_binding(installation_ref,environment,intent_owner_namespace,business_intent_ref,operation_kind);
            
            CREATE UNIQUE INDEX billium_execution_provider_reference ON public.billium_execution_attempt(installation_ref,environment,provider_id,merchant_account_ref,provider_reference);
            
            CREATE UNIQUE INDEX billium_execution_command_ref ON public.billium_execution_observation_emission(command_ref);
            
            CREATE UNIQUE INDEX billium_execution_event_id ON public.billium_execution_observation_emission(event_id);
            
            CREATE INDEX billium_execution_operation_lookup ON public.billium_execution_operation(installation_ref,seller_ref,environment,state,next_reconcile_at,id);
            
            CREATE INDEX billium_execution_attempt_lookup ON public.billium_execution_attempt(installation_ref,seller_ref,environment,state,lease_expires_at,id);
            
            CREATE INDEX billium_execution_dispatch_work_lookup ON public.billium_execution_dispatch_work(installation_ref,seller_ref,environment,state,due_at,id);
            
            CREATE INDEX billium_execution_provider_work_lookup ON public.billium_execution_provider_work(installation_ref,seller_ref,environment,state,due_at,id);
            
            CREATE INDEX billium_execution_provider_evidence_lookup ON public.billium_execution_provider_evidence(installation_ref,seller_ref,environment,provider_id,merchant_account_ref,provider_event_id);
            
            CREATE INDEX billium_execution_provider_fact_lookup ON public.billium_execution_provider_fact(installation_ref,seller_ref,environment,operation_ref,created_at,id);
            
            CREATE INDEX billium_execution_discrepancy_lookup ON public.billium_execution_discrepancy(installation_ref,seller_ref,environment,root_operation_ref,created_at,id);
            
            CREATE INDEX billium_execution_merchant_revision_lookup ON public.billium_execution_merchant_revision(installation_ref,seller_ref,environment,merchant_account_ref,valid_from,id);
            
            ALTER TABLE public.billium_execution_merchant_revision ADD CONSTRAINT ex_merchant_revision_capability_snapshot_fk FOREIGN KEY (installation_ref,seller_ref,environment,capability_snapshot_ref,merchant_account_ref,driver_id,driver_contract_version,capability_digest) REFERENCES public.billium_execution_capability_snapshot(installation_ref,seller_ref,environment,snapshot_ref,merchant_account_ref,driver_id,driver_contract_version,digest) NOT VALID;
            
            
            ALTER TABLE public.billium_execution_operation ADD CONSTRAINT ex_operation_merchant_revision_fk FOREIGN KEY (installation_ref,seller_ref,environment,merchant_revision_ref,merchant_account_ref,driver_id,driver_contract_version,capability_digest) REFERENCES public.billium_execution_merchant_revision(installation_ref,seller_ref,environment,revision_ref,merchant_account_ref,driver_id,driver_contract_version,capability_digest) NOT VALID;
            
            
            ALTER TABLE public.billium_execution_operation ADD CONSTRAINT ex_operation_operation_fk FOREIGN KEY (installation_ref,seller_ref,environment,root_operation_ref) REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref) NOT VALID;
            
            
            ALTER TABLE public.billium_execution_capacity ADD CONSTRAINT ex_capacity_operation_fk FOREIGN KEY (installation_ref,seller_ref,environment,root_operation_ref) REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref) NOT VALID;
            
            
            ALTER TABLE public.billium_execution_reservation ADD CONSTRAINT ex_reservation_operation_fk FOREIGN KEY (installation_ref,seller_ref,environment,operation_ref) REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref) NOT VALID;
            
            
            ALTER TABLE public.billium_execution_attempt ADD CONSTRAINT ex_attempt_operation_fk FOREIGN KEY (installation_ref,seller_ref,environment,operation_ref) REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref) NOT VALID;
            
            
            ALTER TABLE public.billium_execution_provider_fact ADD CONSTRAINT ex_provider_fact_operation_fk FOREIGN KEY (installation_ref,seller_ref,environment,operation_ref) REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref) NOT VALID;
            
            
            ALTER TABLE public.billium_execution_operation_revision ADD CONSTRAINT ex_operation_revision_operation_fk FOREIGN KEY (installation_ref,seller_ref,environment,operation_ref) REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref) NOT VALID;
            
            
            ALTER TABLE public.billium_execution_authorization ADD CONSTRAINT ex_authorization_operation_fk FOREIGN KEY (installation_ref,seller_ref,environment,root_operation_ref) REFERENCES public.billium_execution_operation(installation_ref,seller_ref,environment,operation_ref) NOT VALID;
            
            
            ALTER TABLE public.billium_execution_provider_work ADD CONSTRAINT ex_provider_work_provider_evidence_fk FOREIGN KEY (installation_ref,seller_ref,environment,evidence_ref) REFERENCES public.billium_execution_provider_evidence(installation_ref,seller_ref,environment,evidence_ref) NOT VALID;
            
            
            ALTER TABLE public.billium_execution_dispatch_work ADD CONSTRAINT ex_dispatch_work_attempt_fk FOREIGN KEY (installation_ref,seller_ref,environment,attempt_ref) REFERENCES public.billium_execution_attempt(installation_ref,seller_ref,environment,attempt_ref) NOT VALID;
            
            
            ALTER TABLE public.billium_execution_merchant_lifecycle_event ADD CONSTRAINT ex_merchant_lifecycle_event_merchant_revision_fk FOREIGN KEY (installation_ref,seller_ref,environment,revision_ref) REFERENCES public.billium_execution_merchant_revision(installation_ref,seller_ref,environment,revision_ref) NOT VALID;
            
            
            ALTER TABLE public.billium_execution_merchant_credential_revision ADD CONSTRAINT ex_merchant_credential_revision_merchant_revision_fk FOREIGN KEY (installation_ref,seller_ref,environment,revision_ref) REFERENCES public.billium_execution_merchant_revision(installation_ref,seller_ref,environment,revision_ref) NOT VALID;
            
            
            CREATE INDEX billium_execution_lifecycle_lookup ON public.billium_execution_merchant_lifecycle_event(installation_ref,seller_ref,environment,revision_ref,purpose,effective_at DESC,id DESC);
            
            CREATE INDEX billium_execution_credential_lookup ON public.billium_execution_merchant_credential_revision(installation_ref,seller_ref,environment,revision_ref,purpose,valid_from,id);
            
            CREATE FUNCTION public.billium_execution_reservation_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            DECLARE c public.billium_execution_capacity; o public.billium_execution_operation; held numeric;
            BEGIN
             SELECT * INTO c FROM public.billium_execution_capacity WHERE installation_ref=NEW.installation_ref AND seller_ref=NEW.seller_ref AND environment=NEW.environment AND root_operation_ref=NEW.root_operation_ref FOR UPDATE;
             SELECT * INTO o FROM public.billium_execution_operation WHERE installation_ref=NEW.installation_ref AND seller_ref=NEW.seller_ref AND environment=NEW.environment AND operation_ref=NEW.operation_ref;
             IF c.id IS NULL OR o.id IS NULL OR o.root_operation_ref<>NEW.root_operation_ref OR o.kind<>NEW.kind OR o.currency<>NEW.currency OR c.currency<>NEW.currency OR o.requested_minor<>NEW.requested_minor THEN
              RAISE EXCEPTION 'billium: reservation scope/root/kind/currency mismatch' USING ERRCODE='23514'; END IF;
             IF TG_OP='INSERT' THEN
              IF NEW.state<>'held' OR NEW.consumed_minor<>0 OR c.financial_hold THEN RAISE EXCEPTION 'billium: reservation requires open capacity' USING ERRCODE='23514'; END IF;
              IF NEW.kind='refund' THEN
               SELECT coalesce(sum(requested_minor-consumed_minor),0) INTO held FROM public.billium_execution_reservation WHERE installation_ref=NEW.installation_ref AND seller_ref=NEW.seller_ref AND environment=NEW.environment AND root_operation_ref=NEW.root_operation_ref AND kind='refund' AND state='held';
               IF c.confirmed_refund_minor::numeric+held+NEW.requested_minor>c.confirmed_capture_minor THEN RAISE EXCEPTION 'billium: refund capacity exceeded' USING ERRCODE='23514'; END IF;
              ELSE
               SELECT coalesce(sum(requested_minor-consumed_minor),0) INTO held FROM public.billium_execution_reservation WHERE installation_ref=NEW.installation_ref AND seller_ref=NEW.seller_ref AND environment=NEW.environment AND root_operation_ref=NEW.root_operation_ref AND kind IN ('capture','void_authorization') AND state='held';
               IF c.confirmed_capture_minor::numeric+c.released_authorization_minor+held+NEW.requested_minor>c.authorized_limit_minor THEN RAISE EXCEPTION 'billium: authorization capacity exceeded' USING ERRCODE='23514'; END IF;
              END IF;
             ELSIF OLD.state<>'held' OR NEW.version<>OLD.version+1 OR NEW.state='held' THEN RAISE EXCEPTION 'billium: reservation terminal transition invalid' USING ERRCODE='23514'; END IF;
             RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_execution_reservation_guard() FROM PUBLIC;
            CREATE TRIGGER billium_execution_reservation_guard BEFORE INSERT OR UPDATE ON public.billium_execution_reservation FOR EACH ROW EXECUTE FUNCTION public.billium_execution_reservation_guard();
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

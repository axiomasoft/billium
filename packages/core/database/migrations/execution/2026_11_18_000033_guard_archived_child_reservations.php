<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.billium_execution_reservation_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            DECLARE c public.billium_execution_capacity; o public.billium_execution_operation; held numeric;
            BEGIN
             SELECT * INTO c FROM public.billium_execution_capacity WHERE installation_ref=NEW.installation_ref AND seller_ref=NEW.seller_ref AND environment=NEW.environment AND root_operation_ref=NEW.root_operation_ref FOR UPDATE;
             SELECT * INTO o FROM public.billium_execution_operation WHERE installation_ref=NEW.installation_ref AND seller_ref=NEW.seller_ref AND environment=NEW.environment AND operation_ref=NEW.operation_ref;
             IF c.id IS NULL OR o.id IS NULL OR o.root_operation_ref<>NEW.root_operation_ref OR o.kind<>NEW.kind OR o.currency<>NEW.currency OR c.currency<>NEW.currency OR o.requested_minor<>NEW.requested_minor THEN
              RAISE EXCEPTION 'billium: reservation scope/root/kind/currency mismatch' USING ERRCODE='23514'; END IF;
             IF TG_OP='INSERT' THEN
              IF NEW.state<>'held' OR NEW.consumed_minor<>0 THEN RAISE EXCEPTION 'billium: reservation requires an unsettled request' USING ERRCODE='23514'; END IF;
              -- A verified import reconstructs the original reservation after the irreversible, fenced dispatch.
              -- Cumulative evidence may already contain this child; attribution and settlement still require its own fact.
              IF o.operation_ref<>o.root_operation_ref AND o.state='unknown' AND o.revision=0 AND EXISTS (
                SELECT 1 FROM public.billium_execution_original_identity_import i
                JOIN public.billium_execution_attempt a ON (a.installation_ref,a.seller_ref,a.environment,a.operation_ref,a.attempt_ref)
                  = (i.installation_ref,i.seller_ref,i.environment,i.operation_ref,i.attempt_ref)
                JOIN public.billium_execution_namespace n ON (n.installation_ref,n.seller_ref,n.environment,n.intent_owner_namespace)
                  = (i.installation_ref,i.seller_ref,i.environment,i.intent_owner_namespace)
                WHERE (i.installation_ref,i.seller_ref,i.environment,i.intent_owner_namespace,i.operation_ref)
                  = (o.installation_ref,o.seller_ref,o.environment,o.intent_owner_namespace,o.operation_ref)
                  AND n.state IN ('closed','recovery_required') AND a.state='unknown' AND a.started_at IS NOT NULL
                  AND a.dispatch_ticket_ref IS NOT NULL AND a.lease_fence::numeric=i.original_fence::numeric+1
                  AND a.session_epoch>0 AND a.owner_session_ref IS NOT NULL AND a.lease_owner IS NULL AND a.lease_expires_at IS NULL
                  AND a.merchant_account_ref=o.merchant_account_ref AND NOT EXISTS (
                    SELECT 1 FROM public.billium_execution_dispatch_work w WHERE (w.installation_ref,w.seller_ref,w.environment,w.operation_ref)
                      = (o.installation_ref,o.seller_ref,o.environment,o.operation_ref))
              ) THEN RETURN NEW; END IF;
              IF c.financial_hold THEN RAISE EXCEPTION 'billium: reservation requires open capacity' USING ERRCODE='23514'; END IF;
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
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

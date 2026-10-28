<?php

declare(strict_types=1);

use Billium\Ledger\Internal\Repositories\LedgerPrivileges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- P3.9: guarded observation routines (INV-06, INV-08, D41, D46, D48, D57, D60). The observed fact (custody of the
            -- provider snapshot) is separate from the applied projection and from allocation. Totals are assigned from the
            -- immutable observed revision row, never added as deltas and never taken from a caller argument.
            -- migrate:fresh keeps standalone functions: drop every earlier signature so no stale overload survives.
            DO $$ DECLARE f regprocedure; BEGIN
                FOR f IN SELECT p.oid::regprocedure FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                    WHERE n.nspname='public' AND p.proname IN ('billium_record_observation','billium_project_payment','billium_record_refund_detail',
                        'billium_reconcile_refund_basis','billium_park_observation','billium_release_observation_park')
                LOOP EXECUTE format('DROP FUNCTION %s',f); END LOOP;
            END $$;

            -- An economic observation waiting for its dependency (settlement intent/account or original charge). The observed
            -- fact stays queryable meanwhile; the row is the allocation_pending marker and the retry key of the live delivery
            -- (inbox_id) or of a bootstrap item (inbox_id NULL, re-projected from the stored observed revision).
            CREATE TABLE public.billium_observation_park (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            installation_ref text NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
            seller_ref text NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
            environment text NOT NULL CHECK (environment IN ('test','live')),
            observed_payment_id bigint NOT NULL,
            revision bigint NOT NULL CHECK (revision >= 1),
            inbox_id bigint NULL,
            dependency_kind text NOT NULL CHECK (dependency_kind IN ('settlement_intent','original_charge')),
            dependency_ref text NOT NULL CHECK (dependency_ref <> ''),
            released_at timestamptz NULL,
            release_reason text NULL CHECK (release_reason IS NULL OR release_reason <> ''),
            created_at timestamptz NOT NULL DEFAULT now(),
            CHECK ((released_at IS NULL) = (release_reason IS NULL)),
            UNIQUE (seller_ref,environment,id),
            FOREIGN KEY (seller_ref,environment,observed_payment_id,revision) REFERENCES public.billium_observed_payment_revision(seller_ref,environment,observed_payment_id,revision)
            );
            CREATE UNIQUE INDEX billium_observation_park_inbox ON public.billium_observation_park(seller_ref,environment,inbox_id) WHERE inbox_id IS NOT NULL;
            CREATE UNIQUE INDEX billium_observation_park_item ON public.billium_observation_park(seller_ref,environment,observed_payment_id,revision) WHERE inbox_id IS NULL AND released_at IS NULL;
            CREATE INDEX billium_observation_park_open ON public.billium_observation_park(seller_ref,environment,dependency_kind,dependency_ref,id) WHERE released_at IS NULL;

            CREATE OR REPLACE FUNCTION public.billium_observation_park_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP='DELETE' OR (to_jsonb(NEW)-ARRAY['released_at','release_reason']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['released_at','release_reason'])
                   OR OLD.released_at IS NOT NULL
                THEN RAISE EXCEPTION 'billium: a parked observation is released once and never edited' USING ERRCODE='55000'; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER billium_observation_park_guard BEFORE UPDATE OR DELETE ON public.billium_observation_park FOR EACH ROW EXECUTE FUNCTION public.billium_observation_park_guard();
            REVOKE ALL ON FUNCTION public.billium_observation_park_guard() FROM PUBLIC;

            -- Observed fact of one revision. The economic identity is (seller, environment, sourceAuthority, operationRef):
            -- a restored source lineage reuses it instead of creating a second one (D60). Actual values may exceed normal
            -- bounds; they are preserved as observed (D57). Dispositions: new | advanced | stale | duplicate | conflict |
            -- contradiction (immutable installation/kind/currency/provider account/original differ).
            CREATE OR REPLACE FUNCTION public.billium_record_observation(
                p_installation text,p_seller text,p_environment text,p_authority text,p_lineage text,p_operation text,p_kind text,p_original text,
                p_revision bigint,p_material text,p_state text,p_currency text,p_requested bigint,p_authorized bigint,p_captured bigint,p_refunded bigint,
                p_provider_account text,p_evidence text,p_payload jsonb)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE o public.billium_observed_payment; r public.billium_observed_payment_revision; v_disposition text;
            BEGIN
                IF p_kind IS NULL OR p_kind NOT IN ('charge','refund') OR p_revision IS NULL OR p_revision < 1
                   OR least(p_requested,p_authorized,p_captured,p_refunded) < 0 OR (p_kind = 'refund') IS DISTINCT FROM (p_original IS NOT NULL)
                   OR p_material IS NULL OR p_material !~ '^sha256:[0-9a-f]{64}$'
                THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                SELECT * INTO o FROM public.billium_observed_payment
                    WHERE seller_ref=p_seller AND environment=p_environment AND source_authority=p_authority AND operation_ref=p_operation ORDER BY id LIMIT 1 FOR UPDATE;
                IF NOT FOUND THEN
                    INSERT INTO public.billium_observed_payment(installation_ref,seller_ref,environment,source_authority,source_lineage,operation_ref,kind,original_ref,revision,
                        material_digest,state,currency,requested_minor,authorized_minor,captured_minor,refunded_minor,voided_minor,provider_account_ref,evidence_ref)
                    VALUES(p_installation,p_seller,p_environment,p_authority,p_lineage,p_operation,p_kind,p_original,p_revision,
                        p_material,p_state,p_currency,p_requested,p_authorized,p_captured,p_refunded,0,p_provider_account,p_evidence)
                    ON CONFLICT DO NOTHING RETURNING * INTO o;
                    -- A concurrent first observation of the same identity: retry the whole root (DB-only work).
                    IF NOT FOUND THEN RAISE EXCEPTION 'billium: concurrent observation identity' USING ERRCODE='40001'; END IF;
                    v_disposition := 'new';
                ELSE
                    IF o.installation_ref IS DISTINCT FROM p_installation OR (o.kind,o.currency,o.provider_account_ref) IS DISTINCT FROM (p_kind,p_currency,p_provider_account)
                       OR o.original_ref IS DISTINCT FROM p_original
                    THEN RETURN jsonb_build_object('body',jsonb_build_object('observed_id',o.id,'disposition','contradiction','revision',o.revision),'replayed',false); END IF;
                    SELECT * INTO r FROM public.billium_observed_payment_revision WHERE seller_ref=p_seller AND environment=p_environment AND observed_payment_id=o.id AND revision=p_revision;
                    IF FOUND THEN
                        RETURN jsonb_build_object('body',jsonb_build_object('observed_id',o.id,'revision',o.revision,
                            'disposition',CASE WHEN r.material_digest=p_material THEN 'duplicate' ELSE 'conflict' END),'replayed',true);
                    END IF;
                    IF p_revision > o.revision THEN
                        UPDATE public.billium_observed_payment SET revision=p_revision,material_digest=p_material,state=p_state,requested_minor=p_requested,
                            authorized_minor=p_authorized,captured_minor=p_captured,refunded_minor=p_refunded,evidence_ref=p_evidence WHERE id=o.id;
                        v_disposition := 'advanced';
                    ELSE
                        v_disposition := 'stale'; -- kept as history; the current snapshot never regresses
                    END IF;
                END IF;
                INSERT INTO public.billium_observed_payment_revision(installation_ref,seller_ref,environment,observed_payment_id,revision,material_digest,state,
                    requested_minor,authorized_minor,captured_minor,refunded_minor,voided_minor,currency,evidence_ref,payload)
                VALUES(p_installation,p_seller,p_environment,o.id,p_revision,p_material,p_state,p_requested,p_authorized,p_captured,p_refunded,0,p_currency,p_evidence,coalesce(p_payload,'{}'::jsonb));
                RETURN jsonb_build_object('body',jsonb_build_object('observed_id',o.id,'disposition',v_disposition,'revision',greatest(o.revision,p_revision)),'replayed',false);
            END $$;

            -- Applied projection of one CHARGE revision under the runner's prelocks (intent 65 → obligations 70 → invoices 80 →
            -- payment 90). Modes: assign (ordinary consistent snapshot: totals assigned, D41 refund basis, pro_rata_open/1 parts),
            -- hold (exceptional actual or source discrepancy: last consistent applied totals stay, financial hold, discrepancy),
            -- stale (compatible older revision: no monetary delta). Receipt, revision journal, allocations, discrepancy and
            -- counters commit together (PF-12); the guards check every allocation row.
            CREATE OR REPLACE FUNCTION public.billium_project_payment(
                p_installation text,p_seller text,p_environment text,p_account text,p_currency text,p_observed bigint,p_revision bigint,
                p_payment_ref text,p_intent bigint,p_mode text,p_parts jsonb,p_discrepancy jsonb,p_receipt text,p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                o public.billium_observed_payment; r public.billium_observed_payment_revision; p public.billium_payment; i public.billium_settlement_intent;
                b public.billium_obligation; l record; v_replay public.billium_allocation_receipt; v_body jsonb; v_allocs jsonb := '[]'::jsonb; v_sum numeric := 0;
                v_amount numeric; v_obligations bigint[]; v_discrepancy bigint; v_disc jsonb := NULL;
            BEGIN
                SELECT * INTO o FROM public.billium_observed_payment WHERE id=p_observed AND seller_ref=p_seller AND environment=p_environment;
                IF NOT FOUND OR o.installation_ref IS DISTINCT FROM p_installation OR o.kind <> 'charge' OR o.operation_ref IS DISTINCT FROM p_payment_ref
                THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                IF o.currency IS DISTINCT FROM p_currency THEN RAISE EXCEPTION 'billium.money.currency_mismatch' USING ERRCODE='P0001'; END IF;
                SELECT * INTO r FROM public.billium_observed_payment_revision WHERE seller_ref=p_seller AND environment=p_environment AND observed_payment_id=o.id AND revision=p_revision;
                IF NOT FOUND THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                IF p_mode IS NULL OR p_mode NOT IN ('assign','hold','stale') OR p_receipt IS NULL OR p_receipt = '' OR jsonb_typeof(coalesce(p_parts,'[]'::jsonb)) <> 'array'
                THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                IF p_discrepancy IS NOT NULL AND (p_mode <> 'hold' OR coalesce(p_discrepancy->>'fingerprint','') = '' OR coalesce(p_discrepancy->>'reason','') = ''
                   OR (p_discrepancy->>'expected_minor')::numeric < 0 OR (p_discrepancy->>'actual_minor')::numeric < 0)
                THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                SELECT array_agg((e->>'obligation_id')::bigint) INTO v_obligations FROM jsonb_array_elements(coalesce(p_parts,'[]'::jsonb)) e;
                IF p_intent IS NOT NULL THEN
                    SELECT * INTO i FROM public.billium_settlement_intent WHERE id=p_intent AND seller_ref=p_seller AND environment=p_environment FOR UPDATE;
                    IF NOT FOUND OR (i.installation_ref,i.billing_account_ref,i.currency) IS DISTINCT FROM (p_installation,p_account,p_currency)
                    THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                END IF;
                PERFORM 1 FROM public.billium_obligation WHERE seller_ref=p_seller AND environment=p_environment AND id=ANY(coalesce(v_obligations,'{}')) ORDER BY id FOR UPDATE;
                PERFORM 1 FROM public.billium_invoice WHERE seller_ref=p_seller AND environment=p_environment AND id IN (
                    SELECT billed_invoice_id FROM public.billium_obligation WHERE seller_ref=p_seller AND environment=p_environment AND id=ANY(coalesce(v_obligations,'{}'))) ORDER BY id FOR UPDATE;
                SELECT * INTO p FROM public.billium_payment WHERE seller_ref=p_seller AND environment=p_environment AND payment_ref=p_payment_ref FOR UPDATE;
                -- Replay is decided under the locks: an exact twin returns the original receipt, nothing else moves.
                SELECT * INTO v_replay FROM public.billium_allocation_receipt WHERE seller_ref=p_seller AND environment=p_environment AND receipt_ref=p_receipt;
                IF FOUND THEN
                    IF (v_replay.installation_ref,v_replay.kind,v_replay.semantic_digest) IS DISTINCT FROM (p_installation,'payment_observation'::text,p_digest)
                    THEN RAISE EXCEPTION 'billium.observation.conflict' USING ERRCODE='P0001'; END IF;
                    RETURN jsonb_build_object('body',v_replay.body,'replayed',true);
                END IF;
                IF p.id IS NULL THEN
                    INSERT INTO public.billium_payment(installation_ref,seller_ref,environment,billing_account_ref,currency,payment_ref,source_authority,source_lineage,operation_ref,kind,settlement_intent_id)
                    VALUES(p_installation,p_seller,p_environment,p_account,p_currency,p_payment_ref,o.source_authority,o.source_lineage,o.operation_ref,'charge',p_intent)
                    ON CONFLICT DO NOTHING RETURNING * INTO p;
                    IF NOT FOUND THEN RAISE EXCEPTION 'billium: concurrent payment projection' USING ERRCODE='40001'; END IF;
                ELSIF (p.installation_ref,p.billing_account_ref,p.currency,p.source_authority,p.kind) IS DISTINCT FROM (p_installation,p_account,p_currency,o.source_authority,'charge'::text)
                   OR p.settlement_intent_id IS DISTINCT FROM p_intent
                THEN RAISE EXCEPTION 'billium.observation.conflict' USING ERRCODE='P0001'; END IF;
                -- The account is resolved now; the observed fact learns it once (the guard allows only NULL → value).
                UPDATE public.billium_observed_payment SET billing_account_ref=p_account WHERE id=o.id AND billing_account_ref IS NULL;

                IF p_mode = 'assign' THEN
                    -- Cumulative totals are assigned and never regress; an inconsistent snapshot belongs to mode hold (INV-06, D57).
                    IF p.financial_hold OR p_revision <= p.applied_revision OR r.refunded_minor > r.captured_minor
                       OR r.captured_minor < p.captured_minor OR r.refunded_minor < p.refunded_minor
                    THEN RAISE EXCEPTION 'billium.observation.conflict' USING ERRCODE='P0001'; END IF;
                    UPDATE public.billium_payment SET applied_revision=p_revision,requested_minor=r.requested_minor,authorized_minor=r.authorized_minor,
                        captured_minor=r.captured_minor,refunded_minor=r.refunded_minor,
                        reallocation_required=(allocated_minor::numeric > r.captured_minor::numeric - r.refunded_minor)
                        WHERE id=p.id RETURNING * INTO p;
                ELSIF p_mode = 'hold' THEN
                    UPDATE public.billium_payment SET financial_hold=true,projection_status='held' WHERE id=p.id RETURNING * INTO p;
                ELSIF p_revision > p.applied_revision THEN
                    RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; -- stale means at or below the applied revision
                END IF;

                IF p_mode <> 'assign' AND coalesce(jsonb_array_length(p_parts),0) > 0 THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                FOR l IN SELECT e.value AS value FROM jsonb_array_elements(coalesce(p_parts,'[]'::jsonb)) WITH ORDINALITY e(value, ordinality) ORDER BY e.ordinality LOOP
                    v_amount := (l.value->>'amount_minor')::numeric;
                    IF v_amount IS NULL OR v_amount <= 0 OR v_amount <> trunc(v_amount) THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                    SELECT * INTO b FROM public.billium_obligation WHERE id=(l.value->>'obligation_id')::bigint AND seller_ref=p_seller AND environment=p_environment;
                    IF NOT FOUND THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                    v_allocs := v_allocs || jsonb_build_array(jsonb_build_object('obligation_ref',b.obligation_ref,'amount_minor',v_amount::bigint::text));
                    v_sum := v_sum + v_amount;
                END LOOP;

                IF p_discrepancy IS NOT NULL THEN
                    INSERT INTO public.billium_ledger_discrepancy(installation_ref,seller_ref,environment,fingerprint,comparison_basis_digest,observed_payment_id,observed_revision,
                        charge_revision,refund_revision,expected_minor,actual_minor,hold,reason)
                    VALUES(p_installation,p_seller,p_environment,p_discrepancy->>'fingerprint',r.material_digest,o.id,p_revision,p_revision,NULL,
                        (p_discrepancy->>'expected_minor')::bigint,(p_discrepancy->>'actual_minor')::bigint,true,p_discrepancy->>'reason')
                    ON CONFLICT (seller_ref,environment,fingerprint) DO NOTHING RETURNING id INTO v_discrepancy;
                    IF v_discrepancy IS NOT NULL THEN
                        INSERT INTO public.billium_discrepancy_event(installation_ref,seller_ref,environment,discrepancy_id,event_ref,kind,evidence_ref)
                        VALUES(p_installation,p_seller,p_environment,v_discrepancy,'held:' || (p_discrepancy->>'fingerprint'),'held',r.evidence_ref);
                    END IF;
                    v_disc := jsonb_build_object('fingerprint',p_discrepancy->>'fingerprint','reason',p_discrepancy->>'reason','recorded',v_discrepancy IS NOT NULL,
                        'expected_minor',p_discrepancy->>'expected_minor','actual_minor',p_discrepancy->>'actual_minor','exposure_minor',p_discrepancy->>'exposure_minor');
                END IF;
                v_body := jsonb_build_object('receipt_ref',p_receipt,'payment_ref',p.payment_ref,'operation_ref',o.operation_ref,'revision',p_revision,'mode',p_mode,
                    'billing_account_ref',p_account,'currency',p_currency,'policy_version','pro_rata_open/1',
                    'applied',jsonb_build_object('revision',p.applied_revision,'captured_minor',p.captured_minor::text,'refunded_minor',p.refunded_minor::text),
                    'observed',jsonb_build_object('state',r.state,'captured_minor',r.captured_minor::text,'refunded_minor',r.refunded_minor::text),
                    'allocations',v_allocs,'allocated_minor',(p.allocated_minor::numeric + v_sum)::bigint::text,
                    'unallocated_minor',greatest(p.captured_minor::numeric - p.refunded_minor - p.allocated_minor - v_sum, 0)::bigint::text,
                    'spendable_minor',CASE WHEN p.financial_hold OR p.reallocation_required THEN '0'
                        ELSE greatest(p.captured_minor::numeric - p.refunded_minor - p.allocated_minor - v_sum, 0)::bigint::text END,
                    'financial_hold',p.financial_hold,'reallocation_required',p.reallocation_required,'discrepancy',v_disc);
                PERFORM public.billium_ledger_receipt(p_installation,p_seller,p_environment,p_account,p_currency,p_receipt,'payment_observation',p_digest,v_body);
                IF p_mode = 'assign' THEN
                    INSERT INTO public.billium_payment_revision(installation_ref,seller_ref,environment,billing_account_ref,currency,payment_id,revision,material_digest,
                        requested_minor,authorized_minor,captured_minor,refunded_minor,voided_minor,receipt_ref)
                    VALUES(p_installation,p_seller,p_environment,p_account,p_currency,p.id,p_revision,r.material_digest,r.requested_minor,r.authorized_minor,
                        r.captured_minor,r.refunded_minor,0,p_receipt);
                END IF;
                FOR l IN SELECT e.value AS value FROM jsonb_array_elements(coalesce(p_parts,'[]'::jsonb)) WITH ORDINALITY e(value, ordinality) ORDER BY e.ordinality LOOP
                    INSERT INTO public.billium_allocation(installation_ref,seller_ref,environment,billing_account_ref,currency,obligation_id,payment_id,customer_credit_id,
                        settlement_intent_id,amount_minor,reverses_id,cause,policy_version,receipt_ref)
                    VALUES(p_installation,p_seller,p_environment,p_account,p_currency,(l.value->>'obligation_id')::bigint,p.id,NULL,p.settlement_intent_id,
                        (l.value->>'amount_minor')::bigint,NULL,'observation','pro_rata_open/1',p_receipt);
                END LOOP;
                RETURN jsonb_build_object('body',v_body,'replayed',false);
            END $$;

            -- D41: a refund-child observation changes only its own detail; it is linked to the original charge payment and
            -- receipted in that payment's account, never added to payment.refunded_minor.
            CREATE OR REPLACE FUNCTION public.billium_record_refund_detail(
                p_installation text,p_seller text,p_environment text,p_observed bigint,p_revision bigint,p_payment bigint,p_receipt text,p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE o public.billium_observed_payment; r public.billium_observed_payment_revision; p public.billium_payment; v_replay public.billium_allocation_receipt; v_body jsonb;
            BEGIN
                SELECT * INTO o FROM public.billium_observed_payment WHERE id=p_observed AND seller_ref=p_seller AND environment=p_environment;
                SELECT * INTO p FROM public.billium_payment WHERE id=p_payment AND seller_ref=p_seller AND environment=p_environment;
                IF o.id IS NULL OR p.id IS NULL OR o.kind <> 'refund' OR p.kind <> 'charge' OR o.installation_ref IS DISTINCT FROM p_installation
                   OR o.original_ref IS DISTINCT FROM p.payment_ref OR o.source_authority IS DISTINCT FROM p.source_authority
                THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                IF o.currency IS DISTINCT FROM p.currency THEN RAISE EXCEPTION 'billium.money.currency_mismatch' USING ERRCODE='P0001'; END IF;
                SELECT * INTO r FROM public.billium_observed_payment_revision WHERE seller_ref=p_seller AND environment=p_environment AND observed_payment_id=o.id AND revision=p_revision;
                IF NOT FOUND THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                SELECT * INTO v_replay FROM public.billium_allocation_receipt WHERE seller_ref=p_seller AND environment=p_environment AND receipt_ref=p_receipt;
                IF FOUND THEN
                    IF (v_replay.kind,v_replay.semantic_digest) IS DISTINCT FROM ('refund_detail'::text,p_digest) THEN RAISE EXCEPTION 'billium.observation.conflict' USING ERRCODE='P0001'; END IF;
                    RETURN jsonb_build_object('body',v_replay.body,'replayed',true);
                END IF;
                UPDATE public.billium_observed_payment SET billing_account_ref=p.billing_account_ref WHERE id=o.id AND billing_account_ref IS NULL;
                v_body := jsonb_build_object('receipt_ref',p_receipt,'operation_ref',o.operation_ref,'original_payment_ref',p.payment_ref,'revision',p_revision,'mode','refund_detail',
                    'billing_account_ref',p.billing_account_ref,'currency',p.currency,'state',r.state,'refund_captured_minor',r.captured_minor::text,
                    'charge_refunded_minor',p.refunded_minor::text);
                PERFORM public.billium_ledger_receipt(p_installation,p_seller,p_environment,p.billing_account_ref,p.currency,p_receipt,'refund_detail',p_digest,v_body);
                RETURN jsonb_build_object('body',v_body,'replayed',false);
            END $$;

            -- A missing dependency keeps the observed fact and parks allocation only (D60); the same delivery parks once.
            CREATE OR REPLACE FUNCTION public.billium_park_observation(
                p_installation text,p_seller text,p_environment text,p_observed bigint,p_revision bigint,p_inbox bigint,p_kind text,p_ref text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_id bigint;
            BEGIN
                PERFORM 1 FROM public.billium_observed_payment WHERE id=p_observed AND seller_ref=p_seller AND environment=p_environment AND installation_ref=p_installation;
                IF NOT FOUND THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                INSERT INTO public.billium_observation_park(installation_ref,seller_ref,environment,observed_payment_id,revision,inbox_id,dependency_kind,dependency_ref)
                VALUES(p_installation,p_seller,p_environment,p_observed,p_revision,p_inbox,p_kind,p_ref) ON CONFLICT DO NOTHING RETURNING id INTO v_id;
                IF v_id IS NULL THEN
                    SELECT id INTO v_id FROM public.billium_observation_park WHERE seller_ref=p_seller AND environment=p_environment AND released_at IS NULL
                        AND ((p_inbox IS NOT NULL AND inbox_id=p_inbox) OR (p_inbox IS NULL AND inbox_id IS NULL AND observed_payment_id=p_observed AND revision=p_revision));
                END IF;
                RETURN jsonb_build_object('body',jsonb_build_object('park_id',v_id),'replayed',false);
            END $$;

            -- The dependency retry decided the parked observation (applied, quarantined or superseded): release it once.
            CREATE OR REPLACE FUNCTION public.billium_release_observation_park(p_seller text,p_environment text,p_park bigint,p_inbox bigint,p_reason text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_count integer;
            BEGIN
                IF p_reason IS NULL OR p_reason = '' THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                UPDATE public.billium_observation_park SET released_at=now(),release_reason=p_reason
                    WHERE seller_ref=p_seller AND environment=p_environment AND released_at IS NULL AND (id=p_park OR (p_inbox IS NOT NULL AND inbox_id=p_inbox));
                GET DIAGNOSTICS v_count = ROW_COUNT;
                RETURN jsonb_build_object('body',jsonb_build_object('released',v_count),'replayed',false);
            END $$;

            -- D48 reconcile after the consistency window: the charge refunded basis against the ordered succeeded refund
            -- details. The basis digest names the charge revision and every contributing refund revision/amount; an identical
            -- basis dedupes by fingerprint, a new basis is a new row, agreement appends resolution events. The ledger and the
            -- financial hold never change here.
            CREATE OR REPLACE FUNCTION public.billium_reconcile_refund_basis(
                p_installation text,p_seller text,p_environment text,p_payment bigint,p_charge_revision bigint,p_basis text,p_refund_revision bigint,
                p_charge_refunded bigint,p_detail_refunded bigint,p_evidence text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE p public.billium_payment; o public.billium_observed_payment; d public.billium_ledger_discrepancy; v_fingerprint text; v_id bigint; v_resolved jsonb := '[]'::jsonb;
            BEGIN
                IF p_basis IS NULL OR p_basis !~ '^sha256:[0-9a-f]{64}$' OR least(p_charge_refunded,p_detail_refunded) < 0 OR p_evidence IS NULL OR p_evidence = ''
                THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                SELECT * INTO p FROM public.billium_payment WHERE id=p_payment AND seller_ref=p_seller AND environment=p_environment FOR UPDATE;
                IF NOT FOUND OR p.installation_ref IS DISTINCT FROM p_installation OR p.kind <> 'charge' THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                -- The comparison was read before the lock: a moved charge basis is a new comparison, not this one.
                IF p.applied_revision IS DISTINCT FROM p_charge_revision OR p.refunded_minor IS DISTINCT FROM p_charge_refunded
                THEN RAISE EXCEPTION 'billium: refund basis moved' USING ERRCODE='40001'; END IF;
                SELECT * INTO o FROM public.billium_observed_payment WHERE seller_ref=p_seller AND environment=p_environment AND source_authority=p.source_authority AND operation_ref=p.operation_ref ORDER BY id LIMIT 1;
                IF NOT FOUND THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                v_fingerprint := 'refund_basis:' || p.payment_ref || ':' || p_basis;
                IF p_charge_refunded <> p_detail_refunded THEN
                    INSERT INTO public.billium_ledger_discrepancy(installation_ref,seller_ref,environment,fingerprint,comparison_basis_digest,observed_payment_id,observed_revision,
                        charge_revision,refund_revision,expected_minor,actual_minor,hold,reason)
                    VALUES(p_installation,p_seller,p_environment,v_fingerprint,p_basis,o.id,p.applied_revision,p.applied_revision,p_refund_revision,p_charge_refunded,p_detail_refunded,false,'refund_basis_mismatch')
                    ON CONFLICT (seller_ref,environment,fingerprint) DO NOTHING RETURNING id INTO v_id;
                    RETURN jsonb_build_object('body',jsonb_build_object('payment_ref',p.payment_ref,'fingerprint',v_fingerprint,'recorded',v_id IS NOT NULL,'discrepancy_id',v_id,'resolved',v_resolved),'replayed',v_id IS NULL);
                END IF;
                FOR d IN SELECT x.* FROM public.billium_ledger_discrepancy x WHERE x.seller_ref=p_seller AND x.environment=p_environment AND x.observed_payment_id=o.id
                    AND x.reason='refund_basis_mismatch' AND NOT EXISTS (SELECT FROM public.billium_discrepancy_event e WHERE e.seller_ref=p_seller AND e.environment=p_environment AND e.discrepancy_id=x.id AND e.kind='resolved')
                    ORDER BY x.id
                LOOP
                    INSERT INTO public.billium_discrepancy_event(installation_ref,seller_ref,environment,discrepancy_id,event_ref,kind,evidence_ref)
                    VALUES(p_installation,p_seller,p_environment,d.id,'resolved:' || d.fingerprint || ':' || p_basis,'resolved',p_evidence);
                    v_resolved := v_resolved || jsonb_build_array(d.fingerprint);
                END LOOP;
                RETURN jsonb_build_object('body',jsonb_build_object('payment_ref',p.payment_ref,'fingerprint',v_fingerprint,'recorded',false,'discrepancy_id',NULL,'resolved',v_resolved),'replayed',false);
            END $$;

            DO $$ DECLARE f regprocedure; BEGIN
                FOR f IN SELECT p.oid::regprocedure FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                    WHERE n.nspname='public' AND p.proname IN ('billium_record_observation','billium_project_payment','billium_record_refund_detail',
                        'billium_reconcile_refund_basis','billium_park_observation','billium_release_observation_park')
                LOOP EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC',f); END LOOP;
            END $$;
            SQL);
        app(LedgerPrivileges::class)->grant();
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

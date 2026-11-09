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
            ALTER TABLE public.billium_observation_park DROP CONSTRAINT billium_observation_park_dependency_kind_check;
            ALTER TABLE public.billium_observation_park ADD CONSTRAINT billium_observation_park_dependency_kind_check
                CHECK (dependency_kind IN ('settlement_intent','original_charge','operation_revision')) NOT VALID;

            -- Keep contradictory charge bindings as immutable evidence without replacing the original source identity.
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
                    IF NOT FOUND THEN RAISE EXCEPTION 'billium: concurrent observation identity' USING ERRCODE='40001'; END IF;
                    v_disposition := 'new';
                ELSE
                    SELECT * INTO r FROM public.billium_observed_payment_revision WHERE seller_ref=p_seller AND environment=p_environment AND observed_payment_id=o.id AND revision=p_revision;
                    IF FOUND THEN
                        RETURN jsonb_build_object('body',jsonb_build_object('observed_id',o.id,'revision',o.revision,
                            'disposition',CASE WHEN r.material_digest<>p_material THEN 'conflict'
                                WHEN (o.installation_ref,o.kind,o.currency,o.provider_account_ref,o.original_ref)
                                    IS DISTINCT FROM (p_installation,p_kind,p_currency,p_provider_account,p_original) THEN 'contradiction'
                                ELSE 'duplicate' END),'replayed',true);
                    END IF;
                    IF o.installation_ref IS DISTINCT FROM p_installation OR o.kind IS DISTINCT FROM p_kind OR o.original_ref IS DISTINCT FROM p_original
                    THEN RETURN jsonb_build_object('body',jsonb_build_object('observed_id',o.id,'disposition','conflict','revision',o.revision),'replayed',false); END IF;
                    IF (o.currency,o.provider_account_ref) IS DISTINCT FROM (p_currency,p_provider_account) THEN
                        IF o.kind <> 'charge'
                        THEN RETURN jsonb_build_object('body',jsonb_build_object('observed_id',o.id,'disposition','conflict','revision',o.revision),'replayed',false); END IF;
                        v_disposition := 'contradiction';
                    ELSIF p_revision > o.revision THEN
                        UPDATE public.billium_observed_payment SET revision=p_revision,material_digest=p_material,state=p_state,requested_minor=p_requested,
                            authorized_minor=p_authorized,captured_minor=p_captured,refunded_minor=p_refunded,evidence_ref=p_evidence WHERE id=o.id;
                        v_disposition := 'advanced';
                    ELSE
                        v_disposition := 'stale';
                    END IF;
                END IF;
                INSERT INTO public.billium_observed_payment_revision(installation_ref,seller_ref,environment,observed_payment_id,revision,material_digest,state,
                    requested_minor,authorized_minor,captured_minor,refunded_minor,voided_minor,currency,evidence_ref,payload)
                VALUES(p_installation,p_seller,p_environment,o.id,p_revision,p_material,p_state,p_requested,p_authorized,p_captured,p_refunded,0,p_currency,p_evidence,
                    CASE WHEN p_provider_account IS NULL THEN coalesce(p_payload,'{}'::jsonb)
                        ELSE jsonb_set(coalesce(p_payload,'{}'::jsonb), '{providerAccountRef}', to_jsonb(p_provider_account), true) END);
                RETURN jsonb_build_object('body',jsonb_build_object('observed_id',o.id,'disposition',v_disposition,
                    'revision',CASE WHEN v_disposition='contradiction' THEN o.revision ELSE greatest(o.revision,p_revision) END),'replayed',false);
            END $$;
            REVOKE ALL ON FUNCTION public.billium_record_observation(text,text,text,text,text,text,text,text,bigint,text,text,text,bigint,bigint,bigint,bigint,text,text,jsonb) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.billium_project_payment(
                p_installation text,p_seller text,p_environment text,p_account text,p_currency text,p_observed bigint,p_revision bigint,
                p_payment_ref text,p_intent bigint,p_mode text,p_parts jsonb,p_discrepancy jsonb,p_receipt text,p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                o public.billium_observed_payment; r public.billium_observed_payment_revision; p public.billium_payment; i public.billium_settlement_intent;
                b public.billium_obligation; l record; v_replay public.billium_allocation_receipt; v_body jsonb; v_allocs jsonb := '[]'::jsonb; v_sum numeric := 0;
                v_applied_state text; v_amount numeric; v_obligations bigint[]; v_discrepancy bigint; v_disc jsonb := NULL;
            BEGIN
                SELECT * INTO o FROM public.billium_observed_payment WHERE id=p_observed AND seller_ref=p_seller AND environment=p_environment;
                IF NOT FOUND OR o.installation_ref IS DISTINCT FROM p_installation OR o.kind <> 'charge' OR o.operation_ref IS DISTINCT FROM p_payment_ref
                THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                IF o.currency IS DISTINCT FROM p_currency THEN RAISE EXCEPTION 'billium.money.currency_mismatch' USING ERRCODE='P0001'; END IF;
                SELECT * INTO r FROM public.billium_observed_payment_revision WHERE seller_ref=p_seller AND environment=p_environment AND observed_payment_id=o.id AND revision=p_revision;
                IF NOT FOUND THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                IF r.material_digest IS DISTINCT FROM p_digest THEN RAISE EXCEPTION 'billium.observation.conflict' USING ERRCODE='P0001'; END IF;
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
                SELECT * INTO p FROM public.billium_payment WHERE seller_ref=p_seller AND environment=p_environment AND source_authority=o.source_authority AND payment_ref=p_payment_ref FOR UPDATE;
                -- Replay is decided under the locks: an exact twin returns the original receipt, nothing else moves.
                SELECT * INTO v_replay FROM public.billium_allocation_receipt WHERE seller_ref=p_seller AND environment=p_environment AND (receipt_ref=p_receipt OR (kind='payment_observation' AND semantic_digest=p_digest)) ORDER BY id LIMIT 1;
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

                IF p_mode IN ('assign','stale') THEN
                    -- Every immutable fact, including parked history, constrains assign and stale modes.
                    IF r.currency IS DISTINCT FROM o.currency
                       OR coalesce(r.payload->>'providerAccountRef', o.provider_account_ref) IS DISTINCT FROM o.provider_account_ref
                       OR EXISTS (SELECT 1 FROM public.billium_observed_payment_revision rc
                           WHERE rc.observed_payment_id=o.id AND (rc.currency IS DISTINCT FROM o.currency
                               OR coalesce(rc.payload->>'providerAccountRef', o.provider_account_ref) IS DISTINCT FROM o.provider_account_ref))
                       OR r.refunded_minor > r.captured_minor OR r.captured_minor > r.authorized_minor
                       OR EXISTS (SELECT 1 FROM public.billium_observed_payment_revision prev
                           WHERE prev.observed_payment_id=r.observed_payment_id AND prev.revision<r.revision
                             AND (r.captured_minor<prev.captured_minor OR r.refunded_minor<prev.refunded_minor
                                  OR (prev.state IN ('succeeded','failed','cancelled') AND r.state<>prev.state)))
                       OR EXISTS (SELECT 1 FROM public.billium_observed_payment_revision succ
                           WHERE succ.observed_payment_id=r.observed_payment_id AND succ.revision>r.revision
                             AND (r.captured_minor>succ.captured_minor OR r.refunded_minor>succ.refunded_minor
                                  OR (r.state IN ('succeeded','failed','cancelled') AND succ.state<>r.state)))
                       OR EXISTS (SELECT 1 FROM public.billium_observed_payment_revision r1
                           JOIN public.billium_observed_payment_revision r2
                             ON r2.observed_payment_id=r1.observed_payment_id AND r2.revision>r1.revision
                           WHERE r1.observed_payment_id=r.observed_payment_id
                             AND (r2.captured_minor<r1.captured_minor OR r2.refunded_minor<r1.refunded_minor
                                  OR (r1.state IN ('succeeded','failed','cancelled') AND r2.state<>r1.state)))
                    THEN RAISE EXCEPTION 'billium.observation.conflict' USING ERRCODE='P0001'; END IF;
                END IF;

                IF p_mode = 'assign' THEN
                    -- The immutable applied revision remains a bound in addition to observed neighbors above.
                    SELECT state INTO v_applied_state FROM public.billium_observed_payment_revision
                        WHERE observed_payment_id=o.id AND revision=p.applied_revision
                          AND seller_ref=p_seller AND environment=p_environment;
                    -- Cumulative totals are assigned and never regress; an inconsistent snapshot belongs to mode hold (INV-06, D57).
                    IF p.financial_hold OR p_revision <= p.applied_revision OR r.refunded_minor > r.captured_minor
                       OR r.captured_minor > r.authorized_minor
                       OR (v_applied_state IN ('succeeded','failed','cancelled') AND r.state IS DISTINCT FROM v_applied_state)
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
            REVOKE ALL ON FUNCTION public.billium_project_payment(text,text,text,text,text,bigint,bigint,text,bigint,text,jsonb,jsonb,text,text) FROM PUBLIC;

            DROP FUNCTION IF EXISTS public.billium_record_refund_detail(text,text,text,bigint,bigint,bigint,text,text);
            DROP FUNCTION IF EXISTS public.billium_record_refund_detail(text,text,text,bigint,bigint,bigint,text,text,jsonb);
            CREATE OR REPLACE FUNCTION public.billium_record_refund_detail(
                p_installation text,p_seller text,p_environment text,p_observed bigint,p_revision bigint,p_payment bigint,p_receipt text,p_digest text,
                p_discrepancy jsonb DEFAULT NULL)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                o public.billium_observed_payment; r public.billium_observed_payment_revision; p public.billium_payment;
                v_replay public.billium_allocation_receipt; v_body jsonb; v_discrepancy bigint; v_disc jsonb := NULL;
                v_existing public.billium_ledger_discrepancy;
            BEGIN
                SELECT * INTO p FROM public.billium_payment WHERE id=p_payment AND seller_ref=p_seller AND environment=p_environment FOR UPDATE;
                SELECT * INTO o FROM public.billium_observed_payment WHERE id=p_observed AND seller_ref=p_seller AND environment=p_environment FOR UPDATE;
                IF o.id IS NULL OR p.id IS NULL OR o.kind <> 'refund' OR p.kind <> 'charge'
                   OR o.installation_ref IS DISTINCT FROM p_installation OR p.installation_ref IS DISTINCT FROM p_installation
                   OR o.original_ref IS DISTINCT FROM p.operation_ref OR o.source_authority IS DISTINCT FROM p.source_authority
                THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                SELECT * INTO r FROM public.billium_observed_payment_revision
                    WHERE seller_ref=p_seller AND environment=p_environment AND observed_payment_id=o.id AND revision=p_revision;
                IF NOT FOUND THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                IF p.currency IS DISTINCT FROM o.currency OR p.currency IS DISTINCT FROM r.currency
                THEN RAISE EXCEPTION 'billium.money.currency_mismatch' USING ERRCODE='P0001'; END IF;
                IF r.material_digest IS DISTINCT FROM p_digest THEN RAISE EXCEPTION 'billium.observation.conflict' USING ERRCODE='P0001'; END IF;
                SELECT * INTO v_replay FROM public.billium_allocation_receipt WHERE seller_ref=p_seller AND environment=p_environment
                    AND (receipt_ref=p_receipt OR (kind='refund_detail' AND semantic_digest=p_digest)) ORDER BY id LIMIT 1;
                IF FOUND THEN
                    IF (v_replay.installation_ref,v_replay.kind,v_replay.semantic_digest) IS DISTINCT FROM (p_installation,'refund_detail'::text,p_digest)
                    THEN RAISE EXCEPTION 'billium.observation.conflict' USING ERRCODE='P0001'; END IF;
                    RETURN jsonb_build_object('body',v_replay.body,'replayed',true);
                END IF;
                -- Observed totals remain provider facts. A validated receipt, rather than the mutable observation,
                -- is the applied refund-detail basis; a held revision must never enter that basis.
                IF o.billing_account_ref IS NULL THEN
                    UPDATE public.billium_observed_payment SET billing_account_ref=p.billing_account_ref WHERE id=o.id RETURNING * INTO o;
                END IF;
                IF o.billing_account_ref IS DISTINCT FROM p.billing_account_ref
                THEN RAISE EXCEPTION 'billium.observation.conflict' USING ERRCODE='P0001'; END IF;
                IF p_discrepancy IS NULL THEN
                    IF r.refunded_minor > r.captured_minor OR r.captured_minor > r.authorized_minor
                       OR EXISTS (SELECT 1 FROM public.billium_observed_payment_revision r1
                           JOIN public.billium_observed_payment_revision r2
                             ON r2.observed_payment_id=r1.observed_payment_id AND r2.revision>r1.revision
                           WHERE r1.observed_payment_id=o.id
                             AND (r2.captured_minor<r1.captured_minor OR r2.refunded_minor<r1.refunded_minor
                                  OR (r1.state IN ('succeeded','failed','cancelled') AND r2.state<>r1.state)))
                       OR EXISTS (SELECT 1 FROM public.billium_ledger_discrepancy d WHERE d.observed_payment_id=o.id AND d.hold)
                    THEN RAISE EXCEPTION 'billium.observation.conflict' USING ERRCODE='P0001'; END IF;
                ELSE
                    IF jsonb_typeof(p_discrepancy) IS DISTINCT FROM 'object'
                       OR coalesce(p_discrepancy->>'fingerprint','') = '' OR coalesce(p_discrepancy->>'reason','') = ''
                       OR coalesce(p_discrepancy->>'expected_minor','') !~ '^[0-9]+$'
                       OR coalesce(p_discrepancy->>'actual_minor','') !~ '^[0-9]+$'
                    THEN RAISE EXCEPTION 'billium.observation.conflict' USING ERRCODE='P0001'; END IF;
                    UPDATE public.billium_payment SET financial_hold=true,projection_status='held' WHERE id=p.id RETURNING * INTO p;
                    INSERT INTO public.billium_ledger_discrepancy(installation_ref,seller_ref,environment,fingerprint,comparison_basis_digest,
                        observed_payment_id,observed_revision,charge_revision,refund_revision,expected_minor,actual_minor,hold,reason)
                    VALUES(p_installation,p_seller,p_environment,p_discrepancy->>'fingerprint',r.material_digest,o.id,p_revision,
                        CASE WHEN p.applied_revision>0 THEN p.applied_revision ELSE NULL END,p_revision,
                        (p_discrepancy->>'expected_minor')::bigint,(p_discrepancy->>'actual_minor')::bigint,true,p_discrepancy->>'reason')
                    ON CONFLICT (seller_ref,environment,fingerprint) DO NOTHING RETURNING id INTO v_discrepancy;
                    IF v_discrepancy IS NOT NULL THEN
                        INSERT INTO public.billium_discrepancy_event(installation_ref,seller_ref,environment,discrepancy_id,event_ref,kind,evidence_ref)
                        VALUES(p_installation,p_seller,p_environment,v_discrepancy,'held:' || (p_discrepancy->>'fingerprint'),'held',r.evidence_ref);
                    ELSE
                        SELECT * INTO v_existing FROM public.billium_ledger_discrepancy
                            WHERE seller_ref=p_seller AND environment=p_environment AND fingerprint=p_discrepancy->>'fingerprint';
                        IF (v_existing.installation_ref,v_existing.observed_payment_id,v_existing.observed_revision,v_existing.comparison_basis_digest,
                            v_existing.reason,v_existing.expected_minor,v_existing.actual_minor,v_existing.hold)
                           IS DISTINCT FROM (p_installation,o.id,p_revision,r.material_digest,p_discrepancy->>'reason',
                            (p_discrepancy->>'expected_minor')::bigint,(p_discrepancy->>'actual_minor')::bigint,true)
                        THEN RAISE EXCEPTION 'billium.observation.conflict' USING ERRCODE='P0001'; END IF;
                    END IF;
                    v_disc := jsonb_build_object('fingerprint',p_discrepancy->>'fingerprint','reason',p_discrepancy->>'reason',
                        'recorded',v_discrepancy IS NOT NULL,'expected_minor',p_discrepancy->>'expected_minor',
                        'actual_minor',p_discrepancy->>'actual_minor','exposure_minor',p_discrepancy->>'exposure_minor');
                END IF;
                v_body := jsonb_build_object('receipt_ref',p_receipt,'operation_ref',o.operation_ref,'original_payment_ref',p.payment_ref,
                    'revision',p_revision,'mode',CASE WHEN p_discrepancy IS NULL THEN 'refund_detail' ELSE 'hold' END,
                    'billing_account_ref',p.billing_account_ref,'currency',p.currency,'state',r.state,'refund_captured_minor',r.captured_minor::text,
                    'charge_refunded_minor',p.refunded_minor::text,'financial_hold',p.financial_hold,'discrepancy',v_disc);
                PERFORM public.billium_ledger_receipt(p_installation,p_seller,p_environment,p.billing_account_ref,p.currency,p_receipt,'refund_detail',p_digest,v_body);
                RETURN jsonb_build_object('body',v_body,'replayed',false);
            END $$;
            REVOKE ALL ON FUNCTION public.billium_record_refund_detail(text,text,text,bigint,bigint,bigint,text,text,jsonb) FROM PUBLIC;
            SQL);
        app(LedgerPrivileges::class)->grant();
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

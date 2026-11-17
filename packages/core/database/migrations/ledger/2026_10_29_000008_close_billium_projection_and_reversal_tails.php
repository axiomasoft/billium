<?php

declare(strict_types=1);

use Billium\Ledger\Internal\Repositories\LedgerPrivileges;
use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Support\Facades\DB;

return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- P3 tails: an over-capture cannot enter the applied projection through the granted routine.
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

                IF p_mode = 'assign' THEN
                    -- Cumulative totals are assigned and never regress; an inconsistent snapshot belongs to mode hold (INV-06, D57).
                    IF p.financial_hold OR p_revision <= p.applied_revision OR r.refunded_minor > r.captured_minor
                       OR r.captured_minor > r.authorized_minor
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

            -- Partial reversals return the positive remainder's immutable journal id, independent of receipt prefixes.
            CREATE OR REPLACE FUNCTION public.billium_reverse_allocation(
                p_installation text,p_seller text,p_environment text,p_account text,p_currency text,
                p_allocation bigint,p_ref text,p_remainder bigint,p_cause text,p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE a public.billium_allocation; v_receipt text := 'reversal:' || p_ref; v_replay jsonb; v_body jsonb; v_obligation text; v_reversal_id bigint; v_remainder_id bigint;
            BEGIN
                SELECT * INTO a FROM public.billium_allocation WHERE id=p_allocation AND seller_ref=p_seller AND environment=p_environment;
                IF NOT FOUND OR (a.installation_ref,a.billing_account_ref,a.currency) IS DISTINCT FROM (p_installation,p_account,p_currency)
                THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                PERFORM public.billium_ledger_lock_scope(p_seller,p_environment,array_remove(ARRAY[a.settlement_intent_id],NULL),ARRAY[a.obligation_id],'{}',
                    array_remove(ARRAY[a.payment_id],NULL),array_remove(ARRAY[a.customer_credit_id],NULL));
                v_replay := public.billium_invoice_replay(p_installation,p_seller,p_environment,p_account,p_currency,v_receipt,'allocation_reversal',p_digest);
                IF v_replay IS NOT NULL THEN RETURN jsonb_build_object('body',v_replay,'replayed',true); END IF;
                IF a.reverses_id IS NOT NULL OR a.amount_minor <= 0 OR p_remainder IS NULL OR p_remainder < 0 OR p_remainder >= a.amount_minor OR p_cause IS NULL OR p_cause = ''
                THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                -- UNIQUE original reversal: another identity cannot reverse the same row again.
                IF EXISTS (SELECT FROM public.billium_allocation WHERE seller_ref=p_seller AND environment=p_environment AND reverses_id=a.id)
                THEN RAISE EXCEPTION 'billium.idempotency.conflict' USING ERRCODE='P0001'; END IF;
                SELECT obligation_ref INTO v_obligation FROM public.billium_obligation WHERE id=a.obligation_id;
                -- Reserve journal identities in append order before the receipt (the rows require that receipt).
                v_reversal_id := nextval(pg_get_serial_sequence('public.billium_allocation','id'));
                IF p_remainder > 0 THEN
                    v_remainder_id := nextval(pg_get_serial_sequence('public.billium_allocation','id'));
                END IF;
                v_body := jsonb_build_object('receipt_ref',v_receipt,'reversal_ref',p_ref,'reverses_receipt_ref',a.receipt_ref,'obligation_ref',v_obligation,
                    'reversed_minor',a.amount_minor::text,'remainder_minor',p_remainder::text,'remainder_allocation_ref',v_remainder_id,'cause',p_cause,'policy_version',a.policy_version);
                PERFORM public.billium_ledger_receipt(p_installation,p_seller,p_environment,p_account,p_currency,v_receipt,'allocation_reversal',p_digest,v_body);
                INSERT INTO public.billium_allocation(id,installation_ref,seller_ref,environment,billing_account_ref,currency,obligation_id,payment_id,customer_credit_id,settlement_intent_id,amount_minor,reverses_id,cause,policy_version,receipt_ref)
                OVERRIDING SYSTEM VALUE
                VALUES(v_reversal_id,p_installation,p_seller,p_environment,p_account,p_currency,a.obligation_id,a.payment_id,a.customer_credit_id,a.settlement_intent_id,-a.amount_minor,a.id,p_cause,a.policy_version,v_receipt);
                IF p_remainder > 0 THEN
                    INSERT INTO public.billium_allocation(id,installation_ref,seller_ref,environment,billing_account_ref,currency,obligation_id,payment_id,customer_credit_id,settlement_intent_id,amount_minor,reverses_id,cause,policy_version,receipt_ref)
                    OVERRIDING SYSTEM VALUE
                    VALUES(v_remainder_id,p_installation,p_seller,p_environment,p_account,p_currency,a.obligation_id,a.payment_id,a.customer_credit_id,a.settlement_intent_id,p_remainder,NULL,p_cause,a.policy_version,v_receipt);
                END IF;
                RETURN jsonb_build_object('body',v_body,'replayed',false);
            END $$;
            SQL);
        app(LedgerPrivileges::class)->grant();
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

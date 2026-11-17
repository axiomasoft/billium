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
            -- P3.8: guarded reversal, credit note and refund reallocation routines (INV-09, D35, D60). Every journal row
            -- goes through billium_allocation_guard / billium_credit_note_guard, which alone change the counters.
            -- Locks follow D27: settlement intents (65) → obligations (70) → invoices (80) → payments, then customer
            -- credits (90), ids ascending. The runner prelocks the same rows, so these acquisitions are reentrant.
            -- Replay is decided after the locks: a concurrent twin waits and then replays.
            CREATE OR REPLACE FUNCTION public.billium_ledger_lock_scope(
                p_seller text,p_environment text,p_intents bigint[],p_obligations bigint[],p_invoices bigint[],p_payments bigint[],p_credits bigint[])
            RETURNS void LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                PERFORM 1 FROM public.billium_settlement_intent WHERE seller_ref=p_seller AND environment=p_environment AND id=ANY(p_intents) ORDER BY id FOR UPDATE;
                PERFORM 1 FROM public.billium_obligation WHERE seller_ref=p_seller AND environment=p_environment AND id=ANY(p_obligations) ORDER BY id FOR UPDATE;
                PERFORM 1 FROM public.billium_invoice WHERE seller_ref=p_seller AND environment=p_environment AND (id=ANY(p_invoices) OR id IN (
                    SELECT billed_invoice_id FROM public.billium_obligation WHERE seller_ref=p_seller AND environment=p_environment AND id=ANY(p_obligations))) ORDER BY id FOR UPDATE;
                PERFORM 1 FROM public.billium_payment WHERE seller_ref=p_seller AND environment=p_environment AND id=ANY(p_payments) ORDER BY id FOR UPDATE;
                PERFORM 1 FROM public.billium_customer_credit WHERE seller_ref=p_seller AND environment=p_environment AND id=ANY(p_credits) ORDER BY id FOR UPDATE;
            END $$;

            -- LIFO release of live allocations of one obligation: each one is reversed in full (UNIQUE reverses_id) and the
            -- overshoot of the last one is placed again from the same source (COV-289). Returns the released journal.
            CREATE OR REPLACE FUNCTION public.billium_ledger_release(
                p_installation text,p_seller text,p_environment text,p_account text,p_currency text,
                p_obligation bigint,p_payment bigint,p_need numeric,p_cause text,p_receipt text,p_apply boolean)
            RETURNS jsonb LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE a public.billium_allocation; v_need numeric := p_need; v_rest numeric; v_rows jsonb := '[]'::jsonb; v_obligation text;
            BEGIN
                FOR a IN SELECT x.* FROM public.billium_allocation x
                    WHERE x.seller_ref=p_seller AND x.environment=p_environment AND x.reverses_id IS NULL AND x.amount_minor>0
                      AND (p_obligation IS NULL OR x.obligation_id=p_obligation) AND (p_payment IS NULL OR x.payment_id=p_payment)
                      AND NOT EXISTS (SELECT FROM public.billium_allocation r WHERE r.seller_ref=p_seller AND r.environment=p_environment AND r.reverses_id=x.id)
                    ORDER BY x.id DESC
                LOOP
                    EXIT WHEN v_need <= 0;
                    v_rest := greatest(a.amount_minor::numeric - v_need, 0);
                    SELECT obligation_ref INTO v_obligation FROM public.billium_obligation WHERE id=a.obligation_id;
                    v_rows := v_rows || jsonb_build_array(jsonb_build_object('obligation_ref',v_obligation,'reverses_receipt_ref',a.receipt_ref,
                        'reversed_minor',a.amount_minor::text,'remainder_minor',v_rest::bigint::text,'reopened_minor',(a.amount_minor::numeric - v_rest)::bigint::text));
                    IF p_apply THEN
                        INSERT INTO public.billium_allocation(installation_ref,seller_ref,environment,billing_account_ref,currency,obligation_id,payment_id,customer_credit_id,settlement_intent_id,amount_minor,reverses_id,cause,policy_version,receipt_ref)
                        VALUES(p_installation,p_seller,p_environment,p_account,p_currency,a.obligation_id,a.payment_id,a.customer_credit_id,a.settlement_intent_id,-a.amount_minor,a.id,p_cause,a.policy_version,p_receipt);
                        IF v_rest > 0 THEN
                            INSERT INTO public.billium_allocation(installation_ref,seller_ref,environment,billing_account_ref,currency,obligation_id,payment_id,customer_credit_id,settlement_intent_id,amount_minor,reverses_id,cause,policy_version,receipt_ref)
                            VALUES(p_installation,p_seller,p_environment,p_account,p_currency,a.obligation_id,a.payment_id,a.customer_credit_id,a.settlement_intent_id,v_rest::bigint,NULL,p_cause,a.policy_version,p_receipt);
                        END IF;
                    END IF;
                    v_need := v_need - a.amount_minor;
                END LOOP;
                -- Materialized counters equal the journal; a shortfall means they diverged and nothing is written.
                IF v_need > 0 THEN RAISE EXCEPTION 'billium.allocation.exceeds_available' USING ERRCODE='P0001'; END IF;
                RETURN v_rows;
            END $$;

            CREATE OR REPLACE FUNCTION public.billium_reverse_allocation(
                p_installation text,p_seller text,p_environment text,p_account text,p_currency text,
                p_allocation bigint,p_ref text,p_remainder bigint,p_cause text,p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE a public.billium_allocation; v_receipt text := 'reversal:' || p_ref; v_replay jsonb; v_body jsonb; v_obligation text;
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
                v_body := jsonb_build_object('receipt_ref',v_receipt,'reversal_ref',p_ref,'reverses_receipt_ref',a.receipt_ref,'obligation_ref',v_obligation,
                    'reversed_minor',a.amount_minor::text,'remainder_minor',p_remainder::text,'cause',p_cause,'policy_version',a.policy_version);
                PERFORM public.billium_ledger_receipt(p_installation,p_seller,p_environment,p_account,p_currency,v_receipt,'allocation_reversal',p_digest,v_body);
                INSERT INTO public.billium_allocation(installation_ref,seller_ref,environment,billing_account_ref,currency,obligation_id,payment_id,customer_credit_id,settlement_intent_id,amount_minor,reverses_id,cause,policy_version,receipt_ref)
                VALUES(p_installation,p_seller,p_environment,p_account,p_currency,a.obligation_id,a.payment_id,a.customer_credit_id,a.settlement_intent_id,-a.amount_minor,a.id,p_cause,a.policy_version,v_receipt);
                IF p_remainder > 0 THEN
                    INSERT INTO public.billium_allocation(installation_ref,seller_ref,environment,billing_account_ref,currency,obligation_id,payment_id,customer_credit_id,settlement_intent_id,amount_minor,reverses_id,cause,policy_version,receipt_ref)
                    VALUES(p_installation,p_seller,p_environment,p_account,p_currency,a.obligation_id,a.payment_id,a.customer_credit_id,a.settlement_intent_id,p_remainder,NULL,p_cause,a.policy_version,v_receipt);
                END IF;
                RETURN jsonb_build_object('body',v_body,'replayed',false);
            END $$;

            -- open_only credits at most the open amount; reverse_allocations first releases live allocations (LIFO) and never
            -- credits beyond due + adjusted − credited. Lines: [{obligation_id, amount_minor}] of obligations of the invoice.
            CREATE OR REPLACE FUNCTION public.billium_issue_credit_note(
                p_installation text,p_seller text,p_environment text,p_account text,p_currency text,
                p_invoice bigint,p_ref text,p_mode text,p_lines jsonb,p_reason text,p_legal text,p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                i public.billium_invoice; o public.billium_obligation; l record; v_receipt text := 'credit-note:' || p_ref; v_replay jsonb; v_body jsonb;
                v_ids bigint[]; v_intents bigint[]; v_payments bigint[]; v_credits bigint[]; v_lines jsonb := '[]'::jsonb; v_amount numeric; v_open numeric;
                v_count integer; v_note text; v_released jsonb;
            BEGIN
                IF p_lines IS NULL OR jsonb_typeof(p_lines) <> 'array' OR jsonb_array_length(p_lines) = 0 OR jsonb_array_length(p_lines) > 500
                THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                SELECT array_agg((e.value->>'obligation_id')::bigint ORDER BY e.ordinality) INTO v_ids FROM jsonb_array_elements(p_lines) WITH ORDINALITY e(value, ordinality);
                IF array_position(v_ids, NULL) IS NOT NULL OR cardinality(v_ids) <> (SELECT count(DISTINCT x) FROM unnest(v_ids) x)
                THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                IF p_mode = 'reverse_allocations' THEN
                    SELECT array_agg(DISTINCT settlement_intent_id) FILTER (WHERE settlement_intent_id IS NOT NULL), array_agg(DISTINCT payment_id) FILTER (WHERE payment_id IS NOT NULL),
                           array_agg(DISTINCT customer_credit_id) FILTER (WHERE customer_credit_id IS NOT NULL)
                      INTO v_intents, v_payments, v_credits
                      FROM public.billium_allocation WHERE seller_ref=p_seller AND environment=p_environment AND obligation_id=ANY(v_ids);
                END IF;
                PERFORM public.billium_ledger_lock_scope(p_seller,p_environment,coalesce(v_intents,'{}'),v_ids,ARRAY[p_invoice],coalesce(v_payments,'{}'),coalesce(v_credits,'{}'));
                v_replay := public.billium_invoice_replay(p_installation,p_seller,p_environment,p_account,p_currency,v_receipt,'credit_note',p_digest);
                IF v_replay IS NOT NULL THEN RETURN jsonb_build_object('body',v_replay,'replayed',true); END IF;
                SELECT * INTO i FROM public.billium_invoice WHERE id=p_invoice AND seller_ref=p_seller AND environment=p_environment;
                IF NOT FOUND OR (i.installation_ref,i.billing_account_ref,i.currency) IS DISTINCT FROM (p_installation,p_account,p_currency)
                THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                IF p_mode IS NULL OR p_mode NOT IN ('open_only','reverse_allocations') OR p_reason IS NULL OR p_reason = '' OR p_ref IS NULL OR p_ref = '' OR position('#' in p_ref) > 0
                   OR (p_legal IS NOT NULL AND p_legal = '')
                THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                -- A credit note corrects an issued document; a draft is adjusted, a void/discarded one has no receivable.
                IF i.status NOT IN ('issued','partially_paid','paid','uncollectible') THEN RAISE EXCEPTION 'billium.invoice.transition_invalid' USING ERRCODE='P0001'; END IF;
                v_count := jsonb_array_length(p_lines);
                -- Pass 1 validates every line on the locked rows and fixes the receipt body before any journal row.
                FOR l IN SELECT e.value AS value, e.ordinality AS pos FROM jsonb_array_elements(p_lines) WITH ORDINALITY e(value, ordinality) ORDER BY e.ordinality LOOP
                    SELECT * INTO o FROM public.billium_obligation WHERE id=(l.value->>'obligation_id')::bigint AND seller_ref=p_seller AND environment=p_environment;
                    IF NOT FOUND OR (o.installation_ref,o.billing_account_ref,o.currency) IS DISTINCT FROM (p_installation,p_account,p_currency) OR o.billed_invoice_id IS DISTINCT FROM i.id
                    THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                    v_amount := (l.value->>'amount_minor')::numeric;
                    IF v_amount IS NULL OR v_amount <= 0 OR v_amount <> trunc(v_amount) OR v_amount > 9223372036854775807
                    THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                    v_open := o.amount_due_minor::numeric + o.adjusted_minor - o.allocated_minor - o.credited_minor;
                    IF v_amount > o.amount_due_minor::numeric + o.adjusted_minor - o.credited_minor OR (p_mode = 'open_only' AND v_amount > v_open)
                    THEN RAISE EXCEPTION 'billium.allocation.exceeds_open' USING ERRCODE='P0001'; END IF;
                    v_note := CASE WHEN v_count = 1 THEN p_ref ELSE p_ref || '#' || l.pos END;
                    v_released := CASE WHEN v_amount > v_open THEN public.billium_ledger_release(p_installation,p_seller,p_environment,p_account,p_currency,o.id,NULL,v_amount - v_open,'credit_note',v_receipt,false) ELSE '[]'::jsonb END;
                    v_lines := v_lines || jsonb_build_array(jsonb_build_object('credit_note_ref',v_note,'obligation_ref',o.obligation_ref,'amount_minor',v_amount::bigint::text,
                        'released_minor',greatest(v_amount - v_open, 0)::bigint::text,'released',v_released));
                END LOOP;
                v_body := jsonb_build_object('receipt_ref',v_receipt,'credit_note_ref',p_ref,'invoice_ref',i.invoice_ref,'mode',p_mode,'reason',p_reason,
                    'legal_document_ref',p_legal,'currency',p_currency,'lines',v_lines);
                PERFORM public.billium_ledger_receipt(p_installation,p_seller,p_environment,p_account,p_currency,v_receipt,'credit_note',p_digest,v_body);
                FOR l IN SELECT e.value AS value, e.ordinality AS pos FROM jsonb_array_elements(p_lines) WITH ORDINALITY e(value, ordinality) ORDER BY e.ordinality LOOP
                    SELECT * INTO o FROM public.billium_obligation WHERE id=(l.value->>'obligation_id')::bigint;
                    v_amount := (l.value->>'amount_minor')::numeric;
                    v_open := o.amount_due_minor::numeric + o.adjusted_minor - o.allocated_minor - o.credited_minor;
                    IF v_amount > v_open THEN
                        PERFORM public.billium_ledger_release(p_installation,p_seller,p_environment,p_account,p_currency,o.id,NULL,v_amount - v_open,'credit_note',v_receipt,true);
                    END IF;
                    INSERT INTO public.billium_credit_note(installation_ref,seller_ref,environment,billing_account_ref,currency,credit_note_ref,invoice_id,obligation_id,amount_minor,reason,mode,legal_document_ref,receipt_ref)
                    VALUES(p_installation,p_seller,p_environment,p_account,p_currency,CASE WHEN v_count = 1 THEN p_ref ELSE p_ref || '#' || l.pos END,
                        i.id,o.id,v_amount::bigint,p_reason,p_mode,p_legal,v_receipt);
                END LOOP;
                RETURN jsonb_build_object('body',v_body,'replayed',false);
            END $$;

            -- D35: only after a confirmed refund made the payment over-allocated. LIFO reversals with cause = refund release
            -- exactly the excess; mode credit_note credits every reopened amount in the same Tx (debt closed), mode reversal
            -- leaves it open. No collection is authorized here.
            CREATE OR REPLACE FUNCTION public.billium_reallocate_payment(
                p_installation text,p_seller text,p_environment text,p_payment bigint,p_ref text,p_mode text,p_policy text,p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                p public.billium_payment; o public.billium_obligation; r jsonb; v_receipt text := 'reallocation:' || p_ref; v_replay jsonb; v_body jsonb;
                v_obligations bigint[]; v_excess numeric; v_plan jsonb; v_notes jsonb := '[]'::jsonb; v_n integer := 0; v_reopened numeric;
            BEGIN
                SELECT * INTO p FROM public.billium_payment WHERE id=p_payment AND seller_ref=p_seller AND environment=p_environment;
                IF NOT FOUND OR p.installation_ref IS DISTINCT FROM p_installation OR p.kind <> 'charge' THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                SELECT array_agg(DISTINCT obligation_id) INTO v_obligations FROM public.billium_allocation WHERE seller_ref=p_seller AND environment=p_environment AND payment_id=p.id;
                PERFORM public.billium_ledger_lock_scope(p_seller,p_environment,array_remove(ARRAY[p.settlement_intent_id],NULL),coalesce(v_obligations,'{}'),'{}',ARRAY[p.id],'{}');
                SELECT * INTO p FROM public.billium_payment WHERE id=p_payment;
                v_replay := public.billium_invoice_replay(p_installation,p_seller,p_environment,p.billing_account_ref,p.currency,v_receipt,'reallocation',p_digest);
                IF v_replay IS NOT NULL THEN RETURN jsonb_build_object('body',v_replay,'replayed',true); END IF;
                IF p_policy IS DISTINCT FROM 'refund/1' THEN RAISE EXCEPTION 'billium.policy.unknown' USING ERRCODE='P0001'; END IF;
                IF p_mode IS NULL OR p_mode NOT IN ('credit_note','reversal') OR p_ref IS NULL OR p_ref = '' OR NOT p.reallocation_required
                THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                v_excess := p.allocated_minor::numeric - (p.captured_minor::numeric - p.refunded_minor);
                v_plan := public.billium_ledger_release(p_installation,p_seller,p_environment,p.billing_account_ref,p.currency,NULL,p.id,v_excess,'refund',v_receipt,false);
                IF p_mode = 'credit_note' THEN
                    FOR r IN SELECT value FROM jsonb_array_elements(v_plan) LOOP
                        v_n := v_n + 1;
                        v_notes := v_notes || jsonb_build_array(jsonb_build_object('credit_note_ref','reallocation:' || p_ref || '#' || v_n,
                            'obligation_ref',r->>'obligation_ref','amount_minor',r->>'reopened_minor'));
                    END LOOP;
                END IF;
                v_body := jsonb_build_object('receipt_ref',v_receipt,'reallocation_ref',p_ref,'payment_ref',p.payment_ref,'mode',p_mode,'policy_version',p_policy,
                    'cause','refund','currency',p.currency,'billing_account_ref',p.billing_account_ref,'excess_minor',v_excess::bigint::text,'reversals',v_plan,'credit_notes',v_notes);
                PERFORM public.billium_ledger_receipt(p_installation,p_seller,p_environment,p.billing_account_ref,p.currency,v_receipt,'reallocation',p_digest,v_body);
                PERFORM public.billium_ledger_release(p_installation,p_seller,p_environment,p.billing_account_ref,p.currency,NULL,p.id,v_excess,'refund',v_receipt,true);
                FOR r IN SELECT value FROM jsonb_array_elements(v_notes) LOOP
                    SELECT * INTO o FROM public.billium_obligation WHERE seller_ref=p_seller AND environment=p_environment AND obligation_ref=r->>'obligation_ref';
                    v_reopened := (r->>'amount_minor')::numeric;
                    IF v_reopened > 0 THEN
                        INSERT INTO public.billium_credit_note(installation_ref,seller_ref,environment,billing_account_ref,currency,credit_note_ref,invoice_id,obligation_id,amount_minor,reason,mode,legal_document_ref,receipt_ref)
                        VALUES(p_installation,p_seller,p_environment,p.billing_account_ref,p.currency,r->>'credit_note_ref',o.billed_invoice_id,o.id,v_reopened::bigint,'refund','open_only',NULL,v_receipt);
                    END IF;
                END LOOP;
                RETURN jsonb_build_object('body',v_body,'replayed',false);
            END $$;

            DO $$ DECLARE f regprocedure; BEGIN
                FOR f IN SELECT p.oid::regprocedure FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                    WHERE n.nspname='public' AND p.proname IN ('billium_ledger_lock_scope','billium_ledger_release','billium_reverse_allocation','billium_issue_credit_note','billium_reallocate_payment')
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

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
            -- Creation semantic_digest and its immutable business receipt cover the original due-policy payload.
            -- Live policy columns can subsequently be amended and are not creation replay evidence.
            CREATE OR REPLACE FUNCTION public.billium_record_obligation(
                p_installation text,p_seller text,p_environment text,p_account text,p_currency text,
                p_ref text,p_basis text,p_customer text,p_component text,p_amount bigint,p_terms text,p_price text,p_tax text,
                p_due timestamptz,p_due_version text,p_policy_ref text,p_policy_digest text,p_eligible timestamptz,p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE o public.billium_obligation; v_receipt text := 'obligation:' || p_basis; v_body jsonb; v_inserted boolean;
            BEGIN
                v_body := jsonb_build_object('receipt_ref',v_receipt,'obligation_ref',p_ref,'revision',1);
                PERFORM public.billium_ledger_receipt(p_installation,p_seller,p_environment,p_account,p_currency,v_receipt,'obligation',p_digest,v_body);
                INSERT INTO public.billium_obligation(installation_ref,seller_ref,environment,billing_account_ref,currency,obligation_ref,basis_ref,customer_ref,component_ref,
                    amount_due_minor,terms_digest,semantic_digest,price_version_ref,tax_snapshot_ref,due_at,due_policy_version,source_policy_ref,source_policy_snapshot_digest,collection_eligible_at,receipt_ref,status)
                VALUES(p_installation,p_seller,p_environment,p_account,p_currency,p_ref,p_basis,p_customer,p_component,p_amount,p_terms,p_digest,p_price,p_tax,p_due,p_due_version,p_policy_ref,p_policy_digest,p_eligible,v_receipt,CASE WHEN p_amount=0 THEN 'settled' ELSE 'open' END)
                ON CONFLICT DO NOTHING RETURNING * INTO o;
                v_inserted := FOUND;
                IF NOT v_inserted THEN
                    SELECT * INTO o FROM public.billium_obligation WHERE seller_ref=p_seller AND environment=p_environment AND basis_ref=p_basis;
                    IF NOT FOUND OR (o.installation_ref,o.billing_account_ref,o.currency,o.obligation_ref,o.semantic_digest,o.customer_ref,o.component_ref,o.amount_due_minor,o.terms_digest,o.price_version_ref,o.tax_snapshot_ref,o.receipt_ref)
                        IS DISTINCT FROM (p_installation,p_account,p_currency,p_ref,p_digest,p_customer,p_component,p_amount,p_terms,p_price,p_tax,v_receipt)
                    THEN RAISE EXCEPTION 'billium.idempotency.conflict' USING ERRCODE='P0001'; END IF;
                END IF;
                RETURN jsonb_build_object('body',v_body,'replayed',NOT v_inserted);
            END $$;

            -- The caller supplies a source-qualified business reference and digest. Even a replay must prove that
            -- its immutable receipt belongs to the selected payment, not another authority with the same bare ref.
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
                IF v_replay IS NOT NULL THEN
                    IF (v_replay->>'payment_id',v_replay->>'source_authority',v_replay->>'payment_ref')
                        IS DISTINCT FROM (p.id::text,p.source_authority,p.payment_ref)
                    THEN RAISE EXCEPTION 'billium.idempotency.conflict' USING ERRCODE='P0001'; END IF;
                    RETURN jsonb_build_object('body',v_replay,'replayed',true);
                END IF;
                -- Preserve receipts created before source-qualified reallocation refs. Their release journal is the
                -- source association; a same-ref receipt on another payment can neither replay nor block this one.
                SELECT a.body INTO v_replay FROM public.billium_allocation_receipt a
                WHERE a.seller_ref=p_seller AND a.environment=p_environment AND a.installation_ref=p_installation
                  AND a.billing_account_ref=p.billing_account_ref AND a.currency=p.currency AND a.kind='reallocation'
                  AND a.receipt_ref='reallocation:' || p.payment_ref || '@' || p.refunded_minor
                  AND a.body->>'payment_id' IS NULL AND a.body->>'source_authority' IS NULL
                  AND a.body->>'payment_ref'=p.payment_ref
                  AND EXISTS (SELECT 1 FROM public.billium_allocation j WHERE j.seller_ref=p_seller AND j.environment=p_environment
                      AND j.receipt_ref=a.receipt_ref AND j.payment_id=p.id)
                  AND NOT EXISTS (SELECT 1 FROM public.billium_allocation j WHERE j.seller_ref=p_seller AND j.environment=p_environment
                      AND j.receipt_ref=a.receipt_ref AND j.payment_id IS DISTINCT FROM p.id);
                IF FOUND THEN
                    IF (v_replay->>'mode',v_replay->>'policy_version') IS DISTINCT FROM (p_mode,p_policy)
                    THEN RAISE EXCEPTION 'billium.idempotency.conflict' USING ERRCODE='P0001'; END IF;
                    RETURN jsonb_build_object('body',v_replay,'replayed',true);
                END IF;
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
                v_body := jsonb_build_object('receipt_ref',v_receipt,'reallocation_ref',p_ref,'payment_id',p.id,'payment_ref',p.payment_ref,
                    'source_authority',p.source_authority,'mode',p_mode,'policy_version',p_policy,
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
            REVOKE ALL ON FUNCTION public.billium_record_obligation(text,text,text,text,text,text,text,text,text,bigint,text,text,text,timestamptz,text,text,text,timestamptz,text) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.billium_reallocate_payment(text,text,text,bigint,text,text,text,text) FROM PUBLIC;
            SQL);
        app(LedgerPrivileges::class)->grant();
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

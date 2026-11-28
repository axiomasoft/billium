<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

/**
 * Ledger guards: cross-row invariants and the settlement intent routines.
 */
return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- These are narrow lock-only capabilities; runtime has no UPDATE privilege, even on id columns.
            -- Retained functions must be refreshed along with the tables on migrate:fresh.
            CREATE OR REPLACE FUNCTION public.billium_lock_settlement_intent(p_ids bigint[], p_seller text, p_environment text, p_shared boolean)
            RETURNS TABLE (lock_key bigint) LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF p_shared THEN
                    RETURN QUERY SELECT t.id FROM public.billium_settlement_intent t WHERE t.id = ANY(p_ids) AND t.seller_ref = p_seller AND t.environment = p_environment ORDER BY t.id FOR SHARE;
                ELSE
                    RETURN QUERY SELECT t.id FROM public.billium_settlement_intent t WHERE t.id = ANY(p_ids) AND t.seller_ref = p_seller AND t.environment = p_environment ORDER BY t.id FOR UPDATE;
                END IF;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_lock_settlement_intent(p_ids bigint[], p_seller text, p_environment text, p_shared boolean) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.billium_lock_obligation(p_ids bigint[], p_seller text, p_environment text, p_shared boolean)
            RETURNS TABLE (lock_key bigint) LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF p_shared THEN
                    RETURN QUERY SELECT t.id FROM public.billium_obligation t WHERE t.id = ANY(p_ids) AND t.seller_ref = p_seller AND t.environment = p_environment ORDER BY t.id FOR SHARE;
                ELSE
                    RETURN QUERY SELECT t.id FROM public.billium_obligation t WHERE t.id = ANY(p_ids) AND t.seller_ref = p_seller AND t.environment = p_environment ORDER BY t.id FOR UPDATE;
                END IF;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_lock_obligation(p_ids bigint[], p_seller text, p_environment text, p_shared boolean) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.billium_lock_invoice(p_ids bigint[], p_seller text, p_environment text, p_shared boolean)
            RETURNS TABLE (lock_key bigint) LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF p_shared THEN
                    RETURN QUERY SELECT t.id FROM public.billium_invoice t WHERE t.id = ANY(p_ids) AND t.seller_ref = p_seller AND t.environment = p_environment ORDER BY t.id FOR SHARE;
                ELSE
                    RETURN QUERY SELECT t.id FROM public.billium_invoice t WHERE t.id = ANY(p_ids) AND t.seller_ref = p_seller AND t.environment = p_environment ORDER BY t.id FOR UPDATE;
                END IF;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_lock_invoice(p_ids bigint[], p_seller text, p_environment text, p_shared boolean) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.billium_lock_payment(p_ids bigint[], p_seller text, p_environment text, p_shared boolean)
            RETURNS TABLE (lock_key bigint) LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF p_shared THEN
                    RETURN QUERY SELECT t.id FROM public.billium_payment t WHERE t.id = ANY(p_ids) AND t.seller_ref = p_seller AND t.environment = p_environment ORDER BY t.id FOR SHARE;
                ELSE
                    RETURN QUERY SELECT t.id FROM public.billium_payment t WHERE t.id = ANY(p_ids) AND t.seller_ref = p_seller AND t.environment = p_environment ORDER BY t.id FOR UPDATE;
                END IF;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_lock_payment(p_ids bigint[], p_seller text, p_environment text, p_shared boolean) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.billium_lock_customer_credit(p_ids bigint[], p_seller text, p_environment text, p_shared boolean)
            RETURNS TABLE (lock_key bigint) LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF p_shared THEN
                    RETURN QUERY SELECT t.id FROM public.billium_customer_credit t WHERE t.id = ANY(p_ids) AND t.seller_ref = p_seller AND t.environment = p_environment ORDER BY t.id FOR SHARE;
                ELSE
                    RETURN QUERY SELECT t.id FROM public.billium_customer_credit t WHERE t.id = ANY(p_ids) AND t.seller_ref = p_seller AND t.environment = p_environment ORDER BY t.id FOR UPDATE;
                END IF;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_lock_customer_credit(p_ids bigint[], p_seller text, p_environment text, p_shared boolean) FROM PUBLIC;

            -- Private receipt writer; callers cannot inject arbitrary receipts or counters.
            CREATE OR REPLACE FUNCTION public.billium_ledger_receipt(p_installation text,p_seller text,p_environment text,p_account text,p_currency text,p_ref text,p_kind text,p_digest text,p_body jsonb)
            RETURNS void LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE r public.billium_allocation_receipt;
            BEGIN
                INSERT INTO public.billium_allocation_receipt(installation_ref,seller_ref,environment,billing_account_ref,currency,receipt_ref,kind,semantic_digest,body)
                VALUES(p_installation,p_seller,p_environment,p_account,p_currency,p_ref,p_kind,p_digest,p_body)
                ON CONFLICT (seller_ref,environment,receipt_ref) DO NOTHING;
                SELECT * INTO STRICT r FROM public.billium_allocation_receipt WHERE seller_ref=p_seller AND environment=p_environment AND receipt_ref=p_ref;
                IF (r.installation_ref,r.billing_account_ref,r.currency,r.kind,r.semantic_digest,r.body) IS DISTINCT FROM (p_installation,p_account,p_currency,p_kind,p_digest,p_body)
                THEN RAISE EXCEPTION 'billium.idempotency.conflict' USING ERRCODE='P0001'; END IF;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_ledger_receipt(p_installation text, p_seller text, p_environment text, p_account text, p_currency text, p_ref text, p_kind text, p_digest text, p_body jsonb) FROM PUBLIC;

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
            REVOKE ALL ON FUNCTION public.billium_record_obligation(p_installation text, p_seller text, p_environment text, p_account text, p_currency text, p_ref text, p_basis text, p_customer text, p_component text, p_amount bigint, p_terms text, p_price text, p_tax text, p_due timestamp with time zone, p_due_version text, p_policy_ref text, p_policy_digest text, p_eligible timestamp with time zone, p_digest text) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.billium_adjust_obligation(
                p_installation text,p_seller text,p_environment text,p_account text,p_currency text,
                p_ref text,p_obligation bigint,p_delta bigint,p_cause text,p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE o public.billium_obligation; a public.billium_adjustment; v_receipt text := 'adjustment:' || p_ref; v_body jsonb;
            BEGIN
                SELECT * INTO o FROM public.billium_obligation WHERE id=p_obligation AND seller_ref=p_seller AND environment=p_environment FOR UPDATE;
                IF NOT FOUND OR (o.installation_ref,o.billing_account_ref) IS DISTINCT FROM (p_installation,p_account) THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                IF o.currency <> p_currency THEN RAISE EXCEPTION 'billium.money.currency_mismatch' USING ERRCODE='P0001'; END IF;
                SELECT * INTO a FROM public.billium_adjustment WHERE seller_ref=p_seller AND environment=p_environment AND adjustment_ref=p_ref;
                IF FOUND THEN
                    IF (a.obligation_id,a.semantic_digest,a.kind,a.amount_minor,a.cause_ref) IS DISTINCT FROM (p_obligation,p_digest,CASE WHEN p_delta>0 THEN 'increase' ELSE 'decrease' END,abs(p_delta),p_cause) THEN RAISE EXCEPTION 'billium.idempotency.conflict' USING ERRCODE='P0001'; END IF;
                    SELECT body INTO v_body FROM public.billium_allocation_receipt WHERE seller_ref=p_seller AND environment=p_environment AND receipt_ref=a.receipt_ref;
                    RETURN jsonb_build_object('body',v_body,'replayed',true);
                END IF;
                IF p_delta IS NULL OR p_delta=0 OR p_delta='-9223372036854775808'::bigint THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                IF o.billed_invoice_id IS NOT NULL THEN
                    PERFORM 1 FROM public.billium_invoice WHERE id=o.billed_invoice_id AND seller_ref=p_seller AND environment=p_environment AND status <> 'draft' FOR UPDATE;
                    IF FOUND THEN RAISE EXCEPTION 'billium.invoice.frozen' USING ERRCODE='P0001'; END IF;
                END IF;
                IF o.amount_due_minor::numeric + o.adjusted_minor + p_delta < o.allocated_minor::numeric + o.credited_minor THEN RAISE EXCEPTION 'billium.allocation.exceeds_open' USING ERRCODE='P0001'; END IF;
                v_body := jsonb_build_object('receipt_ref',v_receipt,'obligation_ref',o.obligation_ref,'adjustment_ref',p_ref,'revision',o.revision+1);
                PERFORM public.billium_ledger_receipt(p_installation,p_seller,p_environment,p_account,p_currency,v_receipt,'adjustment',p_digest,v_body);
                INSERT INTO public.billium_adjustment(installation_ref,seller_ref,environment,billing_account_ref,currency,adjustment_ref,obligation_id,kind,amount_minor,cause_ref,revision,semantic_digest,receipt_ref)
                VALUES(p_installation,p_seller,p_environment,p_account,p_currency,p_ref,p_obligation,CASE WHEN p_delta>0 THEN 'increase' ELSE 'decrease' END,abs(p_delta),p_cause,o.revision+1,p_digest,v_receipt);
                -- The adjustment guard holds the invoice lock. Keep its live draft budget current without changing
                -- the line snapshots, which issue_invoice refreshes from the obligations at issue time.
                UPDATE public.billium_invoice SET total_minor=(total_minor::numeric+p_delta)::bigint,
                    subtotal_minor=(subtotal_minor::numeric+p_delta)::bigint, revision=revision+1
                  WHERE id=o.billed_invoice_id AND seller_ref=p_seller AND environment=p_environment AND status='draft';
                RETURN jsonb_build_object('body',v_body,'replayed',false);
            END $$;
            REVOKE ALL ON FUNCTION public.billium_adjust_obligation(p_installation text, p_seller text, p_environment text, p_account text, p_currency text, p_ref text, p_obligation bigint, p_delta bigint, p_cause text, p_digest text) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.billium_record_customer_credit(
                p_installation text,p_seller text,p_environment text,p_account text,p_currency text,p_ref text,p_source text,p_amount bigint,p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE c public.billium_customer_credit; v_receipt text := 'credit:' || p_source; v_body jsonb; v_inserted boolean;
            BEGIN
                v_body := jsonb_build_object('receipt_ref',v_receipt,'credit_ref',p_ref,'revision',1);
                PERFORM public.billium_ledger_receipt(p_installation,p_seller,p_environment,p_account,p_currency,v_receipt,'customer_credit',p_digest,v_body);
                INSERT INTO public.billium_customer_credit(installation_ref,seller_ref,environment,billing_account_ref,currency,credit_ref,source_ref,amount_minor,remaining_minor,semantic_digest,receipt_ref)
                VALUES(p_installation,p_seller,p_environment,p_account,p_currency,p_ref,p_source,p_amount,p_amount,p_digest,v_receipt)
                ON CONFLICT DO NOTHING RETURNING * INTO c;
                v_inserted := FOUND;
                IF NOT v_inserted THEN
                    SELECT * INTO c FROM public.billium_customer_credit WHERE seller_ref=p_seller AND environment=p_environment AND source_ref=p_source;
                    IF NOT FOUND OR (c.installation_ref,c.billing_account_ref,c.currency,c.credit_ref,c.semantic_digest,c.amount_minor) IS DISTINCT FROM (p_installation,p_account,p_currency,p_ref,p_digest,p_amount)
                    THEN RAISE EXCEPTION 'billium.idempotency.conflict' USING ERRCODE='P0001'; END IF;
                END IF;
                RETURN jsonb_build_object('body',v_body,'replayed',NOT v_inserted);
            END $$;
            REVOKE ALL ON FUNCTION public.billium_record_customer_credit(p_installation text, p_seller text, p_environment text, p_account text, p_currency text, p_ref text, p_source text, p_amount bigint, p_digest text) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.billium_declare_settlement_intent(
                p_installation text,p_seller text,p_environment text,p_account text,p_currency text,p_ref text,p_policy text,p_targets bigint[],p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE i public.billium_settlement_intent; o public.billium_obligation; v_receipt text := 'intent:' || p_ref; v_body jsonb; v_count bigint;
            BEGIN
                IF p_policy IS DISTINCT FROM 'pro_rata_open/1' THEN RAISE EXCEPTION 'billium.policy.unknown' USING ERRCODE='P0001'; END IF;
                IF p_targets IS NULL OR cardinality(p_targets)=0 OR array_position(p_targets,NULL) IS NOT NULL OR cardinality(p_targets) <> (SELECT count(DISTINCT x) FROM unnest(p_targets) x)
                THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                -- First reserve the immutable identity. An existing intent is locked at 65 before any obligation.
                v_body := jsonb_build_object('receipt_ref',v_receipt,'business_intent_ref',p_ref,'revision',1);
                PERFORM public.billium_ledger_receipt(p_installation,p_seller,p_environment,p_account,p_currency,v_receipt,'settlement_intent',p_digest,v_body);
                INSERT INTO public.billium_settlement_intent(installation_ref,seller_ref,environment,billing_account_ref,currency,business_intent_ref,policy_version,semantic_digest,receipt_ref)
                VALUES(p_installation,p_seller,p_environment,p_account,p_currency,p_ref,p_policy,p_digest,v_receipt)
                ON CONFLICT DO NOTHING RETURNING * INTO i;
                IF NOT FOUND THEN
                    SELECT * INTO i FROM public.billium_settlement_intent WHERE seller_ref=p_seller AND environment=p_environment AND business_intent_ref=p_ref FOR UPDATE;
                    -- Targets retain ordered obligation identities, independent of payment counters.
                    IF (i.installation_ref,i.billing_account_ref,i.currency,i.semantic_digest,i.policy_version) IS DISTINCT FROM (p_installation,p_account,p_currency,p_digest,p_policy)
                       OR p_targets IS DISTINCT FROM (
                           SELECT array_agg(t.obligation_id ORDER BY t.position) FROM public.billium_settlement_target t
                           WHERE t.seller_ref=p_seller AND t.environment=p_environment AND t.settlement_intent_id=i.id)
                    THEN RAISE EXCEPTION 'billium.idempotency.conflict' USING ERRCODE='P0001'; END IF;
                    RETURN jsonb_build_object('body',v_body,'replayed',true);
                END IF;
                v_count:=0;
                FOR o IN SELECT * FROM public.billium_obligation WHERE seller_ref=p_seller AND environment=p_environment AND id=ANY(p_targets) ORDER BY id FOR UPDATE LOOP
                    IF (o.installation_ref,o.billing_account_ref) IS DISTINCT FROM (p_installation,p_account) THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                    IF o.currency<>p_currency THEN RAISE EXCEPTION 'billium.money.currency_mismatch' USING ERRCODE='P0001'; END IF;
                    v_count:=v_count+1;
                END LOOP;
                IF v_count<>cardinality(p_targets) THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                INSERT INTO public.billium_settlement_target(installation_ref,seller_ref,environment,billing_account_ref,currency,settlement_intent_id,obligation_id,position)
                SELECT p_installation,p_seller,p_environment,p_account,p_currency,i.id,t.id,(t.position-1)::integer FROM unnest(p_targets) WITH ORDINALITY t(id,position);
                RETURN jsonb_build_object('body',v_body,'replayed',false);
            END $$;
            REVOKE ALL ON FUNCTION public.billium_declare_settlement_intent(p_installation text, p_seller text, p_environment text, p_account text, p_currency text, p_ref text, p_policy text, p_targets bigint[], p_digest text) FROM PUBLIC;

            -- External proof semantics are the collection participant's same-root typed trust boundary.
            -- This routine checks ledger state only; no collection/execution table access and no claim of PSP absence inference.
            CREATE OR REPLACE FUNCTION public.billium_close_settlement_intent_no_effect(
                p_installation text,p_seller text,p_environment text,p_account text,p_currency text,p_intent bigint,
                p_closure text,p_expected_revision bigint,p_step text,p_proof_kind text,p_proof_digest text,p_proof_evidence text,p_cause text,p_receipt text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE i public.billium_settlement_intent; c public.billium_settlement_intent_closure; v_body jsonb; v_digest text;
            BEGIN
                SELECT * INTO i FROM public.billium_settlement_intent WHERE id=p_intent AND seller_ref=p_seller AND environment=p_environment FOR UPDATE;
                IF NOT FOUND OR (i.installation_ref,i.billing_account_ref,i.currency) IS DISTINCT FROM (p_installation,p_account,p_currency) THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                SELECT * INTO c FROM public.billium_settlement_intent_closure WHERE seller_ref=p_seller AND environment=p_environment AND (settlement_intent_id=p_intent OR closure_ref=p_closure OR receipt_ref=p_receipt);
                IF FOUND THEN
                    IF (c.installation_ref,c.billing_account_ref,c.currency,c.settlement_intent_id,c.closure_ref,c.expected_revision,c.step_authorization_ref,c.proof_kind,c.proof_digest,c.proof_evidence_ref,c.cause,c.receipt_ref)
                       IS DISTINCT FROM (p_installation,p_account,p_currency,p_intent,p_closure,p_expected_revision,p_step,p_proof_kind,p_proof_digest,p_proof_evidence,p_cause,p_receipt)
                    THEN RAISE EXCEPTION 'billium.idempotency.conflict' USING ERRCODE='P0001'; END IF;
                    SELECT body INTO v_body FROM public.billium_allocation_receipt WHERE seller_ref=p_seller AND environment=p_environment AND receipt_ref=c.receipt_ref;
                    RETURN jsonb_build_object('body',v_body,'replayed',true);
                END IF;
                IF p_expected_revision IS DISTINCT FROM i.revision OR i.status <> 'open'
                   OR EXISTS (SELECT FROM public.billium_payment WHERE seller_ref=p_seller AND environment=p_environment AND settlement_intent_id=i.id)
                   OR EXISTS (SELECT FROM public.billium_allocation WHERE seller_ref=p_seller AND environment=p_environment AND settlement_intent_id=i.id)
                THEN RAISE EXCEPTION 'billium.idempotency.conflict' USING ERRCODE='P0001'; END IF;
                IF p_proof_kind IS NULL OR p_proof_kind NOT IN ('not_admitted','not_dispatched') OR p_proof_digest IS NULL OR p_proof_digest !~ '^sha256:[0-9a-f]{64}$'
                   OR p_proof_evidence IS NULL OR p_proof_evidence='' OR p_step IS NULL OR p_step=''
                THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                v_body:=jsonb_build_object('receipt_ref',p_receipt,'closure_ref',p_closure,'business_intent_ref',i.business_intent_ref,'revision',i.revision+1);
                v_digest:='sha256:' || encode(sha256(convert_to(jsonb_build_array(p_intent,p_closure,p_expected_revision,p_step,p_proof_kind,p_proof_digest,p_proof_evidence,p_cause,p_receipt)::text,'UTF8')),'hex');
                PERFORM public.billium_ledger_receipt(p_installation,p_seller,p_environment,p_account,p_currency,p_receipt,'settlement_intent_closure',v_digest,v_body);
                INSERT INTO public.billium_settlement_intent_closure(installation_ref,seller_ref,environment,billing_account_ref,currency,settlement_intent_id,closure_ref,expected_revision,step_authorization_ref,proof_kind,proof_digest,proof_evidence_ref,cause,receipt_ref)
                VALUES(p_installation,p_seller,p_environment,p_account,p_currency,p_intent,p_closure,p_expected_revision,p_step,p_proof_kind,p_proof_digest,p_proof_evidence,p_cause,p_receipt);
                UPDATE public.billium_settlement_intent SET revision=revision+1,status='closed_no_effect' WHERE id=i.id;
                RETURN jsonb_build_object('body',v_body,'replayed',false);
            END $$;
            REVOKE ALL ON FUNCTION public.billium_close_settlement_intent_no_effect(p_installation text, p_seller text, p_environment text, p_account text, p_currency text, p_intent bigint, p_closure text, p_expected_revision bigint, p_step text, p_proof_kind text, p_proof_digest text, p_proof_evidence text, p_cause text, p_receipt text) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.billium_allocation_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE o public.billium_obligation; p public.billium_payment; c public.billium_customer_credit; a public.billium_allocation; i public.billium_settlement_intent; v_invoice bigint;
            BEGIN
                IF NEW.settlement_intent_id IS NOT NULL THEN
                    SELECT * INTO i FROM public.billium_settlement_intent WHERE id=NEW.settlement_intent_id AND seller_ref=NEW.seller_ref AND environment=NEW.environment FOR UPDATE;
                    IF NOT FOUND OR i.status='closed_no_effect' OR (i.installation_ref,i.billing_account_ref,i.currency) IS DISTINCT FROM (NEW.installation_ref,NEW.billing_account_ref,NEW.currency)
                    THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                END IF;
                SELECT * INTO o FROM public.billium_obligation WHERE id=NEW.obligation_id AND seller_ref=NEW.seller_ref AND environment=NEW.environment FOR UPDATE;
                IF NOT FOUND OR (o.installation_ref,o.billing_account_ref,o.currency) IS DISTINCT FROM (NEW.installation_ref,NEW.billing_account_ref,NEW.currency)
                THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                v_invoice:=o.billed_invoice_id;
                IF v_invoice IS NOT NULL THEN PERFORM 1 FROM public.billium_invoice WHERE id=v_invoice AND seller_ref=NEW.seller_ref AND environment=NEW.environment FOR UPDATE; END IF;
                IF NEW.payment_id IS NOT NULL THEN
                    SELECT * INTO p FROM public.billium_payment WHERE id=NEW.payment_id AND seller_ref=NEW.seller_ref AND environment=NEW.environment FOR UPDATE;
                    IF NOT FOUND OR p.kind<>'charge' OR (p.installation_ref,p.billing_account_ref,p.currency) IS DISTINCT FROM (NEW.installation_ref,NEW.billing_account_ref,NEW.currency)
                    THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                    IF p.settlement_intent_id IS DISTINCT FROM NEW.settlement_intent_id THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                    IF NEW.amount_minor>0 AND (p.financial_hold OR p.reallocation_required) THEN RAISE EXCEPTION 'billium.allocation.reallocation_required' USING ERRCODE='P0001'; END IF;
                    IF NEW.amount_minor>0 AND p.allocated_minor::numeric+NEW.amount_minor>p.captured_minor::numeric-p.refunded_minor THEN RAISE EXCEPTION 'billium.allocation.exceeds_available' USING ERRCODE='P0001'; END IF;
                ELSIF NEW.customer_credit_id IS NOT NULL THEN
                    SELECT * INTO c FROM public.billium_customer_credit WHERE id=NEW.customer_credit_id AND seller_ref=NEW.seller_ref AND environment=NEW.environment FOR UPDATE;
                    IF NOT FOUND OR (c.installation_ref,c.billing_account_ref,c.currency) IS DISTINCT FROM (NEW.installation_ref,NEW.billing_account_ref,NEW.currency)
                    THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                    IF NEW.amount_minor>c.remaining_minor THEN RAISE EXCEPTION 'billium.allocation.exceeds_available' USING ERRCODE='P0001'; END IF;
                END IF;
                IF NEW.amount_minor>0 AND (o.status='cancelled' OR o.allocated_minor::numeric+o.credited_minor+NEW.amount_minor>o.amount_due_minor::numeric+o.adjusted_minor)
                THEN RAISE EXCEPTION 'billium.allocation.exceeds_open' USING ERRCODE='P0001'; END IF;
                IF NEW.reverses_id IS NOT NULL THEN
                    SELECT * INTO a FROM public.billium_allocation WHERE id=NEW.reverses_id AND seller_ref=NEW.seller_ref AND environment=NEW.environment;
                    IF NOT FOUND OR a.reverses_id IS NOT NULL OR NEW.amount_minor::numeric <> -a.amount_minor::numeric
                      OR (NEW.obligation_id,NEW.payment_id,NEW.customer_credit_id,NEW.settlement_intent_id,NEW.billing_account_ref,NEW.currency) IS DISTINCT FROM (a.obligation_id,a.payment_id,a.customer_credit_id,a.settlement_intent_id,a.billing_account_ref,a.currency)
                    THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                END IF;
                IF NEW.amount_minor>0 AND NEW.settlement_intent_id IS NOT NULL AND NOT EXISTS (
                    SELECT FROM public.billium_settlement_target WHERE seller_ref=NEW.seller_ref AND environment=NEW.environment AND settlement_intent_id=NEW.settlement_intent_id AND obligation_id=NEW.obligation_id)
                THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                UPDATE public.billium_obligation SET allocated_minor=(allocated_minor::numeric+NEW.amount_minor)::bigint,revision=revision+1,
                    status=CASE WHEN status='cancelled' THEN status WHEN allocated_minor::numeric+NEW.amount_minor+credited_minor=amount_due_minor::numeric+adjusted_minor THEN 'settled' ELSE 'open' END WHERE id=o.id;
                IF p.id IS NOT NULL THEN
                    UPDATE public.billium_payment SET allocated_minor=(allocated_minor::numeric+NEW.amount_minor)::bigint,
                        reallocation_required=(allocated_minor::numeric+NEW.amount_minor>captured_minor::numeric-refunded_minor) WHERE id=p.id;
                ELSIF c.id IS NOT NULL THEN
                    UPDATE public.billium_customer_credit SET remaining_minor=(remaining_minor::numeric-NEW.amount_minor)::bigint,revision=revision+1 WHERE id=c.id;
                END IF;
                IF v_invoice IS NOT NULL THEN UPDATE public.billium_invoice SET allocated_minor=(allocated_minor::numeric+NEW.amount_minor)::bigint,revision=revision+1,
                    status=CASE WHEN status IN ('issued','partially_paid','paid') OR (status='uncollectible' AND NEW.amount_minor>0) THEN
                        CASE WHEN allocated_minor::numeric+NEW.amount_minor+credited_minor=total_minor THEN 'paid'
                             WHEN allocated_minor::numeric+NEW.amount_minor+credited_minor>0 THEN 'partially_paid' ELSE 'issued' END ELSE status END
                    WHERE id=v_invoice; END IF;
                IF i.id IS NOT NULL THEN UPDATE public.billium_settlement_intent SET revision=revision+1,status='applied' WHERE id=i.id; END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_allocation_guard() FROM PUBLIC;

            CREATE TRIGGER billium_allocation_guard BEFORE INSERT ON public.billium_allocation FOR EACH ROW EXECUTE FUNCTION public.billium_allocation_guard();

            CREATE OR REPLACE FUNCTION public.billium_allocate(
                p_installation text,p_seller text,p_environment text,p_account text,p_currency text,p_obligation bigint,
                p_payment bigint,p_credit bigint,p_intent bigint,p_amount bigint,p_reverse bigint,p_cause text,p_policy text,p_receipt text,p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE r public.billium_allocation_receipt; v_body jsonb;
            BEGIN
                -- All paths, including exact replay, lock the intent first.
                IF p_intent IS NOT NULL THEN PERFORM 1 FROM public.billium_settlement_intent WHERE id=p_intent AND seller_ref=p_seller AND environment=p_environment FOR UPDATE; END IF;
                PERFORM 1 FROM public.billium_obligation WHERE id=p_obligation AND seller_ref=p_seller AND environment=p_environment FOR UPDATE;
                SELECT * INTO r FROM public.billium_allocation_receipt WHERE seller_ref=p_seller AND environment=p_environment AND receipt_ref=p_receipt;
                v_body:=jsonb_build_object('receipt_ref',p_receipt,'obligation_id',p_obligation,'payment_id',p_payment,'customer_credit_id',p_credit,'settlement_intent_id',p_intent,'amount_minor',p_amount::text,'reverses_id',p_reverse,'cause',p_cause,'policy_version',p_policy);
                IF FOUND THEN
                    IF (r.installation_ref,r.billing_account_ref,r.currency,r.kind,r.semantic_digest,r.body) IS DISTINCT FROM (p_installation,p_account,p_currency,'allocation'::text,p_digest,v_body)
                    THEN RAISE EXCEPTION 'billium.idempotency.conflict' USING ERRCODE='P0001'; END IF;
                    RETURN jsonb_build_object('body',r.body,'replayed',true);
                END IF;
                PERFORM public.billium_ledger_receipt(p_installation,p_seller,p_environment,p_account,p_currency,p_receipt,'allocation',p_digest,v_body);
                INSERT INTO public.billium_allocation(installation_ref,seller_ref,environment,billing_account_ref,currency,obligation_id,payment_id,customer_credit_id,settlement_intent_id,amount_minor,reverses_id,cause,policy_version,receipt_ref)
                VALUES(p_installation,p_seller,p_environment,p_account,p_currency,p_obligation,p_payment,p_credit,p_intent,p_amount,p_reverse,p_cause,p_policy,p_receipt);
                RETURN jsonb_build_object('body',v_body,'replayed',false);
            END $$;
            REVOKE ALL ON FUNCTION public.billium_allocate(p_installation text, p_seller text, p_environment text, p_account text, p_currency text, p_obligation bigint, p_payment bigint, p_credit bigint, p_intent bigint, p_amount bigint, p_reverse bigint, p_cause text, p_policy text, p_receipt text, p_digest text) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.billium_funds_identity_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'billium: protected funds identity' USING ERRCODE='55000'; END IF;
                IF (NEW.id,NEW.installation_ref,NEW.seller_ref,NEW.environment,NEW.billing_account_ref,NEW.currency)
                   IS DISTINCT FROM (OLD.id,OLD.installation_ref,OLD.seller_ref,OLD.environment,OLD.billing_account_ref,OLD.currency)
                THEN RAISE EXCEPTION 'billium: immutable funds scope' USING ERRCODE='55000'; END IF;
                IF TG_TABLE_NAME='billium_customer_credit' AND (to_jsonb(NEW)-ARRAY['remaining_minor','revision']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['remaining_minor','revision'])
                THEN RAISE EXCEPTION 'billium: immutable credit terms' USING ERRCODE='55000'; END IF;
                IF TG_TABLE_NAME='billium_payment' AND (to_jsonb(NEW)-ARRAY['applied_revision','requested_minor','authorized_minor','captured_minor','refunded_minor','voided_minor','allocated_minor','financial_hold','reallocation_required','projection_status'])
                   IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['applied_revision','requested_minor','authorized_minor','captured_minor','refunded_minor','voided_minor','allocated_minor','financial_hold','reallocation_required','projection_status'])
                THEN RAISE EXCEPTION 'billium: immutable payment source' USING ERRCODE='55000'; END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_funds_identity_guard() FROM PUBLIC;

            CREATE TRIGGER billium_customer_credit_identity_guard BEFORE UPDATE OR DELETE ON public.billium_customer_credit FOR EACH ROW EXECUTE FUNCTION public.billium_funds_identity_guard();

            CREATE TRIGGER billium_payment_identity_guard BEFORE UPDATE OR DELETE ON public.billium_payment FOR EACH ROW EXECUTE FUNCTION public.billium_funds_identity_guard();

            -- Cancellation is terminal: the allocation and adjustment guards keep it, but a credit note that closed the
            -- remaining amount turned a cancelled obligation into a settled one.
            CREATE OR REPLACE FUNCTION public.billium_credit_note_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE o public.billium_obligation;
            BEGIN
                SELECT * INTO o FROM public.billium_obligation WHERE id=NEW.obligation_id AND seller_ref=NEW.seller_ref AND environment=NEW.environment FOR UPDATE;
                IF NOT FOUND OR (o.installation_ref,o.billing_account_ref,o.currency,o.billed_invoice_id) IS DISTINCT FROM (NEW.installation_ref,NEW.billing_account_ref,NEW.currency,NEW.invoice_id)
                THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                IF NEW.invoice_id IS NOT NULL THEN PERFORM 1 FROM public.billium_invoice WHERE id=NEW.invoice_id AND seller_ref=NEW.seller_ref AND environment=NEW.environment FOR UPDATE; END IF;
                IF o.allocated_minor::numeric+o.credited_minor+NEW.amount_minor>o.amount_due_minor::numeric+o.adjusted_minor
                THEN RAISE EXCEPTION 'billium.allocation.exceeds_open' USING ERRCODE='P0001'; END IF;
                UPDATE public.billium_obligation SET credited_minor=(credited_minor::numeric+NEW.amount_minor)::bigint,revision=revision+1,
                    status=CASE WHEN status='cancelled' THEN status WHEN allocated_minor::numeric+credited_minor+NEW.amount_minor=amount_due_minor::numeric+adjusted_minor THEN 'settled' ELSE status END WHERE id=o.id;
                IF NEW.invoice_id IS NOT NULL THEN UPDATE public.billium_invoice SET credited_minor=(credited_minor::numeric+NEW.amount_minor)::bigint,revision=revision+1,
                    status=CASE WHEN status IN ('issued','partially_paid','paid','uncollectible') THEN
                        CASE WHEN allocated_minor::numeric+credited_minor+NEW.amount_minor=total_minor THEN 'paid' ELSE 'partially_paid' END ELSE status END
                    WHERE id=NEW.invoice_id; END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_credit_note_guard() FROM PUBLIC;

            CREATE TRIGGER billium_credit_note_guard BEFORE INSERT ON public.billium_credit_note FOR EACH ROW EXECUTE FUNCTION public.billium_credit_note_guard();

            CREATE OR REPLACE FUNCTION public.billium_adjustment_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE o public.billium_obligation; v_delta numeric;
            BEGIN
                SELECT * INTO o FROM public.billium_obligation WHERE id=NEW.obligation_id AND seller_ref=NEW.seller_ref AND environment=NEW.environment FOR UPDATE;
                IF NOT FOUND OR (o.installation_ref,o.billing_account_ref,o.currency) IS DISTINCT FROM (NEW.installation_ref,NEW.billing_account_ref,NEW.currency)
                THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                IF NEW.revision IS DISTINCT FROM o.revision+1 THEN RAISE EXCEPTION 'billium.idempotency.conflict' USING ERRCODE='P0001'; END IF;
                IF o.billed_invoice_id IS NOT NULL THEN
                    PERFORM 1 FROM public.billium_invoice WHERE id=o.billed_invoice_id AND seller_ref=NEW.seller_ref AND environment=NEW.environment FOR UPDATE;
                    PERFORM 1 FROM public.billium_invoice WHERE id=o.billed_invoice_id AND seller_ref=NEW.seller_ref AND environment=NEW.environment AND status<>'draft';
                    IF FOUND THEN RAISE EXCEPTION 'billium.invoice.frozen' USING ERRCODE='P0001'; END IF;
                END IF;
                v_delta:=CASE WHEN NEW.kind='increase' THEN NEW.amount_minor::numeric ELSE -NEW.amount_minor::numeric END;
                IF o.amount_due_minor::numeric+o.adjusted_minor+v_delta < o.allocated_minor::numeric+o.credited_minor
                THEN RAISE EXCEPTION 'billium.allocation.exceeds_open' USING ERRCODE='P0001'; END IF;
                UPDATE public.billium_obligation SET adjusted_minor=(adjusted_minor::numeric+v_delta)::bigint,revision=revision+1,
                    status=CASE WHEN status='cancelled' THEN status WHEN allocated_minor::numeric+credited_minor=amount_due_minor::numeric+adjusted_minor+v_delta THEN 'settled' ELSE 'open' END WHERE id=o.id;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_adjustment_guard() FROM PUBLIC;

            CREATE TRIGGER billium_adjustment_guard BEFORE INSERT ON public.billium_adjustment FOR EACH ROW EXECUTE FUNCTION public.billium_adjustment_guard();

            CREATE OR REPLACE FUNCTION public.billium_payment_intent_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE i public.billium_settlement_intent;
            BEGIN
                IF NEW.settlement_intent_id IS NOT NULL THEN
                    SELECT * INTO i FROM public.billium_settlement_intent WHERE id=NEW.settlement_intent_id AND seller_ref=NEW.seller_ref AND environment=NEW.environment FOR UPDATE;
                    IF NOT FOUND OR i.status='closed_no_effect' OR (i.installation_ref,i.billing_account_ref,i.currency) IS DISTINCT FROM (NEW.installation_ref,NEW.billing_account_ref,NEW.currency)
                    THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                    UPDATE public.billium_settlement_intent SET revision=revision+1,status=CASE WHEN NEW.captured_minor>0 OR NEW.refunded_minor>0 OR NEW.allocated_minor>0 THEN 'applied' ELSE status END WHERE id=i.id;
                END IF;
                RETURN NEW;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_payment_intent_guard() FROM PUBLIC;

            CREATE TRIGGER billium_payment_intent_guard BEFORE INSERT ON public.billium_payment FOR EACH ROW EXECUTE FUNCTION public.billium_payment_intent_guard();

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
            REVOKE ALL ON FUNCTION public.billium_record_refund_detail(p_installation text, p_seller text, p_environment text, p_observed bigint, p_revision bigint, p_payment bigint, p_receipt text, p_digest text, p_discrepancy jsonb) FROM PUBLIC;
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('Billium migrations are forward-only.');
    }
};

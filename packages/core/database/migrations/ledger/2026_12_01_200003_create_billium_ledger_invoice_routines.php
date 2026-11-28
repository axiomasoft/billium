<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

/**
 * Ledger invoice routines: the guarded invoice state machine.
 */
return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Guarded invoice FSM routines. Locks follow the global lock order: line obligations (ids asc) then invoice.
            -- Every routine takes the same rows in that order, so a runner prelock makes the acquisitions reentrant.
            -- Receipt replay is decided AFTER the locks: a concurrent twin waits and then replays instead of seeing a changed state.
            CREATE OR REPLACE FUNCTION public.billium_invoice_replay(
                p_installation text,p_seller text,p_environment text,p_account text,p_currency text,p_receipt text,p_kind text,p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE r public.billium_allocation_receipt;
            BEGIN
                SELECT * INTO r FROM public.billium_allocation_receipt WHERE seller_ref=p_seller AND environment=p_environment AND receipt_ref=p_receipt;
                IF NOT FOUND THEN RETURN NULL; END IF;
                IF (r.installation_ref,r.billing_account_ref,r.currency,r.kind,r.semantic_digest) IS DISTINCT FROM (p_installation,p_account,p_currency,p_kind,p_digest)
                THEN RAISE EXCEPTION 'billium.idempotency.conflict' USING ERRCODE='P0001'; END IF;
                RETURN r.body;
            END $$;
            REVOKE ALL ON FUNCTION public.billium_invoice_replay(p_installation text, p_seller text, p_environment text, p_account text, p_currency text, p_receipt text, p_kind text, p_digest text) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.billium_create_invoice(
                p_installation text,p_seller text,p_environment text,p_account text,p_currency text,
                p_ref text,p_customer text,p_snapshot jsonb,p_lines jsonb,p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                v_receipt text := 'invoice-create:' || p_ref; v_replay jsonb; v_ids bigint[]; v_body jsonb; i public.billium_invoice; o public.billium_obligation;
                l record; v_amount numeric; v_tax numeric; v_total numeric := 0; v_tax_total numeric := 0; v_alloc numeric := 0; v_cred numeric := 0;
            BEGIN
                IF p_lines IS NULL OR jsonb_typeof(p_lines) <> 'array' OR jsonb_array_length(p_lines) = 0 OR p_customer IS NULL OR p_customer = ''
                   OR p_snapshot IS NULL OR jsonb_typeof(p_snapshot) <> 'object'
                THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                SELECT array_agg((e.value->>'obligation_id')::bigint ORDER BY e.ordinality) INTO v_ids FROM jsonb_array_elements(p_lines) WITH ORDINALITY e(value, ordinality);
                IF array_position(v_ids, NULL) IS NOT NULL OR cardinality(v_ids) <> (SELECT count(DISTINCT x) FROM unnest(v_ids) x)
                THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                PERFORM 1 FROM public.billium_obligation WHERE id = ANY(v_ids) AND seller_ref = p_seller AND environment = p_environment ORDER BY id FOR UPDATE;
                v_replay := public.billium_invoice_replay(p_installation,p_seller,p_environment,p_account,p_currency,v_receipt,'invoice_create',p_digest);
                IF v_replay IS NOT NULL THEN RETURN jsonb_build_object('body',v_replay,'replayed',true); END IF;
                FOR l IN SELECT e.value AS value FROM jsonb_array_elements(p_lines) WITH ORDINALITY e(value, ordinality) ORDER BY e.ordinality LOOP
                    SELECT * INTO o FROM public.billium_obligation WHERE id=(l.value->>'obligation_id')::bigint AND seller_ref=p_seller AND environment=p_environment;
                    IF NOT FOUND OR (o.installation_ref,o.billing_account_ref) IS DISTINCT FROM (p_installation,p_account) THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                    IF o.currency <> p_currency THEN RAISE EXCEPTION 'billium.money.currency_mismatch' USING ERRCODE='P0001'; END IF;
                    IF o.billed_invoice_id IS NOT NULL THEN RAISE EXCEPTION 'billium.invoice.frozen' USING ERRCODE='P0001'; END IF;
                    IF o.status = 'cancelled' OR o.customer_ref <> p_customer THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                    v_amount := o.amount_due_minor::numeric + o.adjusted_minor;
                    v_tax := (l.value->>'tax_minor')::numeric;
                    IF v_tax IS NULL OR v_tax < 0 OR v_tax > v_amount OR (l.value ? 'snapshot' AND jsonb_typeof(l.value->'snapshot') <> 'object')
                    THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                    v_total := v_total + v_amount; v_tax_total := v_tax_total + v_tax; v_alloc := v_alloc + o.allocated_minor; v_cred := v_cred + o.credited_minor;
                END LOOP;
                INSERT INTO public.billium_invoice(installation_ref,seller_ref,environment,billing_account_ref,currency,invoice_ref,customer_ref,
                    subtotal_minor,tax_minor,total_minor,allocated_minor,credited_minor,snapshot)
                VALUES(p_installation,p_seller,p_environment,p_account,p_currency,p_ref,p_customer,
                    (v_total - v_tax_total)::bigint,v_tax_total::bigint,v_total::bigint,v_alloc::bigint,v_cred::bigint,p_snapshot) RETURNING * INTO i;
                v_body := jsonb_build_object('receipt_ref',v_receipt,'invoice_ref',p_ref,'status','draft','revision',i.revision,'total_minor',v_total::bigint::text);
                PERFORM public.billium_ledger_receipt(p_installation,p_seller,p_environment,p_account,p_currency,v_receipt,'invoice_create',p_digest,v_body);
                FOR l IN SELECT e.value AS value, e.ordinality AS pos FROM jsonb_array_elements(p_lines) WITH ORDINALITY e(value, ordinality) ORDER BY e.ordinality LOOP
                    SELECT * INTO o FROM public.billium_obligation WHERE id=(l.value->>'obligation_id')::bigint AND seller_ref=p_seller AND environment=p_environment;
                    v_amount := o.amount_due_minor::numeric + o.adjusted_minor;
                    v_tax := (l.value->>'tax_minor')::numeric;
                    INSERT INTO public.billium_invoice_line(installation_ref,seller_ref,environment,billing_account_ref,currency,invoice_id,obligation_id,position,subtotal_minor,tax_minor,amount_minor,snapshot)
                    VALUES(p_installation,p_seller,p_environment,p_account,p_currency,i.id,o.id,(l.pos - 1)::integer,(v_amount - v_tax)::bigint,v_tax::bigint,v_amount::bigint,COALESCE(l.value->'snapshot','{}'::jsonb));
                    UPDATE public.billium_obligation SET billed_invoice_id = i.id, revision = revision + 1 WHERE id = o.id;
                END LOOP;
                RETURN jsonb_build_object('body',v_body,'replayed',false);
            END $$;
            REVOKE ALL ON FUNCTION public.billium_create_invoice(p_installation text, p_seller text, p_environment text, p_account text, p_currency text, p_ref text, p_customer text, p_snapshot jsonb, p_lines jsonb, p_digest text) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.billium_update_invoice_metadata(
                p_installation text,p_seller text,p_environment text,p_account text,p_currency text,
                p_invoice bigint,p_update text,p_patch jsonb,p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE i public.billium_invoice; v_receipt text; v_replay jsonb; v_body jsonb;
            BEGIN
                SELECT * INTO i FROM public.billium_invoice WHERE id=p_invoice AND seller_ref=p_seller AND environment=p_environment FOR UPDATE;
                IF NOT FOUND OR (i.installation_ref,i.billing_account_ref,i.currency) IS DISTINCT FROM (p_installation,p_account,p_currency) THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                v_receipt := 'invoice-metadata:' || i.invoice_ref || ':' || p_update;
                v_replay := public.billium_invoice_replay(p_installation,p_seller,p_environment,p_account,p_currency,v_receipt,'invoice_metadata',p_digest);
                IF v_replay IS NOT NULL THEN RETURN jsonb_build_object('body',v_replay,'replayed',true); END IF;
                IF p_patch IS NULL OR jsonb_typeof(p_patch) <> 'object' THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                -- After issue only allow-list keys may change; the same keys are the only valid ones in a draft.
                IF EXISTS (SELECT FROM jsonb_object_keys(p_patch) k WHERE k NOT IN ('note','external_ref','labels')) THEN
                    IF i.status <> 'draft' THEN RAISE EXCEPTION 'billium.invoice.frozen' USING ERRCODE='P0001'; END IF;
                    RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001';
                END IF;
                UPDATE public.billium_invoice SET metadata = metadata || p_patch, revision = revision + 1 WHERE id = i.id RETURNING revision INTO i.revision;
                v_body := jsonb_build_object('receipt_ref',v_receipt,'invoice_ref',i.invoice_ref,'status',i.status,'revision',i.revision);
                PERFORM public.billium_ledger_receipt(p_installation,p_seller,p_environment,p_account,p_currency,v_receipt,'invoice_metadata',p_digest,v_body);
                RETURN jsonb_build_object('body',v_body,'replayed',false);
            END $$;
            REVOKE ALL ON FUNCTION public.billium_update_invoice_metadata(p_installation text, p_seller text, p_environment text, p_account text, p_currency text, p_invoice bigint, p_update text, p_patch jsonb, p_digest text) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.billium_issue_invoice(
                p_installation text,p_seller text,p_environment text,p_account text,p_currency text,
                p_invoice bigint,p_legal text,p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                i public.billium_invoice; v_receipt text; v_replay jsonb; v_body jsonb; v_count bigint; v_ok boolean;
                v_total numeric; v_tax numeric; v_alloc numeric; v_cred numeric; v_status text; v_revision bigint;
            BEGIN
                PERFORM 1 FROM public.billium_obligation WHERE seller_ref=p_seller AND environment=p_environment AND id IN (
                    SELECT obligation_id FROM public.billium_invoice_line WHERE invoice_id=p_invoice AND seller_ref=p_seller AND environment=p_environment) ORDER BY id FOR UPDATE;
                SELECT * INTO i FROM public.billium_invoice WHERE id=p_invoice AND seller_ref=p_seller AND environment=p_environment FOR UPDATE;
                IF NOT FOUND OR (i.installation_ref,i.billing_account_ref,i.currency) IS DISTINCT FROM (p_installation,p_account,p_currency) THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                v_receipt := 'invoice-issue:' || i.invoice_ref;
                v_replay := public.billium_invoice_replay(p_installation,p_seller,p_environment,p_account,p_currency,v_receipt,'invoice_issue',p_digest);
                IF v_replay IS NOT NULL THEN RETURN jsonb_build_object('body',v_replay,'replayed',true); END IF;
                IF i.status <> 'draft' THEN RAISE EXCEPTION 'billium.invoice.transition_invalid' USING ERRCODE='P0001'; END IF;
                IF p_legal IS NOT NULL AND p_legal = '' THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                SELECT count(*), sum(o.amount_due_minor::numeric + o.adjusted_minor), sum(l.tax_minor::numeric), sum(o.allocated_minor::numeric), sum(o.credited_minor::numeric),
                       bool_and(o.billed_invoice_id = p_invoice AND l.tax_minor <= o.amount_due_minor::numeric + o.adjusted_minor)
                  INTO v_count, v_total, v_tax, v_alloc, v_cred, v_ok
                  FROM public.billium_invoice_line l JOIN public.billium_obligation o ON o.id = l.obligation_id
                 WHERE l.invoice_id = p_invoice AND l.seller_ref = p_seller AND l.environment = p_environment;
                IF v_count = 0 OR v_ok IS NOT TRUE THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                -- Draft obligations may be adjusted until now; the issued snapshot takes their current amounts (Σ line = total).
                UPDATE public.billium_invoice_line l SET amount_minor = (o.amount_due_minor::numeric + o.adjusted_minor)::bigint,
                    subtotal_minor = (o.amount_due_minor::numeric + o.adjusted_minor - l.tax_minor)::bigint
                  FROM public.billium_obligation o WHERE o.id = l.obligation_id AND l.invoice_id = p_invoice AND l.seller_ref = p_seller AND l.environment = p_environment;
                UPDATE public.billium_invoice SET status='issued', issued_at=now(), legal_document_ref=p_legal,
                    subtotal_minor=(v_total - v_tax)::bigint, tax_minor=v_tax::bigint, total_minor=v_total::bigint,
                    allocated_minor=v_alloc::bigint, credited_minor=v_cred::bigint, revision=revision+1 WHERE id=i.id RETURNING revision INTO v_revision;
                -- Derived status in the same Tx. draft -> issued is the only legal first step; payment states follow.
                v_status := CASE WHEN v_alloc + v_cred = 0 THEN 'issued' WHEN v_alloc + v_cred >= v_total THEN 'paid' ELSE 'partially_paid' END;
                IF v_status <> 'issued' THEN
                    UPDATE public.billium_invoice SET status=v_status, revision=revision+1 WHERE id=i.id RETURNING revision INTO v_revision;
                END IF;
                v_body := jsonb_build_object('receipt_ref',v_receipt,'invoice_ref',i.invoice_ref,'status',v_status,'revision',v_revision,
                    'total_minor',v_total::bigint::text,'currency',p_currency,'billing_account_ref',p_account,'legal_document_ref',p_legal);
                PERFORM public.billium_ledger_receipt(p_installation,p_seller,p_environment,p_account,p_currency,v_receipt,'invoice_issue',p_digest,v_body);
                RETURN jsonb_build_object('body',v_body,'replayed',false);
            END $$;
            REVOKE ALL ON FUNCTION public.billium_issue_invoice(p_installation text, p_seller text, p_environment text, p_account text, p_currency text, p_invoice bigint, p_legal text, p_digest text) FROM PUBLIC;

            -- discard (draft) and void (issued, settled = 0) both release their obligations for a new invoice; lines stay as history.
            CREATE OR REPLACE FUNCTION public.billium_close_invoice(
                p_installation text,p_seller text,p_environment text,p_account text,p_currency text,
                p_invoice bigint,p_target text,p_cause text,p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE i public.billium_invoice; v_receipt text; v_replay jsonb; v_body jsonb; v_revision bigint; v_from text; v_kind text;
            BEGIN
                IF p_target NOT IN ('discarded','void') THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                PERFORM 1 FROM public.billium_obligation WHERE seller_ref=p_seller AND environment=p_environment AND id IN (
                    SELECT obligation_id FROM public.billium_invoice_line WHERE invoice_id=p_invoice AND seller_ref=p_seller AND environment=p_environment) ORDER BY id FOR UPDATE;
                SELECT * INTO i FROM public.billium_invoice WHERE id=p_invoice AND seller_ref=p_seller AND environment=p_environment FOR UPDATE;
                IF NOT FOUND OR (i.installation_ref,i.billing_account_ref,i.currency) IS DISTINCT FROM (p_installation,p_account,p_currency) THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                -- A CASE inside an IF condition would end the condition at its THEN, so the variants are resolved first.
                v_from := CASE WHEN p_target = 'void' THEN 'issued' ELSE 'draft' END;
                v_kind := CASE WHEN p_target = 'void' THEN 'invoice_void' ELSE 'invoice_discard' END;
                v_receipt := replace(v_kind, '_', '-') || ':' || i.invoice_ref;
                v_replay := public.billium_invoice_replay(p_installation,p_seller,p_environment,p_account,p_currency,v_receipt,v_kind,p_digest);
                IF v_replay IS NOT NULL THEN RETURN jsonb_build_object('body',v_replay,'replayed',true); END IF;
                IF i.status <> v_from OR (p_target = 'void' AND i.allocated_minor::numeric + i.credited_minor <> 0)
                THEN RAISE EXCEPTION 'billium.invoice.transition_invalid' USING ERRCODE='P0001'; END IF;
                UPDATE public.billium_obligation SET billed_invoice_id = NULL, revision = revision + 1
                 WHERE seller_ref = p_seller AND environment = p_environment AND billed_invoice_id = i.id;
                UPDATE public.billium_invoice SET status = p_target, revision = revision + 1 WHERE id = i.id RETURNING revision INTO v_revision;
                v_body := jsonb_build_object('receipt_ref',v_receipt,'invoice_ref',i.invoice_ref,'status',p_target,'revision',v_revision,'cause_ref',p_cause);
                PERFORM public.billium_ledger_receipt(p_installation,p_seller,p_environment,p_account,p_currency,v_receipt,v_kind,p_digest,v_body);
                RETURN jsonb_build_object('body',v_body,'replayed',false);
            END $$;
            REVOKE ALL ON FUNCTION public.billium_close_invoice(p_installation text, p_seller text, p_environment text, p_account text, p_currency text, p_invoice bigint, p_target text, p_cause text, p_digest text) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.billium_mark_invoice_uncollectible(
                p_installation text,p_seller text,p_environment text,p_account text,p_currency text,
                p_invoice bigint,p_reason text,p_authorization text,p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE i public.billium_invoice; v_receipt text; v_replay jsonb; v_body jsonb; v_revision bigint;
            BEGIN
                SELECT * INTO i FROM public.billium_invoice WHERE id=p_invoice AND seller_ref=p_seller AND environment=p_environment FOR UPDATE;
                IF NOT FOUND OR (i.installation_ref,i.billing_account_ref,i.currency) IS DISTINCT FROM (p_installation,p_account,p_currency) THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                v_receipt := 'invoice-uncollectible:' || i.invoice_ref;
                v_replay := public.billium_invoice_replay(p_installation,p_seller,p_environment,p_account,p_currency,v_receipt,'invoice_uncollectible',p_digest);
                IF v_replay IS NOT NULL THEN RETURN jsonb_build_object('body',v_replay,'replayed',true); END IF;
                IF i.status NOT IN ('issued','partially_paid') THEN RAISE EXCEPTION 'billium.invoice.transition_invalid' USING ERRCODE='P0001'; END IF;
                UPDATE public.billium_invoice SET status='uncollectible', revision=revision+1 WHERE id=i.id RETURNING revision INTO v_revision;
                -- Reason and operator authorization stay in the immutable receipt.
                v_body := jsonb_build_object('receipt_ref',v_receipt,'invoice_ref',i.invoice_ref,'status','uncollectible','revision',v_revision,
                    'reason_ref',p_reason,'authorization_ref',p_authorization);
                PERFORM public.billium_ledger_receipt(p_installation,p_seller,p_environment,p_account,p_currency,v_receipt,'invoice_uncollectible',p_digest,v_body);
                RETURN jsonb_build_object('body',v_body,'replayed',false);
            END $$;
            REVOKE ALL ON FUNCTION public.billium_mark_invoice_uncollectible(p_installation text, p_seller text, p_environment text, p_account text, p_currency text, p_invoice bigint, p_reason text, p_authorization text, p_digest text) FROM PUBLIC;
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('Billium migrations are forward-only.');
    }
};

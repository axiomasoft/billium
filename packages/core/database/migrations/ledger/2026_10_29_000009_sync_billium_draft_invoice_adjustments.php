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
            REVOKE ALL ON FUNCTION public.billium_adjust_obligation(text,text,text,text,text,text,bigint,bigint,text,text) FROM PUBLIC;
            SQL);
        app(LedgerPrivileges::class)->grant();
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

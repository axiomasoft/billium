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
            -- least() skips NULL, and NULL <> x is not true: a missing total used to pass validation and then take the
            -- equal-totals branch, resolving every open refund-basis mismatch of the payment without a comparison.
            CREATE OR REPLACE FUNCTION public.billium_reconcile_refund_basis(
                p_installation text,p_seller text,p_environment text,p_payment bigint,p_charge_revision bigint,p_basis text,p_refund_revision bigint,
                p_charge_refunded bigint,p_detail_refunded bigint,p_evidence text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE p public.billium_payment; o public.billium_observed_payment; d public.billium_ledger_discrepancy; v_fingerprint text; v_id bigint; v_resolved jsonb := '[]'::jsonb;
            BEGIN
                IF p_basis IS NULL OR p_basis !~ '^sha256:[0-9a-f]{64}$' OR p_charge_refunded IS NULL OR p_detail_refunded IS NULL
                   OR least(p_charge_refunded,p_detail_refunded) < 0 OR p_evidence IS NULL OR p_evidence = ''
                THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                SELECT * INTO p FROM public.billium_payment WHERE id=p_payment AND seller_ref=p_seller AND environment=p_environment FOR UPDATE;
                IF NOT FOUND OR p.installation_ref IS DISTINCT FROM p_installation OR p.kind <> 'charge' THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                -- The comparison was read before the lock: a moved charge basis is a new comparison, not this one.
                IF p.applied_revision IS DISTINCT FROM p_charge_revision OR p.refunded_minor IS DISTINCT FROM p_charge_refunded
                THEN RAISE EXCEPTION 'billium: refund basis moved' USING ERRCODE='40001'; END IF;
                SELECT * INTO o FROM public.billium_observed_payment WHERE seller_ref=p_seller AND environment=p_environment AND source_authority=p.source_authority AND operation_ref=p.operation_ref ORDER BY id LIMIT 1;
                IF NOT FOUND THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                INSERT INTO public.billium_refund_reconcile_progress(seller_ref,environment,payment_id,compared_at)
                VALUES(p_seller,p_environment,p.id,clock_timestamp())
                ON CONFLICT (payment_id) DO UPDATE SET compared_at=EXCLUDED.compared_at;
                -- Keep pre-upgrade discrepancy identities for this observed charge. New fingerprints are source-bound.
                SELECT fingerprint INTO v_fingerprint FROM public.billium_ledger_discrepancy
                    WHERE seller_ref=p_seller AND environment=p_environment AND observed_payment_id=o.id
                      AND reason='refund_basis_mismatch' AND comparison_basis_digest=p_basis ORDER BY id LIMIT 1;
                IF NOT FOUND THEN v_fingerprint := 'refund_basis:' || p.source_authority || ':' || p.payment_ref || ':' || p_basis; END IF;
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
            REVOKE ALL ON FUNCTION public.billium_reconcile_refund_basis(text,text,text,bigint,bigint,text,bigint,bigint,bigint,text) FROM PUBLIC;
            SQL);
        app(LedgerPrivileges::class)->grant();
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

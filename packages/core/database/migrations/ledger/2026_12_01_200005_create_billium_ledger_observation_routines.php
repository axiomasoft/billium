<?php

declare(strict_types=1);

use Billium\Ledger\Internal\Repositories\LedgerPrivileges;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

/**
 * Ledger observation routines: observed payment custody, payment projection, refund reconciliation and parking,
 * then the ledger runtime privileges.
 */
return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Guarded observation routines. The observed fact (custody of the
            -- provider snapshot) is separate from the applied projection and from allocation. Totals are assigned from the
            -- immutable observed revision row, never added as deltas and never taken from a caller argument.
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
            dependency_kind text NOT NULL,
            dependency_ref text NOT NULL CHECK (dependency_ref <> ''),
            released_at timestamptz NULL,
            release_reason text NULL CHECK (release_reason IS NULL OR release_reason <> ''),
            created_at timestamptz NOT NULL DEFAULT now(),
            CHECK ((released_at IS NULL) = (release_reason IS NULL)),
            FOREIGN KEY (seller_ref,environment,observed_payment_id,revision) REFERENCES public.billium_observed_payment_revision(seller_ref,environment,observed_payment_id,revision),
                CONSTRAINT billium_observation_park_dependency_kind_check
                    CHECK (dependency_kind IN ('settlement_intent','original_charge','operation_revision'))
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
            REVOKE ALL ON FUNCTION public.billium_observation_park_guard() FROM PUBLIC;

            CREATE TRIGGER billium_observation_park_guard BEFORE UPDATE OR DELETE ON public.billium_observation_park FOR EACH ROW EXECUTE FUNCTION public.billium_observation_park_guard();

            -- Observed fact of one revision. The economic identity is (seller, environment, sourceAuthority, operationRef):
            -- a restored source lineage reuses it instead of creating a second one. Actual values may exceed normal
            -- bounds; they are preserved as observed. Dispositions: new | advanced | stale | duplicate | conflict |
            -- contradiction (immutable installation/kind/currency/provider account/original differ).
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
                    requested_minor,authorized_minor,captured_minor,refunded_minor,voided_minor,currency,provider_account_ref,evidence_ref,payload)
                VALUES(p_installation,p_seller,p_environment,o.id,p_revision,p_material,p_state,p_requested,p_authorized,p_captured,p_refunded,0,p_currency,p_provider_account,p_evidence,
                    coalesce(p_payload,'{}'::jsonb));
                RETURN jsonb_build_object('body',jsonb_build_object('observed_id',o.id,'disposition',v_disposition,
                    'revision',CASE WHEN v_disposition='contradiction' THEN o.revision ELSE greatest(o.revision,p_revision) END),'replayed',false);
            END $$;
            REVOKE ALL ON FUNCTION public.billium_record_observation(p_installation text, p_seller text, p_environment text, p_authority text, p_lineage text, p_operation text, p_kind text, p_original text, p_revision bigint, p_material text, p_state text, p_currency text, p_requested bigint, p_authorized bigint, p_captured bigint, p_refunded bigint, p_provider_account text, p_evidence text, p_payload jsonb) FROM PUBLIC;

            -- Applied projection of one CHARGE revision under the runner's prelocks (intent 65 → obligations 70 → invoices 80 →
            -- payment 90). Modes: assign (ordinary consistent snapshot: totals assigned, refund basis, pro_rata_open/1 parts),
            -- hold (exceptional actual or source discrepancy: last consistent applied totals stay, financial hold, discrepancy),
            -- stale (compatible older revision: no monetary delta). Receipt, revision journal, allocations, discrepancy and
            -- counters commit together; the guards check every allocation row.
            -- An over-capture cannot enter the applied projection through the granted routine.
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
                       OR coalesce(r.provider_account_ref, r.payload->>'providerAccountRef', o.provider_account_ref) IS DISTINCT FROM o.provider_account_ref
                       OR EXISTS (SELECT 1 FROM public.billium_observed_payment_revision rc
                           WHERE rc.observed_payment_id=o.id AND (rc.currency IS DISTINCT FROM o.currency
                               OR coalesce(rc.provider_account_ref, rc.payload->>'providerAccountRef', o.provider_account_ref) IS DISTINCT FROM o.provider_account_ref))
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
                    -- Cumulative totals are assigned and never regress; an inconsistent snapshot belongs to mode hold.
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
            REVOKE ALL ON FUNCTION public.billium_project_payment(p_installation text, p_seller text, p_environment text, p_account text, p_currency text, p_observed bigint, p_revision bigint, p_payment_ref text, p_intent bigint, p_mode text, p_parts jsonb, p_discrepancy jsonb, p_receipt text, p_digest text) FROM PUBLIC;

            -- A missing dependency keeps the observed fact and parks allocation only; the same delivery parks once.
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
            REVOKE ALL ON FUNCTION public.billium_park_observation(p_installation text, p_seller text, p_environment text, p_observed bigint, p_revision bigint, p_inbox bigint, p_kind text, p_ref text) FROM PUBLIC;

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
            REVOKE ALL ON FUNCTION public.billium_release_observation_park(p_seller text, p_environment text, p_park bigint, p_inbox bigint, p_reason text) FROM PUBLIC;

            -- Reconcile after the consistency window: the charge refunded basis against the ordered succeeded refund
            -- details. The basis digest names the charge revision and every contributing refund revision/amount; an identical
            -- basis dedupes by fingerprint, a new basis is a new row, agreement appends resolution events. The ledger and the
            -- financial hold never change here.
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
            REVOKE ALL ON FUNCTION public.billium_reconcile_refund_basis(p_installation text, p_seller text, p_environment text, p_payment bigint, p_charge_revision bigint, p_basis text, p_refund_revision bigint, p_charge_refunded bigint, p_detail_refunded bigint, p_evidence text) FROM PUBLIC;

            -- Least recently compared first: a bounded worker advances across invocations and revisits every eligible charge.
            CREATE TABLE public.billium_refund_reconcile_progress (
                seller_ref text NOT NULL,
                environment text NOT NULL,
                payment_id bigint PRIMARY KEY,
                compared_at timestamptz NOT NULL,
                FOREIGN KEY (seller_ref,environment,payment_id) REFERENCES public.billium_payment(seller_ref,environment,id)
            );

            -- Keyed replacement of the existing policy, with an immutable receipt carrying its provenance.
            CREATE OR REPLACE FUNCTION public.billium_amend_obligation_due_policy(
                p_installation text,p_seller text,p_environment text,p_account text,p_obligation bigint,
                p_due timestamptz,p_version text,p_policy_ref text,p_policy_digest text,p_eligible timestamptz,p_receipt text,p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE o public.billium_obligation; r public.billium_allocation_receipt; v_body jsonb;
            BEGIN
                IF coalesce(p_version,'')='' OR coalesce(p_policy_ref,'')='' OR p_policy_digest IS NULL OR p_policy_digest !~ '^sha256:[0-9a-f]{64}$'
                   OR coalesce(p_receipt,'')='' OR p_digest IS NULL OR p_digest !~ '^sha256:[0-9a-f]{64}$'
                THEN RAISE EXCEPTION 'billium.validation.invalid' USING ERRCODE='P0001'; END IF;
                SELECT * INTO o FROM public.billium_obligation WHERE id=p_obligation AND seller_ref=p_seller AND environment=p_environment FOR UPDATE;
                IF NOT FOUND OR (o.installation_ref,o.billing_account_ref) IS DISTINCT FROM (p_installation,p_account)
                THEN RAISE EXCEPTION 'billium.reference.unknown' USING ERRCODE='P0001'; END IF;
                SELECT * INTO r FROM public.billium_allocation_receipt WHERE seller_ref=p_seller AND environment=p_environment AND receipt_ref=p_receipt;
                IF FOUND THEN
                    IF (r.installation_ref,r.billing_account_ref,r.kind,r.semantic_digest) IS DISTINCT FROM (p_installation,p_account,'obligation_due_policy'::text,p_digest)
                    THEN RAISE EXCEPTION 'billium.idempotency.conflict' USING ERRCODE='P0001'; END IF;
                    RETURN jsonb_build_object('body',r.body,'replayed',true);
                END IF;
                UPDATE public.billium_obligation SET due_at=p_due,due_policy_version=p_version,source_policy_ref=p_policy_ref,
                    source_policy_snapshot_digest=p_policy_digest,collection_eligible_at=p_eligible,revision=revision+1
                    WHERE id=o.id RETURNING * INTO o;
                v_body := jsonb_build_object('receipt_ref',p_receipt,'obligation_ref',o.obligation_ref,'billing_account_ref',p_account,
                    'currency',o.currency,'revision',o.revision,'due_at',o.due_at,'due_policy_version',o.due_policy_version,
                    'source_policy_ref',o.source_policy_ref,'source_policy_snapshot_digest',o.source_policy_snapshot_digest,
                    'collection_eligible_at',o.collection_eligible_at);
                PERFORM public.billium_ledger_receipt(p_installation,p_seller,p_environment,p_account,o.currency,p_receipt,'obligation_due_policy',p_digest,v_body);
                RETURN jsonb_build_object('body',v_body,'replayed',false);
            END $$;
            REVOKE ALL ON FUNCTION public.billium_amend_obligation_due_policy(p_installation text, p_seller text, p_environment text, p_account text, p_obligation bigint, p_due timestamp with time zone, p_version text, p_policy_ref text, p_policy_digest text, p_eligible timestamp with time zone, p_receipt text, p_digest text) FROM PUBLIC;

            CREATE TABLE public.billium_observation_owner_binding (
                operation_key text PRIMARY KEY CHECK (operation_key ~ '^[0-9a-f]{64}$'),
                installation_ref text NOT NULL CHECK (installation_ref <> ''),
                seller_ref text NOT NULL CHECK (seller_ref <> ''),
                environment text NOT NULL CHECK (environment <> ''),
                source_authority text NOT NULL CHECK (source_authority <> ''),
                provider_account_ref text NOT NULL CHECK (provider_account_ref <> ''),
                operation_ref text NOT NULL CHECK (operation_ref <> ''),
                root_operation_ref text NOT NULL CHECK (root_operation_ref <> ''),
                intent_owner_namespace text NOT NULL CHECK (intent_owner_namespace <> '')
            );

            CREATE OR REPLACE FUNCTION public.billium_claim_observation_owner(
                p_installation text,p_seller text,p_env text,p_source text,p_account text,p_op text,p_root text,p_owner text,p_payload jsonb)
            RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp AS $$
            DECLARE ref text; key text; binding public.billium_observation_owner_binding; historical public.billium_observed_payment;
            BEGIN
                -- Serialize against the established economic identity, including neutral callers and refund-first delivery.
                FOR ref IN SELECT DISTINCT v FROM unnest(ARRAY[p_op,p_root]) v ORDER BY v LOOP
                    PERFORM pg_advisory_xact_lock(hashtextextended(jsonb_build_array('billium.observation.owner',p_seller,p_env,p_source,ref)::text,0));
                END LOOP;
                FOR ref IN SELECT DISTINCT v FROM unnest(ARRAY[p_op,p_root]) v ORDER BY v LOOP
                    key := encode(sha256(convert_to(jsonb_build_array(p_seller,p_env,p_source,ref)::text,'UTF8')),'hex');
                    SELECT * INTO binding FROM public.billium_observation_owner_binding b WHERE b.operation_key=key;
                    IF FOUND THEN
                        IF (binding.installation_ref,binding.seller_ref,binding.environment,binding.source_authority,binding.provider_account_ref,
                            binding.operation_ref,binding.root_operation_ref,binding.intent_owner_namespace)
                            IS DISTINCT FROM (p_installation,p_seller,p_env,p_source,p_account,ref,p_root,p_owner) THEN RETURN 'denied'; END IF;
                    ELSIF p_owner IS NOT NULL THEN
                        SELECT * INTO historical FROM public.billium_observed_payment o
                        WHERE o.seller_ref=p_seller AND o.environment=p_env AND o.source_authority=p_source AND o.operation_ref=ref;
                        IF FOUND AND (historical.installation_ref<>p_installation OR historical.provider_account_ref<>p_account
                            OR coalesce(historical.original_ref,ref)<>p_root OR EXISTS (
                                SELECT FROM public.billium_observed_payment_revision r WHERE r.observed_payment_id=historical.id
                                AND NOT EXISTS (SELECT FROM public.billium_observed_revision_owner_proof proof WHERE proof.revision_id=r.id
                                    AND proof.intent_owner_namespace=p_owner AND proof.root_operation_ref=p_root))
                        ) THEN RETURN 'unproven'; END IF;
                    END IF;

                END LOOP;
                IF p_owner IS NOT NULL THEN
                    FOR ref IN SELECT DISTINCT v FROM unnest(ARRAY[p_op,p_root]) v ORDER BY v LOOP
                        key := encode(sha256(convert_to(jsonb_build_array(p_seller,p_env,p_source,ref)::text,'UTF8')),'hex');
                        INSERT INTO public.billium_observation_owner_binding VALUES (key,p_installation,p_seller,p_env,p_source,p_account,ref,p_root,p_owner)
                        ON CONFLICT (operation_key) DO NOTHING;
                        IF NOT EXISTS (SELECT FROM public.billium_observation_owner_binding b WHERE b.operation_key=key
                            AND (b.installation_ref,b.seller_ref,b.environment,b.source_authority,b.provider_account_ref,b.operation_ref,b.root_operation_ref,b.intent_owner_namespace)
                              =(p_installation,p_seller,p_env,p_source,p_account,ref,p_root,p_owner)) THEN RAISE EXCEPTION 'billium.observation.conflict'; END IF;
                    END LOOP;
                END IF;
                RETURN 'accepted';
            END $$;
            REVOKE ALL ON FUNCTION public.billium_claim_observation_owner(p_installation text, p_seller text, p_env text, p_source text, p_account text, p_op text, p_root text, p_owner text, p_payload jsonb) FROM PUBLIC;

            CREATE TABLE public.billium_observed_revision_owner_proof (
                revision_id bigint PRIMARY KEY REFERENCES public.billium_observed_payment_revision(id),
                intent_owner_namespace text NOT NULL CHECK (intent_owner_namespace<>''),
                root_operation_ref text NOT NULL CHECK (root_operation_ref<>''),
                proof_digest text NOT NULL CHECK (proof_digest ~ '^sha256:[0-9a-f]{64}$')
            );

            CREATE FUNCTION public.billium_prove_observation_revision(
                p_installation text,p_seller text,p_env text,p_source text,p_account text,p_op text,p_root text,p_owner text,p_payload jsonb,p_proof text)
            RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp AS $$
            DECLARE v_revision bigint;
            BEGIN
                SELECT r.id INTO v_revision FROM public.billium_observed_payment o JOIN public.billium_observed_payment_revision r ON r.observed_payment_id=o.id
                    WHERE (o.installation_ref,o.seller_ref,o.environment,o.source_authority,o.provider_account_ref,o.operation_ref,coalesce(o.original_ref,o.operation_ref))
                        =(p_installation,p_seller,p_env,p_source,p_account,p_op,p_root) AND r.payload=p_payload;
                IF NOT FOUND THEN RETURN 'absent'; END IF;
                INSERT INTO public.billium_observed_revision_owner_proof VALUES (v_revision,p_owner,p_root,p_proof)
                    ON CONFLICT ON CONSTRAINT billium_observed_revision_owner_proof_pkey DO NOTHING;
                IF NOT EXISTS (SELECT FROM public.billium_observed_revision_owner_proof proof WHERE proof.revision_id=v_revision
                        AND (proof.intent_owner_namespace,proof.root_operation_ref)=(p_owner,p_root)) THEN RETURN 'denied'; END IF;
                RETURN 'proven';
            END $$;
            REVOKE ALL ON FUNCTION public.billium_prove_observation_revision(p_installation text, p_seller text, p_env text, p_source text, p_account text, p_op text, p_root text, p_owner text, p_payload jsonb, p_proof text) FROM PUBLIC;
            SQL);

        app(LedgerPrivileges::class)->grant();
    }

    public function down(): never
    {
        throw new LogicException('Billium migrations are forward-only.');
    }
};

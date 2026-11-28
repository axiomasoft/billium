<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

/**
 * Rating pricing: price publications, versions and tiers, commitment terms and tax snapshots.
 */
return new class extends PackmanMigration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Publication, price and tax evidence belong to rating.
            CREATE TABLE public.billium_rating_price_publication (
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                publication_key text COLLATE "C" NOT NULL,
                price_ref text NOT NULL,
                version integer NOT NULL CHECK (version > 0),
                terms_digest text NOT NULL,
                event_type text NOT NULL CHECK (event_type = 'rating.price_version.published.v1'),
                payload jsonb NOT NULL CHECK (jsonb_typeof(payload) = 'object' AND octet_length(payload::text) <= 65536),
                PRIMARY KEY (seller_ref,environment,publication_key),
                UNIQUE (seller_ref,environment,price_ref,version)
            );

            CREATE TRIGGER billium_rating_publication_immutable BEFORE UPDATE OR DELETE ON public.billium_rating_price_publication FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE TABLE public.billium_rating_price_version (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                publication_key text COLLATE "C" NOT NULL,
                price_ref text NOT NULL,
                version integer NOT NULL CHECK (version >= 1),
                strategy_id text NOT NULL CHECK (strategy_id IN ('fixed','per_unit','tiered_graduated','tiered_volume')),
                strategy_version integer NOT NULL CHECK (strategy_version >= 1),
                currency char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
                currency_exponent smallint NOT NULL CHECK (currency_exponent BETWEEN 0 AND 4),
                exponent_version text NOT NULL,
                unit text NOT NULL,
                quantity_scale smallint NOT NULL CHECK (quantity_scale BETWEEN 0 AND 12),
                conversion_numerator numeric(30,0) NOT NULL CHECK (conversion_numerator > 0),
                conversion_denominator numeric(30,0) NOT NULL CHECK (conversion_denominator > 0),
                billing_increment numeric(38,12) NOT NULL CHECK (billing_increment > 0),
                increment_mode text NOT NULL CHECK (increment_mode IN ('none','ceil','floor')),
                fixed_amount numeric(38,12) NULL CHECK (fixed_amount IS NULL OR fixed_amount >= 0),
                per_unit_price numeric(38,12) NULL CHECK (per_unit_price IS NULL OR per_unit_price >= 0),
                rounding_mode text NOT NULL CHECK (rounding_mode IN ('down','up','half_up','half_even')),
                rounding_stage text NOT NULL CHECK (rounding_stage = 'after_tax'),
                rounding_policy_version text NOT NULL,
                commitment_declared boolean NOT NULL,
                terms jsonb NOT NULL CHECK (jsonb_typeof(terms) = 'object' AND octet_length(terms::text) <= 65536),
                terms_digest text NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (seller_ref,environment,price_ref,version),
                FOREIGN KEY (seller_ref,environment,publication_key) REFERENCES public.billium_rating_price_publication(seller_ref,environment,publication_key),
                CHECK ((strategy_id = 'fixed') = (fixed_amount IS NOT NULL)),
                CHECK ((strategy_id = 'per_unit') = (per_unit_price IS NOT NULL)),
                CHECK (increment_mode <> 'none' OR billing_increment = 1)
            );

            CREATE TRIGGER billium_rating_price_immutable BEFORE UPDATE OR DELETE ON public.billium_rating_price_version FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE TABLE public.billium_rating_price_tier (
                price_version_id bigint NOT NULL REFERENCES public.billium_rating_price_version(id),
                tier_index smallint NOT NULL CHECK (tier_index >= 0),
                lower_bound numeric(38,12) NOT NULL CHECK (lower_bound >= 0),
                upper_bound numeric(38,12) NULL,
                unit_price numeric(38,12) NOT NULL CHECK (unit_price >= 0),
                flat_fee numeric(38,12) NOT NULL CHECK (flat_fee >= 0),
                PRIMARY KEY (price_version_id,tier_index),
                CHECK (upper_bound IS NULL OR upper_bound > lower_bound)
            );

            CREATE TRIGGER billium_rating_tier_immutable BEFORE UPDATE OR DELETE ON public.billium_rating_price_tier FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE TABLE public.billium_rating_commitment_term (
                price_version_id bigint PRIMARY KEY REFERENCES public.billium_rating_price_version(id),
                seller_ref text NOT NULL,
                billing_account_ref text NOT NULL CHECK (billing_account_ref <> ''),
                component_ref text NOT NULL CHECK (component_ref <> ''),
                currency char(3) NOT NULL,
                period_ref text NOT NULL CHECK (period_ref <> ''),
                minimum_minor bigint NULL CHECK (minimum_minor IS NULL OR minimum_minor >= 0),
                maximum_minor bigint NULL CHECK (maximum_minor IS NULL OR maximum_minor >= 0),
                consumed_by_already_billed boolean NOT NULL CHECK (consumed_by_already_billed),
                applies_at text NOT NULL CHECK (applies_at = 'period_final'),
                CHECK (minimum_minor IS NOT NULL OR maximum_minor IS NOT NULL),
                CHECK (minimum_minor IS NULL OR maximum_minor IS NULL OR minimum_minor <= maximum_minor)
            );

            CREATE TRIGGER billium_rating_commitment_immutable BEFORE UPDATE OR DELETE ON public.billium_rating_commitment_term FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE TABLE public.billium_rating_tax_snapshot (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL CHECK (environment IN ('test','live')),
                snapshot_ref text NOT NULL,
                kind text NOT NULL CHECK (kind IN ('standard','correction')),
                mode text NOT NULL CHECK (mode IN ('exclusive','inclusive','none_declared')),
                jurisdiction text NOT NULL,
                tax_code text NOT NULL,
                rate numeric(18,8) NOT NULL CHECK (rate >= 0 AND rate < 10),
                rate_text text NOT NULL CHECK (rate_text ~ '^(0|[1-9][0-9]*)(\.[0-9]{1,8})?$' AND rate_text::numeric = rate),
                effective_date date NOT NULL,
                tax_point date NOT NULL,
                no_tax_reason text NULL,
                resolver text NOT NULL,
                resolver_version text NOT NULL,
                input_digest text NOT NULL,
                evidence_digest text NOT NULL,
                snapshot_digest text NOT NULL,
                policy_ref text NULL,
                policy_version text NULL,
                policy_treatment text NULL CHECK (policy_treatment IN ('original_rate','current_legal_rate','other_supported')),
                policy_effective_from date NULL,
                policy_effective_to date NULL,
                policy_input_digest text NULL,
                policy_output_digest text NULL,
                policy_resolver_version text NULL,
                UNIQUE (seller_ref,environment,snapshot_ref),
                CHECK ((mode = 'none_declared') = (no_tax_reason IS NOT NULL)),
                CHECK (mode <> 'none_declared' OR (rate = 0 AND no_tax_reason <> '')),
                CHECK ((kind = 'correction') = (policy_ref IS NOT NULL)),
                CHECK (kind = 'standard' OR (policy_version IS NOT NULL AND policy_treatment IS NOT NULL AND policy_effective_from IS NOT NULL AND policy_input_digest IS NOT NULL AND policy_output_digest IS NOT NULL AND policy_resolver_version IS NOT NULL)),
                CHECK (policy_effective_to IS NULL OR policy_effective_to >= policy_effective_from)
            );

            CREATE TRIGGER billium_rating_tax_immutable BEFORE UPDATE OR DELETE ON public.billium_rating_tax_snapshot FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();

            CREATE OR REPLACE FUNCTION public.billium_rating_check_price_shape() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
            DECLARE v_id bigint; v record; r record; previous_upper numeric; n integer := 0; unbounded integer := 0;
            BEGIN
                IF TG_TABLE_NAME = 'billium_rating_price_version' THEN v_id := NEW.id;
                ELSE v_id := NEW.price_version_id; END IF;
                SELECT * INTO v FROM public.billium_rating_price_version WHERE id=v_id;
                FOR r IN SELECT * FROM public.billium_rating_price_tier WHERE price_version_id=v_id ORDER BY tier_index LOOP
                    IF v.strategy_id NOT IN ('tiered_graduated','tiered_volume') OR r.tier_index <> n OR (n=0 AND r.lower_bound <> 0) OR (n>0 AND (previous_upper IS NULL OR r.lower_bound <> previous_upper)) THEN
                        RAISE EXCEPTION 'billium.rating.terms_invalid: invalid tiers' USING ERRCODE='23514';
                    END IF;
                    n := n+1;
                    IF r.upper_bound IS NULL THEN unbounded := unbounded+1; END IF;
                    previous_upper := r.upper_bound;
                END LOOP;
                IF v.strategy_id IN ('tiered_graduated','tiered_volume') AND (n=0 OR unbounded<>1 OR previous_upper IS NOT NULL) THEN
                    RAISE EXCEPTION 'billium.rating.terms_invalid: unbounded final tier required' USING ERRCODE='23514';
                END IF;
                IF v.commitment_declared <> EXISTS (SELECT 1 FROM public.billium_rating_commitment_term WHERE price_version_id=v_id) THEN
                    RAISE EXCEPTION 'billium.rating.terms_invalid: commitment mismatch' USING ERRCODE='23514';
                END IF;
                IF EXISTS (SELECT 1 FROM public.billium_rating_commitment_term WHERE price_version_id=v_id AND (currency<>v.currency OR seller_ref<>v.seller_ref)) THEN
                    RAISE EXCEPTION 'billium.rating.unsupported_combination: commitment scope' USING ERRCODE='23514';
                END IF;
                RETURN NULL;
            END $$;

            CREATE CONSTRAINT TRIGGER billium_rating_price_shape AFTER INSERT ON public.billium_rating_price_version DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.billium_rating_check_price_shape();

            CREATE CONSTRAINT TRIGGER billium_rating_tier_shape AFTER INSERT ON public.billium_rating_price_tier DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.billium_rating_check_price_shape();

            CREATE CONSTRAINT TRIGGER billium_rating_commitment_shape AFTER INSERT ON public.billium_rating_commitment_term DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.billium_rating_check_price_shape();
            SQL);

        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_rating_price_publication');
        // PostgreSQL requires UPDATE privilege for FOR UPDATE; the immutable trigger still forbids mutation.
        RuntimeGrants::grant('UPDATE (publication_key)', 'public.billium_rating_price_publication');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_rating_price_version');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_rating_price_tier');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_rating_commitment_term');
        RuntimeGrants::grant('SELECT, INSERT', 'public.billium_rating_tax_snapshot');
    }

    public function down(): never
    {
        throw new LogicException('Billium migrations are forward-only.');
    }
};

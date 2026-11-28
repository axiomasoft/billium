<?php

declare(strict_types=1);

use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            SET LOCAL lock_timeout = '2s';
            SET LOCAL statement_timeout = '30s';

            CREATE TABLE public.billium_feed_command_lease (
                lease_key        text        PRIMARY KEY CHECK (lease_key ~ '^sha256:[0-9a-f]{64}$'),
                installation_ref text        NOT NULL CHECK (installation_ref <> '' AND length(installation_ref) <= 200),
                seller_ref       text        NOT NULL CHECK (seller_ref <> '' AND length(seller_ref) <= 200),
                environment      text        NOT NULL CHECK (environment IN ('test', 'live')),
                lease_token      text        NULL CHECK (lease_token IS NULL OR length(lease_token) = 48),
                lease_epoch      bigint      NOT NULL DEFAULT 1 CHECK (lease_epoch >= 1),
                owner_ref        text        NULL CHECK (owner_ref IS NULL OR (owner_ref <> '' AND length(owner_ref) <= 200)),
                acquired_at      timestamptz NOT NULL DEFAULT clock_timestamp(),
                lease_until      timestamptz NOT NULL
            );

            CREATE INDEX idx_billium_feed_command_lease_active_expiry
                ON public.billium_feed_command_lease (lease_until)
                WHERE lease_token IS NOT NULL;

            REVOKE ALL ON TABLE public.billium_feed_command_lease FROM PUBLIC;

            CREATE TABLE public.billium_feed_bootstrap_pin_auth (
                lease_ref         text PRIMARY KEY REFERENCES public.billium_feed_bootstrap_lease(lease_ref),
                pin_secret_hash   text NOT NULL CHECK (pin_secret_hash ~ '^[0-9a-f]{64}$'),
                command_lease_key text NULL CHECK (command_lease_key IS NULL OR command_lease_key ~ '^sha256:[0-9a-f]{64}$')
            );

            REVOKE ALL ON TABLE public.billium_feed_bootstrap_pin_auth FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.billium_feed_register_bootstrap_pin(
                p_lease_ref         text,
                p_pin_secret_hash   text,
                p_command_lease_key text DEFAULT NULL
            ) RETURNS void
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $$
            DECLARE
                v_rls_on boolean;
                v_inst   text := nullif(pg_catalog.current_setting('billium.installation_ref', true), '');
                v_seller text := nullif(pg_catalog.current_setting('billium.seller_ref', true), '');
                v_env    text := nullif(pg_catalog.current_setting('billium.environment', true), '');
                v_exists boolean;
            BEGIN
                SELECT c.relrowsecurity INTO v_rls_on
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public' AND c.relname = 'billium_feed_bootstrap_lease';

                IF COALESCE(v_rls_on, false) AND (v_inst IS NULL OR v_seller IS NULL OR v_env IS NULL) THEN
                    RAISE EXCEPTION 'RLS session context required for pin registration' USING ERRCODE = '42501';
                END IF;

                SELECT EXISTS (
                    SELECT 1 FROM public.billium_feed_bootstrap_lease
                    WHERE lease_ref = p_lease_ref
                      AND state = 'active'
                      AND snapshot_digest IS NULL
                      AND (
                          NOT COALESCE(v_rls_on, false) OR (
                              (source_scope::jsonb->>'installationRef') = v_inst
                              AND (source_scope::jsonb->>'sellerRef') = v_seller
                              AND (source_scope::jsonb->>'environment') = v_env
                          )
                      )
                ) INTO v_exists;

                IF NOT v_exists THEN
                    RAISE EXCEPTION 'active bootstrap pin not found for registration' USING ERRCODE = '22023';
                END IF;

                INSERT INTO public.billium_feed_bootstrap_pin_auth (lease_ref, pin_secret_hash, command_lease_key)
                VALUES (p_lease_ref, p_pin_secret_hash, p_command_lease_key);
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.billium_feed_extend_bootstrap_pin(
                p_lease_ref        text,
                p_subscription_ref text,
                p_snapshot_ref     text,
                p_pin_secret       text,
                p_ttl_seconds      integer,
                p_lease_token      text DEFAULT NULL
            ) RETURNS timestamptz
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $$
            DECLARE
                v_new_expires       timestamptz;
                v_lease_epoch       bigint;
                v_stored_secret     text;
                v_command_lease_key text;
                v_rls_on            boolean;
                v_inst              text := nullif(pg_catalog.current_setting('billium.installation_ref', true), '');
                v_seller            text := nullif(pg_catalog.current_setting('billium.seller_ref', true), '');
                v_env               text := nullif(pg_catalog.current_setting('billium.environment', true), '');
            BEGIN
                IF p_ttl_seconds IS NULL OR p_ttl_seconds < 1 OR p_ttl_seconds > 300 THEN
                    RAISE EXCEPTION 'bootstrap pin TTL extension out of bounds (1..300s)' USING ERRCODE = '22023';
                END IF;

                IF p_pin_secret IS NULL OR p_pin_secret = '' THEN
                    RETURN NULL;
                END IF;

                SELECT c.relrowsecurity INTO v_rls_on
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public' AND c.relname = 'billium_feed_bootstrap_lease';

                IF COALESCE(v_rls_on, false) AND (v_inst IS NULL OR v_seller IS NULL OR v_env IS NULL) THEN
                    RETURN NULL;
                END IF;

                SELECT a.pin_secret_hash, a.command_lease_key
                INTO v_stored_secret, v_command_lease_key
                FROM public.billium_feed_bootstrap_pin_auth a
                WHERE a.lease_ref = p_lease_ref
                FOR UPDATE;

                IF v_stored_secret IS NULL
                   OR v_stored_secret <> pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(p_pin_secret, 'UTF8')), 'hex') THEN
                    RETURN NULL;
                END IF;

                IF v_command_lease_key IS NOT NULL THEN
                    IF p_lease_token IS NULL THEN
                        RETURN NULL;
                    END IF;

                    UPDATE public.billium_feed_command_lease
                    SET lease_until = pg_catalog.clock_timestamp() + (p_ttl_seconds * interval '1 second')
                    WHERE lease_key = v_command_lease_key
                      AND lease_token = p_lease_token
                      AND lease_until > pg_catalog.clock_timestamp()
                      AND (NOT COALESCE(v_rls_on, false) OR (installation_ref = v_inst AND seller_ref = v_seller AND environment = v_env))
                    RETURNING lease_epoch INTO v_lease_epoch;

                    IF v_lease_epoch IS NULL THEN
                        RETURN NULL;
                    END IF;
                ELSIF p_lease_token IS NOT NULL THEN
                    RETURN NULL;
                END IF;

                UPDATE public.billium_feed_bootstrap_lease
                SET expires_at = pg_catalog.clock_timestamp() + (p_ttl_seconds * interval '1 second')
                WHERE lease_ref = p_lease_ref
                  AND subscription_ref = p_subscription_ref
                  AND snapshot_ref = p_snapshot_ref
                  AND state = 'active'
                  AND snapshot_digest IS NULL
                  AND expires_at > pg_catalog.clock_timestamp()
                  AND (
                      NOT COALESCE(v_rls_on, false) OR (
                          (source_scope::jsonb->>'installationRef') = v_inst
                          AND (source_scope::jsonb->>'sellerRef') = v_seller
                          AND (source_scope::jsonb->>'environment') = v_env
                      )
                  )
                RETURNING expires_at INTO v_new_expires;

                RETURN v_new_expires;
            END;
            $$;

            REVOKE ALL ON FUNCTION public.billium_feed_register_bootstrap_pin(text, text, text) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.billium_feed_extend_bootstrap_pin(text, text, text, text, integer, text) FROM PUBLIC;
            SQL);

        RuntimeGrants::grant('SELECT, INSERT, UPDATE', 'public.billium_feed_command_lease');
        RuntimeGrants::revokeAll('public.billium_feed_bootstrap_pin_auth');
        RuntimeGrants::grant('EXECUTE', 'FUNCTION public.billium_feed_register_bootstrap_pin(text, text, text)');
        RuntimeGrants::grant('EXECUTE', 'FUNCTION public.billium_feed_extend_bootstrap_pin(text, text, text, text, integer, text)');
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

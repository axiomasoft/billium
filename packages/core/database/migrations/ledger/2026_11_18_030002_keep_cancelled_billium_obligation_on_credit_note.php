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
            SQL);
        app(LedgerPrivileges::class)->grant();
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

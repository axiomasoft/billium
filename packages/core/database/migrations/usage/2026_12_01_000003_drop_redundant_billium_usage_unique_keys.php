<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        // Each key below repeats a uniqueness the primary key (or a narrower unique key) already enforces, and no
        // foreign key references it. It only cost index maintenance on every insert and storage. A key that a foreign
        // key depends on is kept: the DO block refuses to drop it instead of cascading.
        DB::unprepared(<<<'SQL'
            SET LOCAL lock_timeout = '2s';
            DO $$
            DECLARE
                v_key record;
                v_name text;
            BEGIN
                FOR v_key IN SELECT * FROM (VALUES
                ('billium_usage_aggregate_snapshot', 'id,seller_ref,environment')
                ) AS k(table_name, columns)
                LOOP
                    SELECT c.conname INTO v_name
                    FROM pg_catalog.pg_constraint c
                    WHERE c.conrelid = ('public.' || v_key.table_name)::regclass
                      AND c.contype = 'u'
                      AND (SELECT string_agg(a.attname, ',' ORDER BY x.ord)
                           FROM unnest(c.conkey) WITH ORDINALITY AS x(attnum, ord)
                           JOIN pg_catalog.pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = x.attnum) = v_key.columns;
                    IF v_name IS NULL THEN
                        RAISE EXCEPTION 'redundant unique key %(%) not found', v_key.table_name, v_key.columns;
                    END IF;
                    IF EXISTS (SELECT 1 FROM pg_catalog.pg_constraint f
                               WHERE f.contype = 'f'
                                 AND f.conindid = (SELECT conindid FROM pg_catalog.pg_constraint
                                                   WHERE conrelid = ('public.' || v_key.table_name)::regclass AND conname = v_name)) THEN
                        RAISE EXCEPTION 'unique key % on % is referenced by a foreign key', v_name, v_key.table_name;
                    END IF;
                    EXECUTE pg_catalog.format('ALTER TABLE %I.%I DROP CONSTRAINT %I', 'public', v_key.table_name, v_name);
                END LOOP;
            END
            $$;
            SQL);
    }

    public function down(): never
    {
        throw new LogicException('forward-only (REQ-FAIL-29)');
    }
};

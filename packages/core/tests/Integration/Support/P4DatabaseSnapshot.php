<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Integration\Support;

use Illuminate\Database\Connection;

/** Test-only logical data backup; schema and grants remain installed. Never used by runtime code. */
final readonly class P4DatabaseSnapshot
{
    /**
     * @param  array<string, string>  $tables
     * @param  array<string, array{last_value: int, is_called: bool}>  $sequences
     */
    private function __construct(private array $tables, private array $sequences) {}

    public static function capture(Connection $owner): self
    {
        $tables = [];
        foreach ($owner->select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $row) {
            $name = $row->tablename;
            $tables[$name] = $owner->scalar('SELECT coalesce(json_agg(t ORDER BY row_to_json(t)::text), \'[]\'::json)::text FROM public.'.self::quote($name).' t');
        }
        $sequences = [];
        foreach ($owner->select("SELECT sequencename FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename") as $row) {
            $name = $row->sequencename;
            $state = $owner->selectOne('SELECT last_value, is_called FROM public.'.self::quote($name));
            $sequences[$name] = ['last_value' => (int) $state->last_value, 'is_called' => (bool) $state->is_called];
        }

        return new self(tables: $tables, sequences: $sequences);
    }

    public function restore(Connection $owner): void
    {
        $owner->transaction(function () use ($owner): void {
            // Fixture owner only: preserve append-only history without invoking runtime writers during restore.
            $owner->statement('SET LOCAL session_replication_role = replica');
            $names = array_map(static fn (string $name): string => 'public.'.self::quote($name), array_keys($this->tables));
            $owner->statement('TRUNCATE '.implode(', ', $names).' RESTART IDENTITY');
            foreach ($this->tables as $name => $json) {
                $table = 'public.'.self::quote($name);
                $columns = $owner->select("SELECT attname FROM pg_attribute WHERE attrelid=?::regclass AND attnum>0 AND NOT attisdropped AND attgenerated='' ORDER BY attnum", [$table]);
                $names = implode(', ', array_map(static fn (object $column): string => self::quote($column->attname), $columns));
                $owner->statement('INSERT INTO '.$table.' ('.$names.') OVERRIDING SYSTEM VALUE SELECT '.$names.' FROM json_populate_recordset(NULL::'.$table.', ?::json)', [$json]);
            }
            foreach ($this->sequences as $name => $state) {
                $owner->select('SELECT setval(?::regclass, ?, ?)', ['public.'.self::quote($name), $state['last_value'], $state['is_called']]);
            }
        });
    }

    private static function quote(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}

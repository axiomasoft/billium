<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Arch;

final class MigrationTables
{
    /** @return list<string> */
    public static function createdBy(string $source): array
    {
        // D45/INV-01: ownership comes from CREATE, never from a table merely referenced by a FK.
        $source = implode('', array_map(
            static fn (array|string $token): string => is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1])
                : $token,
            token_get_all($source),
        ));
        $tables = [];
        preg_match_all('/\bSchema\s*::\s*create\s*\(\s*[\'"](billium_[a-z0-9_]+)[\'"]/i', $source, $schema);
        preg_match_all('/\bCREATE\s+(?:UNLOGGED\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"?public"?\.)?"?(billium_[a-z0-9_]+)"?/i', $source, $sql);
        foreach (array_merge($schema[1], $sql[1]) as $name) {
            $tables[] = $name;
        }

        return array_values(array_unique($tables));
    }

    /** @return array<string, string> table => module */
    public static function ownership(string $directory): array
    {
        $owners = [];
        foreach (glob($directory.'/*/*.php') ?: [] as $migration) {
            $module = basename(dirname($migration));
            foreach (self::createdBy((string) file_get_contents($migration)) as $table) {
                if (isset($owners[$table]) && $owners[$table] !== $module) {
                    throw new \LogicException("Multiple migration owners for {$table}");
                }
                $owners[$table] = $module;
            }
        }

        return $owners;
    }
}

<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Installation;

use Billium\Core\Data\Modules\ModuleState;
use Billium\Core\Enums\Modules\ModuleLifecycleState;
use Illuminate\Database\Connection;
use Packman\Attributes\Internal;

/** Plain reads of billium_module_state; locking happens only through the root LockSet. */
#[Internal]
final class Modules
{
    public const string TABLE = 'billium_module_state';

    /**
     * @param  list<string>|null  $modules  null reads every module of the installation
     * @return array<string, ModuleState>
     */
    public static function read(Connection $db, string $installationRef, ?array $modules = null): array
    {
        $sql = 'SELECT module_id, state, config_revision, schema_version, generation, last_transition_ref FROM public.billium_module_state WHERE installation_ref = ?';
        $bindings = [$installationRef];
        if ($modules !== null) {
            $sql .= ' AND module_id = ANY(?::text[])';
            $bindings[] = '{'.implode(',', array_map(static fn (string $id): string => '"'.addcslashes($id, '"\\').'"', $modules)).'}';
        }
        $states = [];
        foreach ($db->select($sql.' ORDER BY module_id COLLATE "C"', $bindings) as $row) {
            $id = Rows::string(Rows::field($row, 'module_id'));
            $states[$id] = new ModuleState(
                $installationRef,
                $id,
                ModuleLifecycleState::from(Rows::string(Rows::field($row, 'state'))),
                Rows::int(Rows::field($row, 'config_revision')),
                Rows::int(Rows::field($row, 'schema_version')),
                Rows::nullableString(Rows::field($row, 'last_transition_ref')),
                Rows::int(Rows::field($row, 'generation')),
            );
        }

        return $states;
    }
}

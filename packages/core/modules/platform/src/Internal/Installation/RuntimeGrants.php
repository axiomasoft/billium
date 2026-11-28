<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Installation;

use Illuminate\Support\Facades\DB;
use LogicException;
use Packman\Attributes\Internal;

/**
 * Grants of platform migrations to the restricted runtime role (D60). The runtime role is the user of the
 * `billium.connection` connection; migrations run as the distinct schema owner. When both are the same role (a
 * deployment without the split) nothing is granted and the strong backstop is not claimed.
 */
#[Internal]
final readonly class RuntimeGrants
{
    /** A public table, or a public function with its identity argument types (for example `bigint[], text`). */
    private const string OBJECT = '/^public\.[a-z_][a-z0-9_]*$|^FUNCTION public\.[a-z_][a-z0-9_]*\([a-z0-9_ ,\[\]]*\)$/';

    public function __construct(private string $runtimeConnection) {}

    public static function role(): ?string
    {
        $connection = app(self::class)->runtimeConnection;
        $user = DB::connection($connection)->getConfig('username');

        return is_string($user) && $user !== '' ? $user : null;
    }

    public static function grant(string $privileges, string $object): void
    {
        if (preg_match('/^[A-Z, ()a-z0-9_]+$/', $privileges) !== 1 || preg_match(self::OBJECT, $object) !== 1) {
            throw new LogicException("Unsafe runtime grant {$privileges} ON {$object}");
        }
        $role = self::role();
        if ($role === null) {
            return;
        }
        if (Rows::field(DB::selectOne('SELECT current_user AS name'), 'name') === $role) {
            return;
        }
        DB::connection()->getPdo()->exec('GRANT '.$privileges.' ON '.$object.' TO "'.str_replace('"', '""', $role).'"');
    }

    public static function revokeAll(string $object): void
    {
        if (preg_match(self::OBJECT, $object) !== 1) {
            throw new LogicException("Unsafe runtime revoke ON {$object}");
        }
        $role = self::role();
        if ($role === null) {
            return;
        }
        if (Rows::field(DB::selectOne('SELECT current_user AS name'), 'name') === $role) {
            return;
        }
        DB::connection()->getPdo()->exec('REVOKE ALL ON '.$object.' FROM "'.str_replace('"', '""', $role).'"');
    }
}

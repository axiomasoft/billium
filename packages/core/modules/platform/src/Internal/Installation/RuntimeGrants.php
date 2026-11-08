<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Installation;

use Illuminate\Support\Facades\DB;

/**
 * Grants of platform migrations to the restricted runtime role (D60). The runtime role is the user of the
 * `billium.connection` connection; migrations run as the distinct schema owner. When both are the same role (a
 * deployment without the split) nothing is granted and the strong backstop is not claimed.
 */
final readonly class RuntimeGrants
{
    public function __construct(private string $runtimeConnection) {}

    public static function role(): ?string
    {
        $connection = app(self::class)->runtimeConnection;
        $user = DB::connection($connection)->getConfig('username');

        return is_string($user) && $user !== '' ? $user : null;
    }

    public static function grant(string $privileges, string $object): void
    {
        if (preg_match('/^[A-Z, ()a-z_]+$/', $privileges) !== 1 || preg_match('/^public\.[a-z_]+(?:\([a-z ]*\))?$|^FUNCTION public\.[a-z_]+\([a-z ,]*\)$/', $object) !== 1) {
            throw new \LogicException("Unsafe runtime grant {$privileges} ON {$object}");
        }
        $role = self::role();
        if ($role === null) {
            return;
        }
        if (Rows::field(DB::selectOne('SELECT current_user AS name'), 'name') === $role) {
            return;
        }
        DB::unprepared('GRANT '.$privileges.' ON '.$object.' TO "'.str_replace('"', '""', $role).'"');
    }
}

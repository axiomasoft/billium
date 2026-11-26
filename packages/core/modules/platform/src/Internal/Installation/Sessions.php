<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Installation;

use Billium\Platform\Data\Installation\FinancialSessionView;
use Illuminate\Database\Connection;
use Packman\Attributes\Internal;

/** Plain reads of billium_financial_session; locking happens only through the root LockSet. */
#[Internal]
final class Sessions
{
    public const string TABLE = 'billium_financial_session';

    private const string COLUMNS = 'id, installation_ref, epoch, external_owner_session_ref, deployment_generation, '
        .'external_attachment_generation, authority_epoch, state, activation_certificate_ref, sealed_at::text AS sealed_at';

    /** The current session is the latest epoch of the installation. */
    public static function currentId(Connection $db, string $installationRef): ?int
    {
        $row = $db->selectOne('SELECT id FROM public.billium_financial_session WHERE installation_ref = ? ORDER BY epoch DESC LIMIT 1', [$installationRef]);

        return is_object($row) ? Rows::int(Rows::field($row, 'id')) : null;
    }

    public static function find(Connection $db, int $id): ?FinancialSessionView
    {
        $row = $db->selectOne('SELECT '.self::COLUMNS.' FROM public.billium_financial_session WHERE id = ?', [$id]);

        return is_object($row) ? self::view($row) : null;
    }

    public static function current(Connection $db, string $installationRef): ?FinancialSessionView
    {
        $id = self::currentId($db, $installationRef);

        return $id === null ? null : self::find($db, $id);
    }

    private static function view(object $row): FinancialSessionView
    {
        return new FinancialSessionView(
            Rows::string(Rows::field($row, 'installation_ref')),
            Rows::int(Rows::field($row, 'epoch')),
            Rows::string(Rows::field($row, 'external_owner_session_ref')),
            Rows::int(Rows::field($row, 'deployment_generation')),
            Rows::int(Rows::field($row, 'external_attachment_generation')),
            Rows::int(Rows::field($row, 'authority_epoch')),
            Rows::string(Rows::field($row, 'state')),
            Rows::nullableString(Rows::field($row, 'activation_certificate_ref')),
            Rows::nullableString(Rows::field($row, 'sealed_at')),
        );
    }
}

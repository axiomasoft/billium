<?php

declare(strict_types=1);

namespace Billium\Core\Database;

use Billium\Core\Data\Runtime\Caller;
use Illuminate\Database\Connection;
use Packman\Attributes\Api;

/**
 * Transaction-local (SET LOCAL) PostgreSQL session context for optional seller-scoped RLS (ADR-08, REQ-PROF-09).
 * Uses `billium.*` GUCs only (no tenant_id).
 */
#[Api(since: '1.0')]
final readonly class RlsSessionContext
{
    public static function apply(Connection $db, Caller $caller): void
    {
        self::applyForValues($db, $caller->installationRef, $caller->sellerRef, $caller->environment);
    }

    public static function applyForValues(Connection $db, string $installationRef, string $sellerRef, string $environment): void
    {
        $db->selectOne(
            "SELECT set_config('billium.installation_ref', ?, true), set_config('billium.seller_ref', ?, true), set_config('billium.environment', ?, true)",
            [$installationRef, $sellerRef, $environment],
            false,
        );
    }
}

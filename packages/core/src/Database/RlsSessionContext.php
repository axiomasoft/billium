<?php

declare(strict_types=1);

namespace Billium\Core\Database;

use Billium\Core\Data\Runtime\Caller;
use Illuminate\Database\Connection;
use LogicException;
use Packman\Attributes\Api;

/**
 * Transaction-local (SET LOCAL) PostgreSQL session context for optional seller-scoped RLS (ADR-08).
 * Uses `billium.*` GUCs only (no tenant_id). Billium ships no policies; a host may define them on these GUCs.
 */
#[Api(since: '1.0')]
final readonly class RlsSessionContext
{
    /**
     * @param  array<string, string>  $settings  further transaction-local settings sent in the same round trip,
     *                                           for example `statement_timeout`
     */
    public static function apply(Connection $db, Caller $caller, array $settings = []): void
    {
        self::applyForValues($db, $caller->installationRef, $caller->sellerRef, $caller->environment, $settings);
    }

    /**
     * @param  array<string, string>  $settings  further transaction-local settings sent in the same round trip
     */
    public static function applyForValues(
        Connection $db,
        string $installationRef,
        string $sellerRef,
        string $environment,
        array $settings = [],
    ): void {
        $settings = [
            'billium.installation_ref' => $installationRef,
            'billium.seller_ref' => $sellerRef,
            'billium.environment' => $environment,
        ] + $settings;
        $calls = [];
        $bindings = [];
        foreach ($settings as $name => $value) {
            if (preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/', $name) !== 1) {
                throw new LogicException("Invalid setting name: {$name}");
            }
            $calls[] = 'set_config(?, ?, true)';
            $bindings[] = $name;
            $bindings[] = $value;
        }
        $db->selectOne('SELECT '.implode(', ', $calls), $bindings, false);
    }
}

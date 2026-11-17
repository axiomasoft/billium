<?php

declare(strict_types=1);

namespace Billium\Core\Database;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;

/**
 * Declares Billium's ordered central PostgreSQL schema sets (`billium-platform` and `billium-ledger`).
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class BilliumSchemaSetProvider implements SchemaSetProvider
{
    /** @return list<SchemaSet> */
    #[Override]
    public function sets(): array
    {
        $migrationsRoot = dirname(__DIR__, 2).'/database/migrations';

        return [
            new SchemaSet(
                package: 'axiomasoft/billium-core',
                name: 'billium-platform',
                scope: SchemaScope::Central,
                path: $migrationsRoot.'/platform',
                version: 1,
                drivers: ['pgsql'],
                lifecycle: SchemaLifecycle::Platform,
            ),
            new SchemaSet(
                package: 'axiomasoft/billium-core',
                name: 'billium-ledger',
                scope: SchemaScope::Central,
                path: $migrationsRoot.'/ledger',
                version: 1,
                drivers: ['pgsql'],
                lifecycle: SchemaLifecycle::Platform,
            ),
        ];
    }
}

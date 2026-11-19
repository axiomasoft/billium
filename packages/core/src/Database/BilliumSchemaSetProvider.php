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
 * Declares Billium's central PostgreSQL schema sets (`billium-platform` platform stream and module streams).
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
                lifecycle: SchemaLifecycle::Module,
                requires: ['axiomasoft/billium-core'],
                prerequisiteSets: ['axiomasoft/billium-core:billium-platform'],
            ),
            new SchemaSet(
                package: 'axiomasoft/billium-core',
                name: 'billium-usage',
                scope: SchemaScope::Central,
                path: $migrationsRoot.'/usage',
                version: 1,
                drivers: ['pgsql'],
                lifecycle: SchemaLifecycle::Module,
                requires: ['axiomasoft/billium-core'],
                prerequisiteSets: ['axiomasoft/billium-core:billium-platform'],
            ),
            new SchemaSet(
                package: 'axiomasoft/billium-core',
                name: 'billium-schedule',
                scope: SchemaScope::Central,
                path: $migrationsRoot.'/schedule',
                version: 1,
                drivers: ['pgsql'],
                lifecycle: SchemaLifecycle::Module,
                requires: ['axiomasoft/billium-core'],
                prerequisiteSets: ['axiomasoft/billium-core:billium-platform'],
            ),
            new SchemaSet(
                package: 'axiomasoft/billium-core',
                name: 'billium-rating',
                scope: SchemaScope::Central,
                path: $migrationsRoot.'/rating',
                version: 1,
                drivers: ['pgsql'],
                lifecycle: SchemaLifecycle::Module,
                requires: ['axiomasoft/billium-core'],
                prerequisiteSets: ['axiomasoft/billium-core:billium-platform'],
            ),
            new SchemaSet(
                package: 'axiomasoft/billium-core',
                name: 'billium-documents',
                scope: SchemaScope::Central,
                path: $migrationsRoot.'/documents',
                version: 1,
                drivers: ['pgsql'],
                lifecycle: SchemaLifecycle::Module,
                requires: ['axiomasoft/billium-core'],
                prerequisiteSets: ['axiomasoft/billium-core:billium-platform'],
            ),
        ];
    }
}

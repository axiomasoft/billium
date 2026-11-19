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

#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ExecutionSchemaSetProvider implements SchemaSetProvider
{
    /** @return list<SchemaSet> */
    #[Override]
    public function sets(): array
    {
        return [new SchemaSet(
            package: 'axiomasoft/billium-core',
            name: 'billium-execution',
            scope: SchemaScope::Central,
            path: dirname(__DIR__, 2).'/database/migrations/execution',
            version: 1,
            drivers: ['pgsql'],
            lifecycle: SchemaLifecycle::Module,
            requires: ['axiomasoft/billium-core'],
            prerequisiteSets: ['axiomasoft/billium-core:billium-platform'],
        )];
    }
}

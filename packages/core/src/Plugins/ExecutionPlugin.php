<?php

declare(strict_types=1);

namespace Billium\Core\Plugins;

use Billium\Core\Database\ExecutionSchemaSetProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/** Execution owns its additive schema; platform session and ledger storage retain their owners. */
#[Api(since: '1.0')]
final class ExecutionPlugin implements ExtensionPlugin, WithSchema
{
    use OwnsSchema;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(id: 'axiomasoft.billium.execution', version: '0.1.0',
            requires: ['axiomasoft.billium.platform'], resourceRoot: dirname(__DIR__, 2), databases: ['pgsql']);
    }

    public function register(PluginRegistrar $registrar): void {}

    #[Override]
    public function tablePrefix(): string
    {
        return 'billium_';
    }

    /** @return list<string> */
    #[Override]
    public function tableNouns(): array
    {
        return ['execution'];
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations/execution';
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaSetProviders(): array
    {
        return [ExecutionSchemaSetProvider::class];
    }
}

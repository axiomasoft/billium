<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Core\Internal\Modules\Semver;
use Billium\Core\Internal\Modules\Shape;
use Packman\Attributes\Api;

/**
 * Static descriptor of one Billium core module: identity, schema stream and ranges, API and wire versions,
 * dependencies, ownership, lifecycle policies and event declarations. Data only: no secrets, no callbacks, and no
 * way to register an external plugin.
 */
#[Api(since: '1.0')]
final readonly class ModuleDescriptor
{
    /**
     * @param  list<ModuleDependency>  $requiresModules
     * @param  list<string>  $requiresPorts
     * @param  list<string>  $optionalPorts
     * @param  list<string>  $emits  wire schema versions this module produces
     * @param  list<string>  $consumes  wire schema versions this module accepts
     * @param  list<string>  $ownedTables
     * @param  list<string>  $configKeys
     * @param  list<MigrationIdentity>  $migrationIdentities
     * @param  list<PublicEventDeclaration>  $publicEvents
     * @param  list<InternalCommandDeclaration>  $internalCommands
     */
    public function __construct(
        public string $moduleId,
        public string $schemaNamespace,
        public string $moduleApiVersion,
        public ApiRange $coreApiRange,
        public string $migrationStream,
        public int $currentSchemaVersion,
        public SchemaRange $supportedSchemaReadRange,
        public SchemaRange $supportedSchemaWriteRange,
        public array $requiresModules,
        public array $requiresPorts,
        public array $optionalPorts,
        public array $emits,
        public array $consumes,
        public array $ownedTables,
        public array $configKeys,
        public array $migrationIdentities,
        public DisablePolicy $disablePolicy,
        public RetirementPolicy $retirementPolicy,
        public UninstallPolicy $uninstallPolicy,
        public string $retentionClass,
        public array $publicEvents,
        public array $internalCommands,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $moduleId) !== 1) {
            throw Shape::fail('module', "invalid moduleId {$moduleId}");
        }
        $context = "module {$moduleId}";
        if (preg_match('/^billium\.[a-z][a-z0-9_]*$/', $schemaNamespace) !== 1) {
            throw Shape::fail($context, 'invalid schemaNamespace');
        }
        if (! Semver::isValid($moduleApiVersion)) {
            throw Shape::fail($context, 'moduleApiVersion must be MAJOR.MINOR.PATCH');
        }
        if (preg_match('/^[a-z][a-z0-9_]*$/', $migrationStream) !== 1) {
            throw Shape::fail($context, 'invalid migrationStream');
        }
        if (! $supportedSchemaReadRange->covers($supportedSchemaWriteRange)) {
            throw Shape::fail($context, 'the write range must lie inside the read range');
        }
        if (! $supportedSchemaWriteRange->contains($currentSchemaVersion)) {
            throw Shape::fail($context, 'currentSchemaVersion must lie inside the write range');
        }
        $versions = array_map(static fn (MigrationIdentity $migration): int => $migration->version, $migrationIdentities);
        if ($versions !== ($versions === [] ? [] : range(1, count($versions))) || $currentSchemaVersion !== ($versions === [] ? 0 : max($versions))) {
            throw Shape::fail($context, 'migration identities must run 1..n and end at currentSchemaVersion');
        }
        self::unique($context, 'owned table', $ownedTables);
        foreach ($ownedTables as $table) {
            if (preg_match('/^billium_[a-z0-9_]+$/', $table) !== 1) {
                throw Shape::fail($context, "table {$table} needs the billium_ prefix");
            }
        }
        self::unique($context, 'config key', $configKeys);
        self::unique($context, 'port', [...$requiresPorts, ...$optionalPorts]);
        self::unique($context, 'dependency', array_map(static fn (ModuleDependency $dependency): string => $dependency->moduleId, $requiresModules));
        self::unique($context, 'migration', array_map(static fn (MigrationIdentity $migration): string => $migration->id, $migrationIdentities));
        self::unique($context, 'public event', array_map(static fn (PublicEventDeclaration $event): string => $event->key(), $publicEvents));
        $commandTypes = array_map(static fn (InternalCommandDeclaration $command): string => $command->commandType, $internalCommands);
        self::unique($context, 'internal command', $commandTypes);
        foreach ($publicEvents as $event) {
            if (in_array($event->eventType, $commandTypes, true)) {
                throw Shape::fail($context, "{$event->eventType} is both a public event and an internal command");
            }
        }
        if (in_array($moduleId, array_map(static fn (ModuleDependency $dependency): string => $dependency->moduleId, $requiresModules), true)) {
            throw Shape::fail($context, 'a module cannot require itself');
        }
    }

    /** @return list<string> */
    public function requiredModuleIds(): array
    {
        return array_map(static fn (ModuleDependency $dependency): string => $dependency->moduleId, $this->requiresModules);
    }

    public static function fromArray(mixed $data, string $context = 'module'): self
    {
        $data = Shape::object($data, [
            'moduleId', 'schemaNamespace', 'moduleApiVersion', 'coreApiRange', 'migrationStream', 'currentSchemaVersion',
            'supportedSchemaReadRange', 'supportedSchemaWriteRange', 'requiresModules', 'requiresPorts', 'optionalPorts',
            'emits', 'consumes', 'ownedTables', 'configKeys', 'migrationIdentities', 'disablePolicy', 'retirementPolicy',
            'uninstallPolicy', 'retentionClass', 'publicEvents', 'internalCommands',
        ], $context);
        $id = Shape::string($data, 'moduleId', $context);
        $context = "module {$id}";

        return new self(
            moduleId: $id,
            schemaNamespace: Shape::string($data, 'schemaNamespace', $context),
            moduleApiVersion: Shape::string($data, 'moduleApiVersion', $context),
            coreApiRange: ApiRange::fromArray($data['coreApiRange'], "{$context}.coreApiRange"),
            migrationStream: Shape::string($data, 'migrationStream', $context),
            currentSchemaVersion: Shape::int($data, 'currentSchemaVersion', $context),
            supportedSchemaReadRange: SchemaRange::fromArray($data['supportedSchemaReadRange'], "{$context}.supportedSchemaReadRange"),
            supportedSchemaWriteRange: SchemaRange::fromArray($data['supportedSchemaWriteRange'], "{$context}.supportedSchemaWriteRange"),
            requiresModules: array_map(static fn (mixed $item): ModuleDependency => ModuleDependency::fromArray($item, "{$context}.requiresModules"), Shape::list($data, 'requiresModules', $context)),
            requiresPorts: Shape::strings($data, 'requiresPorts', $context),
            optionalPorts: Shape::strings($data, 'optionalPorts', $context),
            emits: Shape::strings($data, 'emits', $context),
            consumes: Shape::strings($data, 'consumes', $context),
            ownedTables: Shape::strings($data, 'ownedTables', $context),
            configKeys: Shape::strings($data, 'configKeys', $context),
            migrationIdentities: array_map(static fn (mixed $item): MigrationIdentity => MigrationIdentity::fromArray($item, "{$context}.migrationIdentities"), Shape::list($data, 'migrationIdentities', $context)),
            disablePolicy: DisablePolicy::fromArray($data['disablePolicy'], "{$context}.disablePolicy"),
            retirementPolicy: RetirementPolicy::fromArray($data['retirementPolicy'], "{$context}.retirementPolicy"),
            uninstallPolicy: UninstallPolicy::fromArray($data['uninstallPolicy'], "{$context}.uninstallPolicy"),
            retentionClass: Shape::string($data, 'retentionClass', $context),
            publicEvents: array_map(static fn (mixed $item): PublicEventDeclaration => PublicEventDeclaration::fromArray($item, "{$context}.publicEvents"), Shape::list($data, 'publicEvents', $context)),
            internalCommands: array_map(static fn (mixed $item): InternalCommandDeclaration => InternalCommandDeclaration::fromArray($item, "{$context}.internalCommands"), Shape::list($data, 'internalCommands', $context)),
        );
    }

    /** @param list<string> $values */
    private static function unique(string $context, string $what, array $values): void
    {
        if (count($values) !== count(array_unique($values))) {
            throw Shape::fail($context, "duplicate {$what}");
        }
    }
}

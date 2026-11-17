<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Modules;

use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Data\Modules\CompatibilityFinding;
use Billium\Core\Data\Modules\CompatibilityInput;
use Billium\Core\Data\Modules\CompatibilityReport;
use Billium\Core\Data\Modules\InstalledCheckpoint;
use Billium\Core\Data\Modules\MigrationIdentity;
use Billium\Core\Data\Modules\ModuleDescriptor;
use Billium\Core\Data\Modules\ModuleManifest;
use Billium\Core\Data\Modules\PublicEventDeclaration;
use Billium\Core\Enums\Modules\CompatibilityFindingCode as Code;
use Billium\Core\Enums\Modules\ModulePresence;
use Billium\Core\Plugins\LedgerPlugin;
use Billium\Core\Plugins\PlatformPlugin;
use Billium\Core\Plugins\RatingPlugin;
use Billium\Core\Plugins\SchedulePlugin;
use Billium\Core\Plugins\UsagePlugin;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Contracts\Registry\PluginActivationGate;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Testing\PluginGraph;

/** Descriptor set + desired config + capabilities + checkpoints → report. Pure: no database, no filesystem after load. */
#[Internal]
final readonly class ModuleCatalog implements ModuleRegistry
{
    /** @var list<class-string<ExtensionPlugin>> */
    public const array PACKAGED_PLUGINS = [
        PlatformPlugin::class,
        LedgerPlugin::class,
        UsagePlugin::class,
        SchedulePlugin::class,
        RatingPlugin::class,
    ];

    public function __construct(private ModuleManifest $manifest) {}

    public static function compilePluginGraph(Container $container, ?PluginActivationGate $activationGate = null): Contributions&PluginInspector
    {
        return PluginGraph::compile(
            container: $container,
            plugins: [],
            corePluginId: 'axiomasoft.billium.platform',
            builtIn: self::PACKAGED_PLUGINS,
            activationGate: $activationGate,
        );
    }

    /** Loads the packaged resource only; the installed package never reads the monorepo spec/ directory. */
    public static function packaged(): self
    {
        $path = dirname(__DIR__, 3).'/resources/modules.json';
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw Shape::fail('manifest', 'packaged resources/modules.json is missing');
        }

        return new self(ModuleManifest::fromArray(json_decode($json, true, 64, JSON_THROW_ON_ERROR)));
    }

    public function manifest(): ModuleManifest
    {
        return $this->manifest;
    }

    public function descriptor(string $moduleId): ?ModuleDescriptor
    {
        return $this->manifest->descriptor($moduleId);
    }

    public function check(CompatibilityInput $input): CompatibilityReport
    {
        $findings = $this->descriptorSetFindings();
        $enabled = [];
        foreach ($input->enabled as $id) {
            if (isset($enabled[$id])) {
                $findings[] = new CompatibilityFinding(Code::DuplicateId, $id, "module {$id} is enabled more than once");

                continue;
            }
            $descriptor = $this->manifest->descriptor($id);
            if ($descriptor === null) {
                $findings[] = new CompatibilityFinding(Code::Unknown, $id, "module {$id} has no descriptor");

                continue;
            }
            $enabled[$id] = $descriptor;
        }

        $composition = $this->manifest->composition($input->composition);
        if ($composition === null) {
            $findings[] = new CompatibilityFinding(Code::CompositionUnknown, null, "composition {$input->composition} is not supported");
        } else {
            foreach ($composition->modules as $id => $presence) {
                if ($presence === ModulePresence::Forbidden && isset($enabled[$id])) {
                    $findings[] = new CompatibilityFinding(Code::CompositionForbidden, $id, "{$id} is forbidden in {$composition->id}");
                }
                if ($presence === ModulePresence::Mandatory && ! isset($enabled[$id])) {
                    $findings[] = new CompatibilityFinding(Code::CompositionRequired, $id, "{$id} is mandatory in {$composition->id}");
                }
            }
            foreach ($composition->ports as $port => $presence) {
                if ($presence === ModulePresence::Forbidden && in_array($port, $input->providedPorts, true)) {
                    $findings[] = new CompatibilityFinding(Code::PortForbidden, null, "port {$port} must be absent in {$composition->id}");
                }
            }
        }

        foreach ($enabled as $id => $descriptor) {
            array_push($findings, ...$this->enabledFindings($descriptor, $enabled, $input));
        }
        foreach ($input->modulesWithHistory as $id) {
            if (! in_array($id, $input->historicalReaders, true)) {
                $findings[] = new CompatibilityFinding(Code::HistoricalReaderMissing, $id, "module {$id} has history but no historical reader is installed");
            }
        }

        return new CompatibilityReport($findings, $this->bootOrder($this->inManifestOrder($enabled)));
    }

    /**
     * @param  array<string, ModuleDescriptor>  $enabled
     * @return array<string, ModuleDescriptor>
     */
    private function inManifestOrder(array $enabled): array
    {
        $ordered = [];
        foreach ($this->manifest->modules as $descriptor) {
            if (isset($enabled[$descriptor->moduleId])) {
                $ordered[$descriptor->moduleId] = $enabled[$descriptor->moduleId];
            }
        }

        return $ordered;
    }

    /** @return list<CompatibilityFinding> */
    private function descriptorSetFindings(): array
    {
        $findings = [];
        $byId = [];
        $owners = ['table' => [], 'config key' => [], 'migration stream' => [], 'schema namespace' => []];
        $events = [];
        foreach ($this->manifest->modules as $descriptor) {
            $id = $descriptor->moduleId;
            if (isset($byId[$id])) {
                $findings[] = new CompatibilityFinding(Code::DuplicateId, $id, "module {$id} is declared more than once");

                continue;
            }
            $byId[$id] = $descriptor;
            $claims = [
                'table' => $descriptor->ownedTables,
                'config key' => $descriptor->configKeys,
                'migration stream' => [$descriptor->migrationStream],
                'schema namespace' => [$descriptor->schemaNamespace],
            ];
            foreach ($claims as $kind => $values) {
                foreach ($values as $value) {
                    if (isset($owners[$kind][$value])) {
                        $findings[] = new CompatibilityFinding(Code::OwnershipConflict, $id, "{$kind} {$value} is owned by {$owners[$kind][$value]} and {$id}");
                    } else {
                        $owners[$kind][$value] = $id;
                    }
                }
            }
            foreach ($descriptor->publicEvents as $event) {
                $previous = $events[$event->key()] ?? null;
                if ($previous !== null && ($previous['owner'] !== $id || ! self::samePublicEvent($previous['event'], $event))) {
                    $findings[] = new CompatibilityFinding(Code::EventContractConflict, $id, "event contract {$event->key()} is already declared by {$previous['owner']}");
                } else {
                    $events[$event->key()] = ['owner' => $id, 'event' => $event];
                }
            }
        }
        foreach ($byId as $id => $descriptor) {
            foreach ($descriptor->requiresModules as $dependency) {
                $target = $byId[$dependency->moduleId] ?? null;
                if ($target === null) {
                    $findings[] = new CompatibilityFinding(Code::DependencyMissing, $id, "{$id} requires unknown module {$dependency->moduleId}");
                } elseif (! $dependency->apiRange->contains($target->moduleApiVersion)) {
                    $findings[] = new CompatibilityFinding(Code::DependencyRange, $id, "{$id} needs {$target->moduleId} API [{$dependency->apiRange->min}, {$dependency->apiRange->maxExclusive}), found {$target->moduleApiVersion}");
                }
            }
            foreach ([...$descriptor->requiresPorts, ...$descriptor->optionalPorts] as $port) {
                $provider = $this->manifest->ports[$port] ?? null;
                if ($provider === null || ($provider !== 'host' && ! isset($byId[$provider]))) {
                    $findings[] = new CompatibilityFinding(Code::PortUnknown, $id, "{$id} uses port {$port} with no known provider");
                }
            }
        }
        [, $cycle] = self::topologicalSort($byId);
        if ($cycle !== []) {
            $findings[] = new CompatibilityFinding(Code::DependencyCycle, null, 'dependency cycle: '.implode(' -> ', $cycle));
        }

        return $findings;
    }

    /**
     * @param  array<string, ModuleDescriptor>  $enabled
     * @return list<CompatibilityFinding>
     */
    private function enabledFindings(ModuleDescriptor $descriptor, array $enabled, CompatibilityInput $input): array
    {
        $findings = [];
        $id = $descriptor->moduleId;
        if (! $descriptor->coreApiRange->contains($this->manifest->coreApiVersion)) {
            $findings[] = new CompatibilityFinding(Code::CoreApiUnsupported, $id, "{$id} does not support core API {$this->manifest->coreApiVersion}");
        }
        foreach ($descriptor->requiredModuleIds() as $required) {
            if (! isset($enabled[$required])) {
                $findings[] = new CompatibilityFinding(Code::DependencyMissing, $id, "{$id} requires {$required}, which is not enabled");
            }
        }
        foreach ($descriptor->requiresPorts as $port) {
            $provider = $this->manifest->ports[$port] ?? null;
            $bound = $provider === 'host' ? in_array($port, $input->providedPorts, true) : ($provider !== null && isset($enabled[$provider]));
            if ($provider !== null && ! $bound) {
                $findings[] = new CompatibilityFinding(Code::PortMissing, $id, "{$id} requires port {$port}, which is not bound");
            }
        }

        $checkpoint = $input->checkpoints[$id] ?? new InstalledCheckpoint($id);
        $missing = array_values(array_filter(
            $descriptor->migrationIdentities,
            static fn (MigrationIdentity $migration): bool => ! in_array($migration->id, $checkpoint->appliedMigrations, true),
        ));
        if ($checkpoint->schemaVersion < $descriptor->currentSchemaVersion || $missing !== []) {
            $findings[] = new CompatibilityFinding(Code::MigrationUnapplied, $id, "{$id} installed schema {$checkpoint->schemaVersion} is behind {$descriptor->currentSchemaVersion}");
        }
        if (! $descriptor->supportedSchemaReadRange->contains($checkpoint->schemaVersion)) {
            $findings[] = new CompatibilityFinding(Code::SchemaReadUnsupported, $id, "{$id} cannot read installed schema {$checkpoint->schemaVersion}");
        } elseif (! $descriptor->supportedSchemaWriteRange->contains($checkpoint->schemaVersion)) {
            $findings[] = new CompatibilityFinding(Code::SchemaWriteUnsupported, $id, "{$id} cannot write installed schema {$checkpoint->schemaVersion}");
        }

        return $findings;
    }

    /**
     * Dependency order of the enabled modules; empty when they contain a cycle.
     *
     * @param  array<string, ModuleDescriptor>  $enabled
     * @return list<string>
     */
    private function bootOrder(array $enabled): array
    {
        [$order, $cycle] = self::topologicalSort($enabled);

        return $cycle === [] ? $order : [];
    }

    /**
     * Delegates Kahn topological sort and cycle detection to Packman's PluginGraph compiler.
     *
     * @param  array<string, ModuleDescriptor>  $nodes
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function topologicalSort(array $nodes): array
    {
        return ModuleGraphPluginBridge::sort($nodes);
    }

    private static function samePublicEvent(PublicEventDeclaration $a, PublicEventDeclaration $b): bool
    {
        return $a->eventType === $b->eventType
            && $a->envelopeSchema === $b->envelopeSchema
            && $a->eventContractRevision === $b->eventContractRevision
            && $a->payloadSchemaRef === $b->payloadSchemaRef
            && $a->payloadSchemaDigest === $b->payloadSchemaDigest
            && $a->bootstrapMode === $b->bootstrapMode
            && $a->snapshotSourceKey === $b->snapshotSourceKey
            && $a->retentionPolicyRef === $b->retentionPolicyRef;
    }
}

<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Contracts\Attributes\Api;
use Billium\Core\Internal\Modules\Shape;

/**
 * The packaged module registry: core API version, host/module ports, compositions and descriptors.
 * A plain container; graph and range validation happens in ModuleRegistry::check so that invalid sets can be reported.
 */
#[Api]
final readonly class ModuleManifest
{
    /**
     * @param  list<string>  $findingCodes
     * @param  array<string, string>  $retentionPolicies  id => purge mode
     * @param  array<string, string>  $ports  port id => 'host' or providing moduleId
     * @param  list<CompositionProfile>  $compositions
     * @param  list<ModuleDescriptor>  $modules
     */
    public function __construct(
        public string $coreApiVersion,
        public array $findingCodes,
        public array $retentionPolicies,
        public array $ports,
        public array $compositions,
        public array $modules,
    ) {}

    public function descriptor(string $moduleId): ?ModuleDescriptor
    {
        foreach ($this->modules as $module) {
            if ($module->moduleId === $moduleId) {
                return $module;
            }
        }

        return null;
    }

    public function composition(string $id): ?CompositionProfile
    {
        foreach ($this->compositions as $composition) {
            if ($composition->id === $id) {
                return $composition;
            }
        }

        return null;
    }

    public static function fromArray(mixed $data): self
    {
        $context = 'manifest';
        $data = Shape::object($data, ['specVersion', 'coreApiVersion', 'findingCodes', 'retentionPolicies', 'ports', 'compositions', 'modules'], $context);
        if (Shape::int($data, 'specVersion', $context) !== 1) {
            throw Shape::fail($context, 'unsupported specVersion');
        }
        $retention = [];
        foreach (Shape::list($data, 'retentionPolicies', $context) as $item) {
            $item = Shape::object($item, ['id', 'purge'], 'retentionPolicy');
            $id = Shape::string($item, 'id', 'retentionPolicy');
            isset($retention[$id]) && throw Shape::fail('retentionPolicy', "duplicate {$id}");
            $retention[$id] = Shape::string($item, 'purge', 'retentionPolicy');
        }
        $ports = [];
        foreach (Shape::list($data, 'ports', $context) as $item) {
            $item = Shape::object($item, ['id', 'providedBy'], 'port');
            $id = Shape::string($item, 'id', 'port');
            isset($ports[$id]) && throw Shape::fail('port', "duplicate {$id}");
            $ports[$id] = Shape::string($item, 'providedBy', 'port');
        }

        return new self(
            coreApiVersion: Shape::string($data, 'coreApiVersion', $context),
            findingCodes: Shape::strings($data, 'findingCodes', $context),
            retentionPolicies: $retention,
            ports: $ports,
            compositions: array_map(static fn (mixed $item): CompositionProfile => CompositionProfile::fromArray($item), Shape::list($data, 'compositions', $context)),
            modules: array_map(static fn (mixed $item): ModuleDescriptor => ModuleDescriptor::fromArray($item), Shape::list($data, 'modules', $context)),
        );
    }
}

<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Core\Enums\Modules\ModulePresence;
use Billium\Core\Internal\Modules\Shape;
use Packman\Attributes\Api;

/**
 * One supported runtime composition row: which modules are mandatory/optional/forbidden, whether the server
 * package is part of it, and which host ports must be absent.
 */
#[Api(since: '1.0')]
final readonly class CompositionProfile
{
    /**
     * @param  array<string, ModulePresence>  $modules
     * @param  array<string, ModulePresence>  $ports  only Forbidden entries are declared
     */
    public function __construct(
        public string $id,
        public array $modules,
        public ModulePresence $server,
        public array $ports,
    ) {}

    public function presence(string $moduleId): ?ModulePresence
    {
        return $this->modules[$moduleId] ?? null;
    }

    public static function fromArray(mixed $data, string $context = 'composition'): self
    {
        $data = Shape::object($data, ['id', 'modules', 'server', 'ports'], $context);
        $modules = self::presences($data['modules'], "{$context}.modules");
        $ports = self::presences($data['ports'], "{$context}.ports");
        $server = is_string($data['server']) ? ModulePresence::tryFrom($data['server']) : null;

        return new self(Shape::string($data, 'id', $context), $modules, $server ?? throw Shape::fail($context, 'server presence must be M, O or X'), $ports);
    }

    /** @return array<string, ModulePresence> */
    private static function presences(mixed $value, string $context): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw Shape::fail($context, 'must be an object');
        }
        $presences = [];
        foreach ($value as $id => $presence) {
            $presences[(string) $id] = (is_string($presence) ? ModulePresence::tryFrom($presence) : null)
                ?? throw Shape::fail($context, "{$id}: presence must be M, O or X");
        }

        return $presences;
    }
}

<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Internal\Modules\Shape;
use Packman\Attributes\Api;

/**
 * What stays served after the module stops accepting new intents. `keeps` narrows the state matrix: a state
 * serves a class only if the descriptor keeps it as well.
 */
#[Api(since: '1.0')]
final readonly class DisablePolicy
{
    /** @param list<ModuleOperationClass> $keeps */
    public function __construct(
        public bool $cascadeDependents,
        public array $keeps,
        public bool $hostObservationGuard,
    ) {
        $required = [ModuleOperationClass::ReceiptReplay, ModuleOperationClass::HistoricalRead];
        foreach ($required as $class) {
            if (! in_array($class, $keeps, true)) {
                throw Shape::fail('disablePolicy', "must keep {$class->value}");
            }
        }
        if (in_array(ModuleOperationClass::NewIntent, $keeps, true)) {
            throw Shape::fail('disablePolicy', 'new_intent cannot be kept: disable stops new operations');
        }
    }

    public function keeps(ModuleOperationClass $class): bool
    {
        return in_array($class, $this->keeps, true);
    }

    public static function fromArray(mixed $data, string $context): self
    {
        $data = Shape::object($data, ['dependents', 'keeps', 'hostObservationGuard'], $context);
        $dependents = Shape::string($data, 'dependents', $context);
        if (! in_array($dependents, ['cascade', 'block'], true)) {
            throw Shape::fail($context, 'dependents must be cascade or block');
        }
        $keeps = array_map(
            static fn (string $value): ModuleOperationClass => ModuleOperationClass::tryFrom($value)
                ?? throw Shape::fail($context, "unknown operation class {$value}"),
            Shape::strings($data, 'keeps', $context),
        );

        return new self($dependents === 'cascade', $keeps, Shape::bool($data, 'hostObservationGuard', $context));
    }
}

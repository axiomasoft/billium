<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Contracts\Attributes\Api;
use Billium\Core\Internal\Modules\Shape;

/** A required module and the module API range this module was built against. */
#[Api]
final readonly class ModuleDependency
{
    public function __construct(public string $moduleId, public ApiRange $apiRange) {}

    public static function fromArray(mixed $data, string $context): self
    {
        $data = Shape::object($data, ['moduleId', 'apiRange'], $context);

        return new self(Shape::string($data, 'moduleId', $context), ApiRange::fromArray($data['apiRange'], "{$context}.apiRange"));
    }
}

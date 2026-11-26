<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Core\Internal\Modules\Shape;
use Packman\Attributes\Api;

/** A required module and the module API range this module was built against. */
#[Api(since: '1.0')]
final readonly class ModuleDependency
{
    public function __construct(public string $moduleId, public ApiRange $apiRange) {}

    public static function fromArray(mixed $data, string $context): self
    {
        $data = Shape::object($data, ['moduleId', 'apiRange'], $context);

        return new self(Shape::string($data, 'moduleId', $context), ApiRange::fromArray($data['apiRange'], "{$context}.apiRange"));
    }
}

<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Core\Internal\Modules\Shape;
use Packman\Attributes\Api;

/** Closed SQL schema-version range of a module migration stream. */
#[Api(since: '1.0')]
final readonly class SchemaRange
{
    public function __construct(public int $min, public int $max)
    {
        if ($min < 0 || $max < $min) {
            throw Shape::fail('schemaRange', "invalid range [{$min}, {$max}]");
        }
    }

    public function contains(int $version): bool
    {
        return $version >= $this->min && $version <= $this->max;
    }

    public function covers(self $other): bool
    {
        return $other->min >= $this->min && $other->max <= $this->max;
    }

    public static function fromArray(mixed $data, string $context): self
    {
        $data = Shape::object($data, ['min', 'max'], $context);

        return new self(Shape::int($data, 'min', $context), Shape::int($data, 'max', $context));
    }
}

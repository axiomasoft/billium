<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Core\Internal\Modules\Semver;
use Billium\Core\Internal\Modules\Shape;
use Packman\Attributes\Api;

/** Half-open API version range: min inclusive, maxExclusive exclusive. */
#[Api(since: '1.0')]
final readonly class ApiRange
{
    public function __construct(public string $min, public string $maxExclusive)
    {
        if (! Semver::isValid($min) || ! Semver::isValid($maxExclusive) || Semver::compare($min, $maxExclusive) >= 0) {
            throw Shape::fail('apiRange', "invalid range [{$min}, {$maxExclusive})");
        }
    }

    public function contains(string $version): bool
    {
        return Semver::isValid($version)
            && Semver::compare($version, $this->min) >= 0
            && Semver::compare($version, $this->maxExclusive) < 0;
    }

    public static function fromArray(mixed $data, string $context): self
    {
        $data = Shape::object($data, ['min', 'maxExclusive'], $context);

        return new self(Shape::string($data, 'min', $context), Shape::string($data, 'maxExclusive', $context));
    }
}

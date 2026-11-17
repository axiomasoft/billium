<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Core\Internal\Modules\Shape;
use Packman\Attributes\Api;

/** Immutable identity of one shipped module migration: basename, stream version and file checksum. */
#[Api(since: '1.0')]
final readonly class MigrationIdentity
{
    public function __construct(public string $id, public int $version, public string $checksum)
    {
        if (preg_match('/^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+$/', $id) !== 1) {
            throw Shape::fail('migrationIdentity', "invalid basename {$id}");
        }
        if ($version < 1) {
            throw Shape::fail('migrationIdentity', 'version starts at 1');
        }
        if (preg_match('/^sha256:[0-9a-f]{64}$/', $checksum) !== 1) {
            throw Shape::fail('migrationIdentity', 'checksum must be sha256:<hex>');
        }
    }

    public static function fromArray(mixed $data, string $context): self
    {
        $data = Shape::object($data, ['id', 'version', 'checksum'], $context);

        return new self(Shape::string($data, 'id', $context), Shape::int($data, 'version', $context), Shape::string($data, 'checksum', $context));
    }
}

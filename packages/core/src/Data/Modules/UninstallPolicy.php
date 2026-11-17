<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Core\Internal\Modules\Shape;
use Packman\Attributes\Api;

/**
 * Uninstalling a binary is not a schema purge (D55): with module history a compatible read/recovery bundle is
 * required, and purge stays a separate privileged retention operation. There is no automatic financial purge in 1.0.
 */
#[Api(since: '1.0')]
final readonly class UninstallPolicy
{
    public const string SCHEMA_PURGE = 'never_automatic';

    public const string PURGE = 'privileged_retention_operation';

    public function __construct(
        public bool $readBundleRequiredWithHistory = true,
        public string $schemaPurge = self::SCHEMA_PURGE,
        public string $purge = self::PURGE,
    ) {
        if (! $readBundleRequiredWithHistory || $schemaPurge !== self::SCHEMA_PURGE || $purge !== self::PURGE) {
            throw Shape::fail('uninstallPolicy', 'a read bundle is required with history and purge is never automatic');
        }
    }

    public static function fromArray(mixed $data, string $context): self
    {
        $data = Shape::object($data, ['readBundleRequiredWithHistory', 'schemaPurge', 'purge'], $context);

        return new self(
            Shape::bool($data, 'readBundleRequiredWithHistory', $context),
            Shape::string($data, 'schemaPurge', $context),
            Shape::string($data, 'purge', $context),
        );
    }
}

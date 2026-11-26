<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Packman\Attributes\Api;

/** What an installation really applied for a module stream: version 0 and no migrations when uninstalled. */
#[Api(since: '1.0')]
final readonly class InstalledCheckpoint
{
    /** @param list<string> $appliedMigrations basenames */
    public function __construct(public string $moduleId, public int $schemaVersion = 0, public array $appliedMigrations = []) {}
}

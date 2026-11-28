<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Runtime;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
interface ContractCapabilities
{
    public function currentSpecRevision(): string;

    /** @return list<string> */
    public function supportedSpecRevisions(): array;

    public function supportsSpecRevision(string $revision): bool;

    /** @return list<string> */
    public function supportedUnits(): array;

    public function isModuleEnabled(string $moduleId): bool;

    public function supportsUnit(string $unit): bool;

    public function supportsRatingStrategy(string $strategyId, int $version): bool;

    public function hasRealUsageAttribution(): bool;

    public function supportsTokenOverage(): bool;
}

<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Runtime;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Contracts\SpecRevision;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runtime\ContractCapabilities;
use Billium\Rating\Contracts\PricingStrategyRegistry;
use Billium\Rating\Enums\StrategyId;
use Billium\Usage\Contracts\UsageAttribution;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;
use Throwable;

#[Internal]
final readonly class DefaultContractCapabilities implements ContractCapabilities
{
    public function __construct(
        private Container $container,
        private BilliumOptions $options,
    ) {}

    public function currentSpecRevision(): string
    {
        return SpecRevision::CURRENT;
    }

    /** @return list<string> */
    public function supportedSpecRevisions(): array
    {
        return SpecRevision::SUPPORTED;
    }

    public function supportsSpecRevision(string $revision): bool
    {
        return in_array($revision, SpecRevision::SUPPORTED, true);
    }

    /** @return list<string> */
    public function supportedUnits(): array
    {
        $units = Codec::forRevision(SpecRevision::CURRENT)->supportedUnits();
        if (! $this->isModuleEnabled('usage') || ! $this->hasRealUsageAttribution()) {
            return array_values(array_diff($units, ['token']));
        }

        return $units;
    }

    public function isModuleEnabled(string $moduleId): bool
    {
        return $this->options->moduleEnabled($moduleId);
    }

    public function supportsUnit(string $unit): bool
    {
        return in_array($unit, $this->supportedUnits(), true);
    }

    public function supportsRatingStrategy(string $strategyId, int $version): bool
    {
        if (! $this->isModuleEnabled('rating') || ! $this->container->bound(PricingStrategyRegistry::class)) {
            return false;
        }
        $id = StrategyId::tryFrom($strategyId);
        if ($id === null) {
            return false;
        }
        try {
            $registry = $this->container->make(PricingStrategyRegistry::class);
            $registry->resolve($id, $version);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function hasRealUsageAttribution(): bool
    {
        if (! $this->isModuleEnabled('usage') || ! $this->container->bound(UsageAttribution::class)) {
            return false;
        }
        $bound = $this->container->make(UsageAttribution::class);

        return ! str_ends_with($bound::class, '\\UnresolvedAttribution');
    }

    public function supportsTokenOverage(): bool
    {
        return $this->supportsSpecRevision('2026-12')
            && $this->isModuleEnabled('usage')
            && $this->isModuleEnabled('rating')
            && $this->isModuleEnabled('schedule')
            && $this->isModuleEnabled('ledger')
            && $this->hasRealUsageAttribution()
            && $this->supportsUnit('token')
            && $this->supportsRatingStrategy('per_unit', 2);
    }
}

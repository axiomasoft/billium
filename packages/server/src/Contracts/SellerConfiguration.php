<?php

declare(strict_types=1);

namespace Billium\Server\Contracts;

use Billium\Server\Data\MerchantConfigurationScope;
use Billium\Server\Data\SellerLegalSnapshot;
use Billium\Server\Data\SellerScope;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Host inputs to the corresponding module ports. Missing configuration has no implicit default. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface SellerConfiguration
{
    public function legalSnapshot(SellerScope $scope, string $revisionRef): ?SellerLegalSnapshot;

    /** @return list<string> */
    public function allowedCurrencies(SellerScope $scope): array;

    public function numberingSeries(SellerScope $scope, string $documentKind, string $periodRef): ?string;

    /** Resolve the exact revision, including when the operation has a historical binding. */
    public function merchantConfiguration(SellerScope $scope, string $revisionRef): ?MerchantConfigurationScope;
}

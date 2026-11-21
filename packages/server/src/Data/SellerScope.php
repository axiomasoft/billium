<?php

declare(strict_types=1);

namespace Billium\Server\Data;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class SellerScope
{
    public function __construct(public string $installationRef, public string $environment, public string $sellerRef)
    {
        foreach ([$installationRef, $environment, $sellerRef] as $ref) {
            if (trim($ref) === '' || str_contains($ref, "\0")) {
                throw new InvalidArgumentException('Seller scope requires explicit installation, environment and seller');
            }
        }
    }

    public function matches(self $scope): bool
    {
        return $this->installationRef === $scope->installationRef
            && $this->environment === $scope->environment
            && $this->sellerRef === $scope->sellerRef;
    }
}

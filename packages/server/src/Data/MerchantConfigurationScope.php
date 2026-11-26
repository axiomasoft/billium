<?php

declare(strict_types=1);

namespace Billium\Server\Data;

use Packman\Attributes\Api;

/** Public identity of a frozen configuration revision. Credential locators stay in the execution/deployment boundary. */
#[Api(since: '1.0')]
final readonly class MerchantConfigurationScope
{
    public function __construct(public SellerScope $scope, public string $revisionRef, public string $merchantAccountRef) {}
}

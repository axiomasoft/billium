<?php

declare(strict_types=1);

namespace Billium\Server\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class SellerAdmission
{
    public function __construct(public SellerScope $scope, public bool $eligible) {}
}

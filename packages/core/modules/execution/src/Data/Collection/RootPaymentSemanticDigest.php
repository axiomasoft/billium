<?php

declare(strict_types=1);

namespace Billium\Execution\Data\Collection;

use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\OriginDecision;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Packman\Attributes\Api;

/** Produces the exact execution digest a collection authorization freezes. */
#[Api(since: '1.0')]
final readonly class RootPaymentSemanticDigest
{
    public static function forCharge(ExecutionScope $scope, ExecutePayment $command, MerchantRevision $merchant, OriginDecision $origin): string
    {
        return PaymentIdentity::semantic($scope, $command, $merchant, $origin);
    }
}

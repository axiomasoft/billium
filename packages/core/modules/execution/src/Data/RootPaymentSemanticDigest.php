<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Produces the exact execution digest a collection authorization freezes (D63/F04). */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class RootPaymentSemanticDigest
{
    public static function forCharge(ExecutionScope $scope, ExecutePayment $command, MerchantRevision $merchant, OriginDecision $origin): string
    {
        return PaymentIdentity::semantic($scope, $command, $merchant, $origin);
    }
}

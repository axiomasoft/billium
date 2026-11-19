<?php

declare(strict_types=1);

namespace Billium\Execution\Data\Fiscal;

use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Enums\Fiscal\FiscalRoute;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FiscalLookupRequest
{
    public function __construct(
        public FiscalIntentRecord $intent,
        public MerchantRevision $merchantRevision,
        public ResolvedCredentials $credentials,
        public string $externalId,
        public ?string $providerReceiptId,
        public FiscalRoute $route,
    ) {}
}

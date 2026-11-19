<?php

declare(strict_types=1);

namespace Billium\Execution\Data\Fiscal;

use Billium\Contracts\Data\Money;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Enums\Fiscal\FiscalRoute;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FiscalRegisterRequest
{
    /** @param array<string, mixed> $payloadSnapshot */
    public function __construct(
        public FiscalIntentRecord $intent,
        public MerchantRevision $merchantRevision,
        public ResolvedCredentials $credentials,
        public string $externalId,
        public FiscalRoute $route,
        public Money $amount,
        public array $payloadSnapshot,
    ) {}
}

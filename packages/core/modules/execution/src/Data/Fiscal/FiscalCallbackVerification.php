<?php

declare(strict_types=1);

namespace Billium\Execution\Data\Fiscal;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FiscalCallbackVerification
{
    public function __construct(
        public bool $trusted,
        public ?FiscalDriverResult $result = null,
        public ?string $rejectionReason = null,
    ) {}
}

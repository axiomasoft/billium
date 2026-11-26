<?php

declare(strict_types=1);

namespace Billium\Schedule\Data;

use Billium\Core\Data\Runner\Receipt;
use Packman\Attributes\Api;

#[Api]
final readonly class ScheduleReceipt implements Receipt
{
    /** @param array<string, mixed> $payload */
    public function __construct(private array $payload) {}

    public function receiptClass(): string
    {
        return 'schedule.agreement_revision_committed';
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->payload;
    }
}

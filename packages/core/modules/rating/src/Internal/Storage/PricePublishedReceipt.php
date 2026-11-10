<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Storage;

use Billium\Core\Data\Receipt;

final readonly class PricePublishedReceipt implements Receipt
{
    /** @param array<string, mixed> $payload */
    public function __construct(private array $payload, private string $class = 'rating.price_version_published') {}

    public function receiptClass(): string
    {
        return $this->class;
    }

    public function body(): array
    {
        return $this->payload;
    }
}

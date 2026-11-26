<?php

declare(strict_types=1);

namespace Billium\Documents\Internal;

use Billium\Core\Data\Runner\Receipt;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DocumentReceipt implements Receipt
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        private string $class,
        private array $payload,
    ) {}

    public function receiptClass(): string
    {
        return $this->class;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->payload;
    }
}

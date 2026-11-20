<?php

declare(strict_types=1);

namespace Billium\Collection\Internal;

use Billium\Core\Data\Runner\Receipt;
use Override;
use Packman\Attributes\Internal;

#[Internal]
final readonly class CollectionReceipt implements Receipt
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        private string $class,
        private array $payload,
    ) {}

    #[Override]
    public function receiptClass(): string
    {
        return $this->class;
    }

    /** @return array<string, mixed> */
    #[Override]
    public function body(): array
    {
        return $this->payload;
    }
}

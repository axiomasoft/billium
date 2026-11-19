<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Fiscal;

use Billium\Core\Data\Runner\Receipt;
use Packman\Attributes\Internal;

#[Internal]
final readonly class FiscalReceipt implements Receipt
{
    /** @param array<string, mixed> $body */
    public function __construct(
        private string $class,
        private array $body,
    ) {}

    public function receiptClass(): string
    {
        return $this->class;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->body;
    }
}

<?php

declare(strict_types=1);

namespace Billium\Platform\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Core\Data\Receipt;

/** Receipt of a platform administrative operation (session or module transition). */
#[Api]
final readonly class PlatformReceipt implements Receipt
{
    /** @param array<string, mixed> $body */
    public function __construct(private string $class, private array $body) {}

    public function receiptClass(): string
    {
        return $this->class;
    }

    public function body(): array
    {
        return $this->body;
    }
}

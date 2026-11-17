<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Runtime;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface InternalCommandReceiver
{
    /** @param array<string, mixed> $message */
    public function receive(string $destinationRef, array $message): void;
}

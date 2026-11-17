<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\ProviderEvidence;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Trusted immutable host storage for original callback frames. Both methods run outside a transaction. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface CallbackPayloadArchive
{
    public function retain(ExecutionScope $scope, string $merchantRevisionRef, ProviderEvidence $delivery): string;

    /** Read only an opaque receipt previously returned under the same authenticated scope and merchant revision. */
    public function read(ExecutionScope $scope, string $merchantRevisionRef, string $archiveRef): ProviderEvidence;
}

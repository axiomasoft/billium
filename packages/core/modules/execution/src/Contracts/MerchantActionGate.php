<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\ResolvedCredentials;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Holds the original merchant revision through the current financial decision and checks its action permissions. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface MerchantActionGate
{
    public function lock(TransactionContext $context, ExecutionScope $scope, string $revisionRef, string $purpose, ?ResolvedCredentials $credentials = null): void;
}

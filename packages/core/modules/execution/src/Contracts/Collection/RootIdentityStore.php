<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts\Collection;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Data\ExecutionScope;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Coordinates root identity claims between execution admission and collection authorization. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface RootIdentityStore
{
    /** Lock both root identity references through commit or rollback, before taking collection or execution row locks. */
    public function lockRootIdentity(TransactionContext $context, ExecutionScope $scope, string $operationRef, string $businessIntentRef): void;

    /** Check for a retained execution claim to either reference under the root identity locks. */
    public function rootIdentityExists(TransactionContext $context, ExecutionScope $scope, string $operationRef, string $businessIntentRef): bool;
}

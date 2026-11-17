<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Contracts\Data\Envelopes\ExecutionStatus;
use Billium\Contracts\Data\Envelopes\FindResult;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * find(ExecutionScope $scope,string $operationRef): FindResult; status(ExecutionScope $scope,string $operationRef): ExecutionStatus; receipt(ExecutionScope $scope,string $receiptRef): ExecutionReceipt. DELIVERY_QUERIES owns all implementations.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface ExecutionQueries
{
    public function find(ExecutionScope $scope, string $operationRef): FindResult;

    public function status(ExecutionScope $scope, string $operationRef): ExecutionStatus;

    public function receipt(ExecutionScope $scope, string $receiptRef): ExecutionReceipt;
}

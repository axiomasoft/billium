<?php

declare(strict_types=1);

namespace Billium\Collection\Contracts;

use Billium\Collection\Data\AuthorizeCollection;
use Billium\Contracts\Data\Envelopes\AuthorizeCollection as WireAuthorization;
use Billium\Core\Data\Runtime\FinancialScope;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PinnedCollectionInputs
{
    /** Resolve the pinned executor binding and step for this obligation and business intent. */
    public function resolve(FinancialScope $scope, WireAuthorization $command): ?AuthorizeCollection;
}

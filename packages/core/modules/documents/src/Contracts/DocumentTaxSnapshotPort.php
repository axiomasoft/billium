<?php

declare(strict_types=1);

namespace Billium\Documents\Contracts;

use Billium\Documents\Data\DocumentTaxSnapshot;
use Billium\Documents\Data\TaxSnapshotRequest;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Host-provided tax snapshot port. Always invoked outside any database transaction. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface DocumentTaxSnapshotPort
{
    public function resolve(TaxSnapshotRequest $request): DocumentTaxSnapshot;
}

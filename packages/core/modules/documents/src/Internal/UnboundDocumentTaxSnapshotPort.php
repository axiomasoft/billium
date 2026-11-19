<?php

declare(strict_types=1);

namespace Billium\Documents\Internal;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Documents\Contracts\DocumentTaxSnapshotPort;
use Billium\Documents\Data\DocumentTaxSnapshot;
use Billium\Documents\Data\TaxSnapshotRequest;
use Packman\Attributes\Internal;

#[Internal]
final class UnboundDocumentTaxSnapshotPort implements DocumentTaxSnapshotPort
{
    public function resolve(TaxSnapshotRequest $request): DocumentTaxSnapshot
    {
        throw new ProblemException(ProblemCode::LookupUnavailable, 'Host must bind DocumentTaxSnapshotPort');
    }
}

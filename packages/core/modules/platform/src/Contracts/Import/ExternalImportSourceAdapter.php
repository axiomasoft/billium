<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Import;

use Billium\Platform\Data\Import\ImportManifest;
use Billium\Platform\Data\Import\ImportRecordInput;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Host-implemented adapter that reads a product-agnostic external export artifact or stream (never a foreign host DB connection).
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ExternalImportSourceAdapter
{
    public function manifest(): ImportManifest;

    /**
     * @return iterable<ImportRecordInput>
     */
    public function records(): iterable;
}

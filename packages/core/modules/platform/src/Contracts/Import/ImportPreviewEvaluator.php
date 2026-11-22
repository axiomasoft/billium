<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Import;

use Billium\Platform\Data\Import\ImportManifest;
use Billium\Platform\Data\Import\ImportPreviewReport;
use Billium\Platform\Data\Import\ImportRecordInput;
use Packman\Attributes\Api;

/**
 * Evaluates external.import.v1 records into a typed preview report without mutating financial state.
 */
#[Api(since: '1.0')]
interface ImportPreviewEvaluator
{
    /**
     * @param  list<ImportRecordInput>  $records
     */
    public function evaluate(ImportManifest $manifest, array $records): ImportPreviewReport;
}

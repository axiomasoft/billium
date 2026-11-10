<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Execution\Data\WriterAdmissionRequest;
use Billium\Execution\Data\WriterAdmissionToken;

#[Api]
#[Spi(SpiKind::Implement)]
interface WriterAdmission
{
    public function admit(WriterAdmissionRequest $request): ?WriterAdmissionToken;
}

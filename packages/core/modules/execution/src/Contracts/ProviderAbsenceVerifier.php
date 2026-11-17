<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Execution\Data\AbsenceCertificate;
use Billium\Execution\Data\FrozenBinding;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * verify(AbsenceCertificate $certificate,FrozenBinding $binding): void at Tx0 against authenticated provider/authority proof, not self-asserted fields; cache checked result for reducer input
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ProviderAbsenceVerifier
{
    public function verify(AbsenceCertificate $certificate, FrozenBinding $binding): void;
}

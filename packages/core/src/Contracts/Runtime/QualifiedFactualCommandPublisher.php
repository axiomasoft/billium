<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Runtime;

use Billium\Core\Data\Runtime\FinancialScope;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Detached, credential-pinned transport for independently attested execution facts. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface QualifiedFactualCommandPublisher extends EventPublisher
{
    /**
     * Verify original producer, frozen root and lineage against independent origin evidence before signing.
     * The outbox supplies selectors only. A missing origin fails delivery.
     *
     * @param  array<string,mixed>  $message
     */
    public function publishQualified(string $destinationRef, array $message, FinancialScope $scope): void;
}

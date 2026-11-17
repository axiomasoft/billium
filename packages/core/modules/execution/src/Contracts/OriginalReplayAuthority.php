<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\DriverRequest;
use Billium\Execution\Data\OriginalProviderRequest;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Data\ReplayAuthorization;
use Closure;
use DateTimeImmutable;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Independently retained first-egress commitment and explicit operator approval. Both calls require transaction level zero. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface OriginalReplayAuthority
{
    /** Match the actual first-send commitment against the independent egress journal and separately verify the operator grant, exact scope, writer and window. The live local ticket may have been retired. */
    public function authorize(string $approvalRef, DispatchTicket $writer, OriginalProviderRequest $original, DateTimeImmutable $originalWindowEndsAt): ReplayAuthorization;

    /**
     * Fence current owner/epoch, revocation, exact bytes, grant, key, historical account and finite deadline at transport.
     * Consume permit before sending in independent, restore-resistant authority storage.
     *
     * @param  Closure():ProviderLookupResult  $send
     */
    public function invoke(ReplayAuthorization $approval, DispatchTicket $writer, OriginalProviderRequest $original, DriverRequest $current, Closure $send): ProviderLookupResult;
}

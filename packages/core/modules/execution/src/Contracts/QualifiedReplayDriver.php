<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\OriginalProviderRequest;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Data\ResolvedCredentials;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Optional qualified provider transport. At first egress retain a protected archive of the transmitted bytes and key, historical account and first dispatch ticket reference, independently of Billium. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface QualifiedReplayDriver
{
    public function originalRequest(FrozenBinding $binding, AttemptRecord $attempt): ?OriginalProviderRequest;

    /** Transmit the exact archived request under its original provider key and historical account. */
    public function replayOriginalWithinWindow(OriginalProviderRequest $original, ResolvedCredentials $credentials): ProviderLookupResult;
}

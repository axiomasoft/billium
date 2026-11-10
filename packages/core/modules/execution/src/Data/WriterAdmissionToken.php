<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Core\Data\FinancialAdmissionToken;
use Billium\Execution\Enums\EffectKind;
use DateTimeImmutable;

/** Verified host claims, not a self-authenticating DB lease; no authority service is implemented in P5.1 (D62). */
#[Api]
final readonly class WriterAdmissionToken
{
    /** @param list<EffectKind> $permittedActions */
    public function __construct(
        public FinancialAdmissionToken $financialToken,
        public string $merchantAccountRef,
        public string $environment,
        public string $bindingRevision,
        public array $permittedActions,
        public bool $recoveryAllowed,
        public DateTimeImmutable $expiresAt,
        public string $egressFenceRef,
    ) {}
}

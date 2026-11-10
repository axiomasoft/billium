<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Proration;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Data\Correction\HostAmendment;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Enums\StrategyId;

final class AmendmentSupport
{
    /** @param array<string, mixed>|null $quantitySnapshot */
    public function assertSupported(string $kind, PriceVersion $price, ?array $quantitySnapshot = null): void
    {
        if ($kind !== 'partial_cancel' || ! in_array($price->strategyId, [StrategyId::Fixed, StrategyId::PerUnit], true)
            || ($price->strategyId === StrategyId::PerUnit && ($quantitySnapshot['prorationPolicy'] ?? null) !== 'whole_interval/1')
            || $price->commitment !== null) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.amendment_unsupported');
        }
    }

    /** @param array<string, mixed>|null $previous */
    public function assertRevision(?array $previous, HostAmendment $amendment): void
    {
        if ($amendment->amendmentRef === '' || $amendment->revision < 1 || $amendment->kind !== 'partial_cancel') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.amendment_unsupported');
        }
        if ($previous === null) {
            return;
        }
        if (($previous['amendmentRef'] ?? null) !== $amendment->amendmentRef || ! is_int($previous['revision'] ?? null)
            || $amendment->revision < $previous['revision']) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.amendment_unsupported');
        }
        if ($amendment->revision === $previous['revision'] && [
            $previous['effectiveAt'] ?? null, $previous['policy'] ?? null, $previous['timezone'] ?? null,
        ] !== [$amendment->effectiveAt->format('Y-m-d\TH:i:s.uP'), $amendment->prorationPolicy->value, $amendment->timezone]) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'billium.rating.amendment_revision_conflict');
        }
    }
}

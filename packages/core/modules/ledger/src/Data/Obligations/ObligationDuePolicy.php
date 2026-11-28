<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Obligations;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Frozen host inputs; null dueAt disables automatic due collection (BC-ENV-28).
 */
#[Api(since: '1.0')]
final readonly class ObligationDuePolicy
{
    public function __construct(
        public ?DateTimeImmutable $dueAt = null,
        public ?string $duePolicyVersion = null,
        public ?string $sourcePolicyRef = null,
        public ?string $sourcePolicySnapshotDigest = null,
        public ?DateTimeImmutable $collectionEligibleAt = null,
    ) {
        $provided = $dueAt !== null || $collectionEligibleAt !== null || $duePolicyVersion !== null
            || $sourcePolicyRef !== null || $sourcePolicySnapshotDigest !== null;
        if ($provided && (! $this->hasProvenance() || $duePolicyVersion === '' || $sourcePolicyRef === ''
            || preg_match('/^sha256:[0-9a-f]{64}$/D', $sourcePolicySnapshotDigest ?? '') !== 1)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'due policy needs version, source reference and snapshot digest');
        }
    }

    public function hasProvenance(): bool
    {
        return $this->duePolicyVersion !== null && $this->sourcePolicyRef !== null && $this->sourcePolicySnapshotDigest !== null;
    }
}

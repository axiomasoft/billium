<?php

declare(strict_types=1);

namespace Billium\Platform\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Core\Data\StoredOutcome;
use InvalidArgumentException;

/** Either a new in-progress claim row of this root Tx or the replay of a completed one (INV-05). */
#[Api]
final readonly class IdempotencyClaim
{
    private function __construct(public ?int $claimId, public ?StoredOutcome $replay) {}

    public static function claimed(int $claimId): self
    {
        if ($claimId < 1) {
            throw new InvalidArgumentException('Claim id is positive');
        }

        return new self($claimId, null);
    }

    public static function replay(StoredOutcome $outcome): self
    {
        return new self(null, $outcome->asReplay());
    }

    public function isNew(): bool
    {
        return $this->claimId !== null;
    }
}

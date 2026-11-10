<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Rating\Data\Rating\BookingReceipt;
use Billium\Rating\Data\Rating\RatedBasisDraft;

#[Api]
interface RatingQueries
{
    public function basis(string $basisRef): ?RatedBasisDraft;

    public function bookingByBasis(string $basisRef): ?BookingReceipt;

    /** @return array{rowVersion: int, headDigest: string, headBasisRef: ?string} */
    public function frontier(string $occurrenceRef): array;

    public function replay(string $basisRef): RatedBasisDraft;
}

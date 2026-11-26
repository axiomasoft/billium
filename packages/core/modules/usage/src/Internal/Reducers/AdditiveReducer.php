<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Reducers;

use Billium\Usage\Data\FactRevision;
use Billium\Usage\Internal\Errors\UsageProblems;
use Packman\Attributes\Internal;

#[Internal]
final class AdditiveReducer
{
    /**
     * @param  list<FactRevision>  $effective
     * @return numeric-string
     */
    public function reduce(array $effective, bool $allowNegative): string
    {
        $sum = '0';
        foreach ($effective as $fact) {
            if (! $allowNegative && bccomp($fact->quantityUnscaled, '0', 0) < 0) {
                throw UsageProblems::error('negative_quantity');
            }
            $sum = bcadd($sum, $fact->quantityUnscaled, 0);
        }

        return $sum;
    }
}

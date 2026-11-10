<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Corrections;

use Billium\Rating\Data\Tax\TaxCorrectionPolicySnapshot;
use Billium\Rating\Internal\Canonical\CanonicalJson;

final class CorrectionDigest
{
    /** @param list<string> $sourceSnapshotDigests */
    public static function frontier(array $sourceSnapshotDigests, TaxCorrectionPolicySnapshot $policy, ?string $amendmentDigest = null): string
    {
        sort($sourceSnapshotDigests, SORT_STRING);

        return CanonicalJson::digest(['sources' => $sourceSnapshotDigests, 'amendment' => $amendmentDigest,
            'taxPolicyRef' => $policy->policyRef, 'taxPolicyVersion' => $policy->policyVersion,
            'taxPolicyInputDigest' => $policy->inputDigest, 'taxPolicyOutputDigest' => $policy->outputDigest,
            'selectedRate' => $policy->selectedRate, 'resolverVersion' => $policy->resolverVersion]);
    }
}

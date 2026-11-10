<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\QualificationManifest as WireManifest;
use InvalidArgumentException;

#[Api]
final readonly class QualificationManifest
{
    private WireManifest $values;

    public function __construct(string $json)
    {
        $codec = new Codec;
        $wire = $codec->decode($json);
        if (! $wire instanceof WireManifest) {
            throw new InvalidArgumentException('Expected payment.qualification.v1');
        }
        $this->values = $wire;
    }

    public function qualifies(CapabilitySnapshot $snapshot, bool $production): bool
    {
        $v = $this->values;
        foreach ($snapshot->identity() as $key => $value) {
            if ($v->{$key} !== $value) {
                return false;
            }
        }

        return $v->capabilityDigest === $snapshot->digest && $v->status === 'qualified'
            && $v->unresolvedGaps === [] && (! $production || $v->qualification === 'production');
    }
}

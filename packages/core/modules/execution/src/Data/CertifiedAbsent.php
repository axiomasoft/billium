<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\DriverLookupResult;
use DateTimeImmutable;
use InvalidArgumentException;
use stdClass;

/** Structural evidence only. The recovery owner must authenticate provider/fence evidence externally (D54/D62). */
#[Api]
final readonly class CertifiedAbsent
{
    public string $fenceProofRef;

    public string $certificateJson;

    public function __construct(string $json, DriverLookup $query, CapabilitySnapshot $capabilities)
    {
        if (! $capabilities->allowsCertifiedAbsence($query)) {
            throw new InvalidArgumentException('Pinned lookup capability cannot certify absence');
        }
        $codec = new Codec;
        $wire = $codec->decode($json);
        if (! $wire instanceof DriverLookupResult || $wire->status !== 'certified_absent') {
            throw new InvalidArgumentException('Certified absence requires a certificate');
        }
        $certificate = $wire->absenceCertificate;
        if ($certificate === null) {
            throw new InvalidArgumentException('Missing absence certificate');
        }
        foreach (['merchantAccountRef' => $query->binding->merchantAccountRef, 'environment' => $query->binding->environment,
            'selectorKind' => $query->selectorKind, 'selectorValue' => $query->selectorValue] as $key => $expected) {
            if ($wire->raw->{$key} !== $expected || $certificate->{$key} !== $expected) {
                throw new InvalidArgumentException('Certificate scope differs from the original query');
            }
        }
        $from = new DateTimeImmutable(self::text($certificate, 'dispatchFrom'));
        $through = new DateTimeImmutable(self::text($certificate, 'dispatchThrough'));
        if ($from > $query->dispatchFrom || $through < $query->dispatchThrough
            || new DateTimeImmutable(self::text($certificate, 'retainedFrom')) > $from
            || new DateTimeImmutable(self::text($certificate, 'providerCompleteThrough')) < $through) {
            throw new InvalidArgumentException('Certificate completeness does not cover all old dispatches');
        }
        $this->fenceProofRef = self::text($certificate, 'fenceProofRef');
        $this->certificateJson = $codec->encode($wire);
    }

    private static function text(stdClass $certificate, string $key): string
    {
        $value = $certificate->{$key} ?? null;

        return is_string($value) ? $value : throw new InvalidArgumentException('Expected certificate string');
    }
}

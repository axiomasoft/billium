<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Billium\Documents\Enums\TaxConvention;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class DocumentTaxSnapshot
{
    /** @param list<DocumentTaxLineSnapshot> $lines */
    public function __construct(
        public string $taxSnapshotRef,
        public string $taxPolicyVersion,
        public string $taxPoint,
        public TaxConvention $convention,
        public string $currency,
        public int $currencyExponent,
        public int $subtotalMinor,
        public int $taxMinor,
        public int $totalMinor,
        public array $lines,
        public string $inputDigest,
        public string $evidenceDigest,
        public string $resolver,
        public string $resolverVersion,
        public ?string $noTaxReason = null,
    ) {}
}

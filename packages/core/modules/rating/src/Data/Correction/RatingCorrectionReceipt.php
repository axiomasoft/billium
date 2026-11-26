<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Correction;

use Billium\Core\Data\Runner\Receipt;
use Billium\Rating\Internal\Storage\RatingRows;
use Packman\Attributes\Api;

#[Api]
final readonly class RatingCorrectionReceipt implements Receipt
{
    public function __construct(
        public string $correctionRef,
        public int $correctionRevision,
        public string $outcome,
        public string $deltaGrossMinor,
        public ?string $obligationRef,
        public ?string $creditNoteRef,
        public string $currency,
        public bool $legalRequirementPending = true,
    ) {}

    public function receiptClass(): string
    {
        return 'rating.correction';
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return ['correctionRef' => $this->correctionRef, 'correctionRevision' => $this->correctionRevision,
            'outcome' => $this->outcome, 'deltaGrossMinor' => $this->deltaGrossMinor, 'obligationRef' => $this->obligationRef,
            'creditNoteRef' => $this->creditNoteRef, 'currency' => $this->currency, 'legalRequirementPending' => $this->legalRequirementPending];
    }

    /** @param array<string, mixed> $body */
    public static function fromBody(array $body): self
    {
        return new self(correctionRef: RatingRows::text($body['correctionRef'] ?? null), correctionRevision: RatingRows::integer($body['correctionRevision'] ?? null),
            outcome: RatingRows::text($body['outcome'] ?? null), deltaGrossMinor: RatingRows::text($body['deltaGrossMinor'] ?? null),
            obligationRef: RatingRows::nullableText($body['obligationRef'] ?? null), creditNoteRef: RatingRows::nullableText($body['creditNoteRef'] ?? null),
            currency: RatingRows::text($body['currency'] ?? null), legalRequirementPending: RatingRows::boolean($body['legalRequirementPending'] ?? null));
    }
}

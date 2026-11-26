<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Billium\Contracts\Data\Money;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Post-apply linked correction request (D63/F01, D71, IM-10) without destructive rollback or automatic PSP dispatch.
 */
#[Api(since: '1.0')]
final readonly class ImportLinkedCorrection
{
    public const string KIND_POSITIVE_OBLIGATION = 'positive_obligation';

    public const string KIND_NEGATIVE_CREDIT_NOTE = 'negative_credit_note';

    public const string KIND_DRAFT_ADJUSTMENT = 'draft_obligation_adjustment';

    public const string KIND_TOMBSTONE_MAPPING = 'tombstone_mapping';

    /**
     * @param  list<array{componentRef: string, weightMinor: int}>  $componentWeights
     */
    public function __construct(
        public string $capabilityScope,
        public string $correctionRef,
        public ImportSourceIdentity $originalIdentity,
        public int $originalRevision,
        public int $newRevision,
        public string $kind,
        public string $reason,
        public ?Money $deltaMoney = null,
        public ?string $billingAccountRef = null,
        public ?string $customerRef = null,
        public ?string $invoiceRef = null,
        public string $creditNoteMode = 'open_only',
        public ?string $termsDigest = null,
        public ?string $priceVersionRef = null,
        public ?string $taxSnapshotRef = null,
        public array $componentWeights = [],
    ) {
        if (trim($correctionRef) === '' || strlen($correctionRef) > 200 || str_contains($correctionRef, "\0")) {
            throw new InvalidArgumentException('ImportLinkedCorrection correctionRef must be bounded and nonempty');
        }
        if (trim($reason) === '' || strlen($reason) > 200 || str_contains($reason, "\0")) {
            throw new InvalidArgumentException('ImportLinkedCorrection reason must be bounded and nonempty');
        }
        if (! in_array($kind, [
            self::KIND_POSITIVE_OBLIGATION,
            self::KIND_NEGATIVE_CREDIT_NOTE,
            self::KIND_DRAFT_ADJUSTMENT,
            self::KIND_TOMBSTONE_MAPPING,
        ], true)) {
            throw new InvalidArgumentException("Unsupported ImportLinkedCorrection kind '{$kind}'");
        }
        if ($kind !== self::KIND_TOMBSTONE_MAPPING && $newRevision <= $originalRevision) {
            throw new InvalidArgumentException('ImportLinkedCorrection newRevision must exceed originalRevision');
        }
    }
}

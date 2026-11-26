<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class InventoryPage
{
    /** @var list<ProviderEvidence> */
    public array $evidence;

    /**
     * @param  array<mixed>  $evidence
     */
    public function __construct(
        array $evidence,
        public ?string $nextCursor,
        public ?string $completenessWatermark,
        public bool $complete,
        public string $accountRef,
        public string $qualificationEvidenceRef,
    ) {
        if (! array_is_list($evidence)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'evidence must be a list');
        }
        $evidence = array_map(static function (mixed $item): ProviderEvidence {
            if (! $item instanceof ProviderEvidence) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'evidence has an invalid element');
            }

            return $item;
        }, $evidence);
        $this->evidence = $evidence;
        if ($nextCursor === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'nextCursor must be null or nonempty');
        }
        if ($completenessWatermark === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'completenessWatermark must be null or nonempty');
        }
        if ($accountRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'accountRef must not be empty');
        }
        if ($qualificationEvidenceRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'qualificationEvidenceRef must not be empty');
        }
    }
}

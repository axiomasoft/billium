<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Enums\ReductionDisposition;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ReductionResult
{
    /** @var list<string> */
    public array $evidenceRefs;

    /** @var list<string> */
    public array $observationCommandRefs;

    /** @var list<string> */
    public array $publicEventIds;

    /**
     * @param  array<mixed>  $evidenceRefs
     * @param  array<mixed>  $observationCommandRefs
     * @param  array<mixed>  $publicEventIds
     */
    public function __construct(
        public ReductionDisposition $disposition,
        public string $operationRef,
        public ?int $revision,
        array $evidenceRefs,
        array $observationCommandRefs,
        array $publicEventIds,
        public ?string $discrepancyRef,
    ) {
        if ($operationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'operationRef must not be empty');
        }
        if (! array_is_list($evidenceRefs)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'evidenceRefs must be a list');
        }
        $evidenceRefs = array_map(static function (mixed $item): string {
            if (! is_string($item) || $item === '') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'evidenceRefs has an invalid element');
            }

            return $item;
        }, $evidenceRefs);
        $this->evidenceRefs = $evidenceRefs;
        if (! array_is_list($observationCommandRefs)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'observationCommandRefs must be a list');
        }
        $observationCommandRefs = array_map(static function (mixed $item): string {
            if (! is_string($item) || $item === '') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'observationCommandRefs has an invalid element');
            }

            return $item;
        }, $observationCommandRefs);
        $this->observationCommandRefs = $observationCommandRefs;
        if (! array_is_list($publicEventIds)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'publicEventIds must be a list');
        }
        $publicEventIds = array_map(static function (mixed $item): string {
            if (! is_string($item) || $item === '') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'publicEventIds has an invalid element');
            }

            return $item;
        }, $publicEventIds);
        $this->publicEventIds = $publicEventIds;
        if ($discrepancyRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'discrepancyRef must be null or nonempty');
        }
    }
}

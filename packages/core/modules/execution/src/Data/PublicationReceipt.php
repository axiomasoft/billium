<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class PublicationReceipt
{
    /** @var list<string> */
    public array $commandRefs;

    /** @var list<int> */
    public array $commandRowIds;

    /** @var list<string> */
    public array $eventIds;

    /** @var list<int> */
    public array $sourceSequences;

    /**
     * @param  array<mixed>  $commandRefs
     * @param  array<mixed>  $commandRowIds
     * @param  array<mixed>  $eventIds
     * @param  array<mixed>  $sourceSequences
     */
    public function __construct(
        array $commandRefs,
        array $commandRowIds,
        array $eventIds,
        array $sourceSequences,
    ) {
        if (! array_is_list($commandRefs)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'commandRefs must be a list');
        }
        $commandRefs = array_map(static function (mixed $item): string {
            if (! is_string($item) || $item === '') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'commandRefs has an invalid element');
            }

            return $item;
        }, $commandRefs);
        $this->commandRefs = $commandRefs;
        if (! array_is_list($commandRowIds)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'commandRowIds must be a list');
        }
        $commandRowIds = array_map(static function (mixed $item): int {
            if (! is_int($item)) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'commandRowIds has an invalid element');
            }

            return $item;
        }, $commandRowIds);
        $this->commandRowIds = $commandRowIds;
        if (! array_is_list($eventIds)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'eventIds must be a list');
        }
        $eventIds = array_map(static function (mixed $item): string {
            if (! is_string($item) || $item === '') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'eventIds has an invalid element');
            }

            return $item;
        }, $eventIds);
        $this->eventIds = $eventIds;
        if (! array_is_list($sourceSequences)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'sourceSequences must be a list');
        }
        $sourceSequences = array_map(static function (mixed $item): int {
            if (! is_int($item)) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'sourceSequences has an invalid element');
            }

            return $item;
        }, $sourceSequences);
        $this->sourceSequences = $sourceSequences;
    }
}

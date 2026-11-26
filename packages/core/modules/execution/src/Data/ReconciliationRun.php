<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runtime\UnresolvedDisposition;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ReconciliationRun
{
    /** @var list<UnresolvedDisposition> */
    public array $unresolved;

    /**
     * @param  array<mixed>  $unresolved
     */
    public function __construct(
        public string $runRef,
        public ExecutionScope $scope,
        public string $merchantAccountRef,
        public DateTimeImmutable $recoveryHorizon,
        public ?string $inventoryCursor,
        public ?string $completenessWatermark,
        public ?string $inventoryDigest,
        public ?string $manifestDigest,
        public string $state,
        array $unresolved,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
    ) {
        if ($runRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'runRef must not be empty');
        }
        if ($merchantAccountRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'merchantAccountRef must not be empty');
        }
        if ($inventoryCursor === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'inventoryCursor must be null or nonempty');
        }
        if ($completenessWatermark === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'completenessWatermark must be null or nonempty');
        }
        if ($inventoryDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'inventoryDigest must be null or nonempty');
        }
        if ($manifestDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'manifestDigest must be null or nonempty');
        }
        if ($state === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'state must not be empty');
        }
        if (! array_is_list($unresolved)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'unresolved must be a list');
        }
        $unresolved = array_map(static function (mixed $item): UnresolvedDisposition {
            if (! $item instanceof UnresolvedDisposition) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'unresolved has an invalid element');
            }

            return $item;
        }, $unresolved);
        $this->unresolved = $unresolved;
    }
}

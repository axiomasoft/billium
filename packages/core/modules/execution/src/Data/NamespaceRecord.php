<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Enums\NamespaceState;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class NamespaceRecord
{
    public function __construct(
        public ExecutionScope $scope,
        public NamespaceState $state,
        public string $canonicalizationVersion,
        public ?DateTimeImmutable $historyFrom,
        public ?string $completenessReceiptRef,
        public int $generation,
    ) {
        if ($canonicalizationVersion === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'canonicalizationVersion must not be empty');
        }
        if ($completenessReceiptRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'completenessReceiptRef must be null or nonempty');
        }
    }
}

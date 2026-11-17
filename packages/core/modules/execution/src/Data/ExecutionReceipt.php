<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runner\Receipt;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ExecutionReceipt implements Receipt
{
    /** @var list<string> */
    public array $evidenceRefs;

    /**
     * @param  array<mixed>  $evidenceRefs
     */
    public function __construct(
        public string $receiptRef,
        public string $commandKey,
        public string $operationRef,
        public string $semanticDigest,
        public string $status,
        public bool $replayed,
        array $evidenceRefs,
    ) {
        if ($receiptRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'receiptRef must not be empty');
        }
        if ($commandKey === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'commandKey must not be empty');
        }
        if ($operationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'operationRef must not be empty');
        }
        if ($semanticDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'semanticDigest must not be empty');
        }
        if ($status === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'status must not be empty');
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
    }

    public function receiptClass(): string
    {
        return 'execution.operation';
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return [
            'receiptRef' => $this->receiptRef,
            'commandKey' => $this->commandKey,
            'operationRef' => $this->operationRef,
            'semanticDigest' => $this->semanticDigest,
            'status' => $this->status,
            'replayed' => $this->replayed,
            'evidenceRefs' => $this->evidenceRefs,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\PaymentAdmission;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runner\StoredOutcome;
use LogicException;
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
        public ?int $revision = null,
        public ?string $outcomeQueryRef = null,
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
        $body = [
            'receiptRef' => $this->receiptRef,
            'commandKey' => $this->commandKey,
            'operationRef' => $this->operationRef,
            'semanticDigest' => $this->semanticDigest,
            'status' => $this->status,
            'replayed' => $this->replayed,
            'evidenceRefs' => $this->evidenceRefs,
        ];
        if ($this->revision !== null && $this->outcomeQueryRef !== null) {
            $body['revision'] = $this->revision;
            $body['outcomeQueryRef'] = $this->outcomeQueryRef;
            $body['wireReceipt'] = self::wire($body);
        }

        return $body;
    }

    public static function admission(StoredOutcome $outcome): PaymentAdmission
    {
        $body = $outcome->orThrow()->body;
        if ($outcome->receiptClass !== 'execution.operation' || ! in_array($body['status'] ?? null, ['admitted', 'cancelled'], true)) {
            throw new LogicException('Expected a payment admission receipt');
        }
        $codec = new Codec;
        // Before admission metadata was stored, root and child operations started at revision zero.
        $record = $body['wireReceipt'] ?? self::wire($body + ['revision' => $body['status'] === 'cancelled' ? 1 : 0, 'outcomeQueryRef' => $body['operationRef'] ?? null]);
        $wire = $codec->decode($codec->canonicalize($record));
        assert($wire instanceof PaymentAdmission);

        return $wire;
    }

    /**
     * @param  array<string, mixed>  $admission
     * @return array<string, mixed>
     */
    private static function wire(array $admission): array
    {
        $wire = ['schema' => PaymentAdmission::SCHEMA,
            'result' => $admission['status'] === 'cancelled' ? 'known-terminal' : 'accepted',
            'operationRef' => $admission['operationRef'], 'revision' => $admission['revision'],
            'outcomeQueryRef' => $admission['outcomeQueryRef'], 'receipt' => 'CommandAccepted'];
        $wire['digest'] = (new Codec)->digest($wire);

        return $wire;
    }
}

<?php

declare(strict_types=1);

namespace Billium\Core\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use InvalidArgumentException;

/**
 * Result of a root operation as stored with its idempotency claim: an accepted receipt `{class, body}` or a stored
 * business refusal `{problem}` (D31). `replayed` marks a duplicate of the same key and digest; `receiptId` is the
 * claim row of a keyed operation (the same for the original and every replay).
 */
#[Api]
final readonly class StoredOutcome
{
    /** @param array<string, mixed> $body */
    private function __construct(
        public bool $accepted,
        public ?string $receiptClass,
        public array $body,
        public ?ProblemCode $problem,
        public string $detail,
        public ?int $receiptId,
        public bool $replayed,
    ) {}

    public static function accepted(Receipt $receipt, ?int $receiptId = null): self
    {
        return new self(true, $receipt->receiptClass(), self::canonical($receipt->body()), null, '', $receiptId, false);
    }

    public static function refused(ProblemException $problem, ?int $receiptId = null): self
    {
        if ($problem->problem->receipt() !== 'stored') {
            throw new InvalidArgumentException("{$problem->problem->value} is not a stored refusal (D31)");
        }

        return new self(false, null, [], $problem->problem, $problem->detail, $receiptId, false);
    }

    /** @return array<string, mixed> the stored `result` document */
    public function result(): array
    {
        return $this->accepted
            ? ['class' => $this->receiptClass, 'body' => $this->body]
            : ['problem' => $this->problem?->value, 'detail' => $this->detail];
    }

    /** @param array<array-key, mixed> $result */
    public static function fromResult(string $outcome, array $result, int $receiptId, bool $replayed): self
    {
        if ($outcome === 'accepted' && is_string($result['class'] ?? null) && is_array($result['body'] ?? null)) {
            /** @var array<string, mixed> $body */
            $body = $result['body'];

            return new self(true, $result['class'], self::canonical($body), null, '', $receiptId, $replayed);
        }
        $problem = is_string($result['problem'] ?? null) ? ProblemCode::tryFrom($result['problem']) : null;
        if ($outcome === 'refused' && $problem !== null) {
            return new self(false, null, [], $problem, is_string($result['detail'] ?? null) ? $result['detail'] : '', $receiptId, $replayed);
        }

        throw new InvalidArgumentException("Stored outcome {$outcome} has an unreadable result");
    }

    /**
     * Object keys in byte order at every depth, so an original body and its jsonb replay are identical.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function canonical(array $body): array
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if ($value instanceof \stdClass) {
                $properties = get_object_vars($value);
                ksort($properties, SORT_STRING);
                $properties = array_map($sort, $properties);

                // Keep map access for ordinary receipt fields, but never turn a JSON object into a JSON list.
                return array_is_list($properties) ? (object) $properties : $properties;
            }
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($sort, $value);
        };
        // Freeze the JSON representation (including JsonSerializable values) before storage and replay.
        $document = json_decode(json_encode($body, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $sorted */
        $sorted = $sort($document instanceof \stdClass ? get_object_vars($document) : $document);

        return $sorted;
    }

    public function asReplay(): self
    {
        return new self($this->accepted, $this->receiptClass, $this->body, $this->problem, $this->detail, $this->receiptId, true);
    }

    /** Throws the stored refusal; returns the outcome otherwise. */
    public function orThrow(): self
    {
        if (! $this->accepted && $this->problem !== null) {
            throw new ProblemException($this->problem, $this->detail);
        }

        return $this;
    }
}

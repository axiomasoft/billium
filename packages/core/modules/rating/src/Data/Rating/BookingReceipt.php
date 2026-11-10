<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Rating;

use Billium\Contracts\Attributes\Api;
use Billium\Core\Data\Receipt;
use Billium\Rating\Enums\BookingOutcome;

#[Api]
final readonly class BookingReceipt implements Receipt
{
    public function __construct(
        public string $basisRef, public BookingOutcome $outcome, public ?string $obligationRef,
        public string $grossMinor, public string $currency,
    ) {}

    public function receiptClass(): string
    {
        return 'rating.booking';
    }

    public function body(): array
    {
        return ['basisRef' => $this->basisRef, 'outcome' => $this->outcome->value, 'obligationRef' => $this->obligationRef,
            'grossMinor' => $this->grossMinor, 'currency' => $this->currency];
    }

    /** @param array<string, mixed> $body */
    public static function fromBody(array $body): self
    {
        foreach (['basisRef', 'outcome', 'grossMinor', 'currency'] as $field) {
            if (! is_string($body[$field] ?? null)) {
                throw new \LogicException('Invalid stored rating receipt');
            }
        }
        $obligation = $body['obligationRef'] ?? null;
        if ($obligation !== null && ! is_string($obligation)) {
            throw new \LogicException('Invalid stored obligation reference');
        }

        return new self(basisRef: $body['basisRef'], outcome: BookingOutcome::from($body['outcome']),
            obligationRef: $obligation, grossMinor: $body['grossMinor'], currency: $body['currency']);
    }
}

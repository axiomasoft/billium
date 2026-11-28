<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Allocations;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\ReallocatePayment as ReallocatePaymentEnvelope;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;

/**
 * allocation.reallocate.v1: keyed policy reallocation of a payment that a confirmed refund over-allocated.
 * The billing account and currency are the payment's own; the command never moves value across accounts.
 */
#[Api(since: '1.0')]
final readonly class ReallocatePayment
{
    public function __construct(
        public string $commandKey,
        public string $installationRef,
        public string $sellerRef,
        public string $environment,
        public string $paymentRef,
        public string $policyVersion,
        public ReallocationMode $mode,
    ) {}

    /**
     * Strict wire decode through Codec::decode (schema, fields, digest).
     *
     * @throws ProblemException billium.validation.invalid or billium.schema.unsupported
     */
    public static function fromWire(string $json, Codec $codec = new Codec): self
    {
        $envelope = $codec->decode($json);
        if (! $envelope instanceof ReallocatePaymentEnvelope) {
            throw new ProblemException(ProblemCode::SchemaUnsupported, 'expected allocation.reallocate.v1');
        }

        return new self($envelope->commandKey, $envelope->installationRef, $envelope->sellerRef, $envelope->environment,
            $envelope->paymentRef, $envelope->policyVersion, ReallocationMode::from($envelope->mode));
    }
}

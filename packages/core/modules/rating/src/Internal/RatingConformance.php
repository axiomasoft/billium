<?php

declare(strict_types=1);

namespace Billium\Rating\Internal;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Data\Rating\QuoteRequest;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Internal\Storage\FrozenInputsCodec;
use Packman\Attributes\Internal;
use stdClass;

/** Testkit wire adapter for the pure quote lane; financial writes retain their typed root command. */
#[Internal]
final readonly class RatingConformance
{
    public function __construct(private RatingComputation $computation) {}

    /** @return array<string, mixed> */
    public function __invoke(string $operation, stdClass $arguments): array
    {
        if ($operation !== 'rating.rate' || ($arguments->action ?? null) !== 'quote' || ! is_string($arguments->frozen_inputs_json ?? null)) {
            throw new ProblemException(ProblemCode::PolicyUnknown, 'rating conformance requires an explicit retained-input quote');
        }
        $quote = $this->computation->quote(new QuoteRequest(FrozenInputsCodec::decode($arguments->frozen_inputs_json)));

        return ['financial' => $quote->financial, 'inputsDigest' => $quote->inputsDigest,
            'netMinor' => $quote->netMinor, 'taxMinor' => $quote->taxMinor,
            'grossMinor' => $quote->grossMinor, 'quoteDigest' => $quote->quoteDigest];
    }
}

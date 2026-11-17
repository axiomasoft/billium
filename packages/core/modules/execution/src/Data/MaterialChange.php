<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Platform\Data\EventContracts\RegisteredPublicEvent;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class MaterialChange
{
    /** @var list<PaymentObservation> */
    public array $observations;

    /** @var list<RegisteredPublicEvent> */
    public array $publicFacts;

    /**
     * @param  array<mixed>  $observations
     * @param  array<mixed>  $publicFacts
     */
    public function __construct(
        public OperationRecord $before,
        public OperationRecord $after,
        public MaterialSnapshot $observed,
        public CapacitySnapshot $capacity,
        public ?string $discrepancyRef,
        array $observations,
        array $publicFacts,
    ) {
        if ($discrepancyRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'discrepancyRef must be null or nonempty');
        }
        if (! array_is_list($observations)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'observations must be a list');
        }
        $observations = array_map(static function (mixed $item): PaymentObservation {
            if (! $item instanceof PaymentObservation) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'observations has an invalid element');
            }

            return $item;
        }, $observations);
        $this->observations = $observations;
        if (! array_is_list($publicFacts)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'publicFacts must be a list');
        }
        $publicFacts = array_map(static function (mixed $item): RegisteredPublicEvent {
            if (! $item instanceof RegisteredPublicEvent) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'publicFacts has an invalid element');
            }

            return $item;
        }, $publicFacts);
        $this->publicFacts = $publicFacts;
    }
}

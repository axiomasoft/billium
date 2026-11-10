<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Schedule\Contracts\OccurrenceService;
use Billium\Schedule\Data\Commands\ApplyScheduleRevision;
use Billium\Schedule\Data\Commands\EnsureOccurrence;
use Billium\Schedule\Data\Commands\MaterializeNext;
use Billium\Schedule\Data\ScheduleRevisionTerms;
use Billium\Schedule\Enums\OccurrencePurpose;
use Billium\Schedule\Internal\Errors\OccurrenceProblems;
use DateTimeImmutable;
use stdClass;

/** Module-owned test protocol dispatch; no second scheduler or monetary writer (D55/D56). */
final readonly class OccurrenceConformance
{
    public function __construct(private OccurrenceService $service) {}

    /** @return array<string, mixed> */
    public function __invoke(string $operation, stdClass $input): array
    {
        if (! isset($input->sellerRef,$input->environment,$input->scheduleRef,$input->commandKey)) {
            throw new ProblemException(ProblemCode::PolicyUnknown, 'schedule runtime requires explicit installed schedule input');
        }
        $seller = Payload::text($input->sellerRef);
        $environment = Payload::text($input->environment);
        $schedule = Payload::text($input->scheduleRef);
        $key = Payload::text($input->commandKey);
        $receipt = match ($operation) {
            'schedule.ensure' => $this->service->ensureOccurrence(new EnsureOccurrence(
                sellerRef: $seller, environment: $environment, componentRef: Payload::text($input->componentRef ?? null),
                purpose: $this->purpose($input->purpose ?? null),
                periodStart: new DateTimeImmutable(Payload::text($input->periodStart ?? null)),
                periodEnd: new DateTimeImmutable(Payload::text($input->periodEnd ?? null)), scheduleRef: $schedule,
                scheduleRevision: Payload::integer($input->scheduleRevision ?? null), inputsDigest: Payload::text($input->inputsDigest ?? null),
                parentOccurrenceRef: isset($input->parentOccurrenceRef) ? Payload::text($input->parentOccurrenceRef) : null,
                commandKey: $key, ordinal: isset($input->ordinal) ? Payload::integer($input->ordinal) : 0)),
            'schedule.generate' => $this->service->materializeNext(new MaterializeNext(
                sellerRef: $seller, environment: $environment, scheduleRef: $schedule,
                expectedRowVersion: Payload::integer($input->expectedRowVersion ?? null),
                expectedCancelGeneration: Payload::integer($input->expectedCancelGeneration ?? null),
                asOf: new DateTimeImmutable(Payload::text($input->asOf ?? null)), commandKey: $key)),
            'schedule.revise' => $this->service->applyScheduleRevision(new ApplyScheduleRevision(
                sellerRef: $seller, environment: $environment, scheduleRef: $schedule,
                expectedRevision: Payload::integer($input->expectedRevision ?? null),
                expectedRowVersion: Payload::integer($input->expectedRowVersion ?? null), newRevision: Payload::integer($input->newRevision ?? null),
                terms: ScheduleRevisionTerms::fromArray(Payload::map(json_decode(json_encode($input->terms ?? null, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR))),
                effectiveFrom: new DateTimeImmutable(Payload::text($input->effectiveFrom ?? null)),
                inputsDigest: Payload::text($input->inputsDigest ?? null), commandKey: $key)),
            default => throw new ProblemException(ProblemCode::PolicyUnknown, 'Unsupported schedule runtime operation'),
        };

        return $receipt->body();
    }

    private function purpose(mixed $value): OccurrencePurpose
    {
        $purpose = is_string($value) ? OccurrencePurpose::tryFrom($value) : null;
        if ($purpose === null) {
            OccurrenceProblems::refuse('occurrence.purpose_rejected');
        }

        return $purpose;
    }
}

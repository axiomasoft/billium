<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Schedule\Tests\OccurrenceTestCase;
use Billium\Schedule\Tests\Support\Terms;

final class OccurrenceIdentityTest extends OccurrenceTestCase
{
    public function test_module_owned_conformance_dispatch_uses_the_same_occurrence_writer(): void
    {
        $this->createSchedule();
        $handler = $this->application()->make('billium.conformance.schedule');
        $input = (object) ['sellerRef' => 'seller-a', 'environment' => 'test', 'scheduleRef' => 'schedule-a',
            'commandKey' => 'adapter-1', 'componentRef' => 'component-a', 'purpose' => 'recurring_charge',
            'periodStart' => '2024-01-31T00:00:00Z', 'periodEnd' => '2024-02-29T00:00:00Z',
            'scheduleRevision' => 1, 'inputsDigest' => 'sha256:'.str_repeat('a', 64)];
        $first = $handler('schedule.ensure', $input);
        $input->commandKey = 'adapter-2';
        $second = $handler('schedule.ensure', $input);
        self::assertSame($first['occurrenceRef'], $second['occurrenceRef']);
        self::assertFalse($second['created']);
        $input->purpose = 'entitlement_grant';
        $this->refusal(fn () => $handler('schedule.ensure', $input), 'occurrence.purpose_rejected');
    }

    public function test_the_same_operation_key_with_another_payload_conflicts(): void
    {
        $this->createSchedule();
        $this->occurrences()->ensureOccurrence($this->ensure());
        try {
            $this->occurrences()->ensureOccurrence($this->ensure(['inputsDigest' => 'changed']));
            self::fail('Changed command payload accepted');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
        }
        self::assertSame(1, $this->rows('billium_schedule_billing_occurrence'));
    }

    public function test_revision_and_new_operation_keys_do_not_create_another_charge(): void
    {
        $this->createSchedule();
        $first = $this->occurrences()->ensureOccurrence($this->ensure());
        $this->occurrences()->applyScheduleRevision($this->revision([
            'expectedRevision' => 1, 'expectedRowVersion' => 1, 'newRevision' => 2, 'commandKey' => 'revision-2',
            'terms' => Terms::make(['purpose' => 'recurring_charge', 'agreementRevision' => 2])]));
        $second = $this->occurrences()->ensureOccurrence($this->ensure(['scheduleRevision' => 2, 'commandKey' => 'new-key']));
        self::assertSame($first->body()['occurrenceRef'], $second->body()['occurrenceRef']);
        self::assertFalse($second->body()['created']);
        self::assertSame(1, $this->rows('billium_schedule_billing_occurrence'));
        self::assertSame(1, $this->rows('billium_schedule_occurrence_outbox', "event_kind='schedule.occurrence.ensured.v1'"));
        self::assertSame($first->body(), $this->occurrences()->ensureOccurrence($this->ensure())->body());
        $this->refusal(fn () => $this->occurrences()->ensureOccurrence($this->ensure(['inputsDigest' => 'changed', 'commandKey' => 'changed-key'])), 'occurrence.inputs_changed');
    }

    public function test_changed_inputs_under_another_key_preserve_the_original_and_store_a_refusal(): void
    {
        $this->createSchedule();
        $this->occurrences()->ensureOccurrence($this->ensure());
        $this->refusal(fn () => $this->occurrences()->ensureOccurrence($this->ensure(['inputsDigest' => 'changed', 'commandKey' => 'changed-key'])), 'occurrence.inputs_changed');
        self::assertSame(1, $this->rows('billium_schedule_billing_occurrence'));
        self::assertSame('refused', $this->value("SELECT outcome FROM billium_idempotency_receipt WHERE command_key='changed-key'"));
    }
}

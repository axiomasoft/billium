<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\EnsureBillingOccurrence;
use Billium\Contracts\Data\Envelopes\FindReceipt;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Platform\Contracts\Idempotency\IdempotencyReceiptQueries;
use Billium\Platform\Contracts\Idempotency\ReceiptAbsenceAuthority;
use Billium\Schedule\Contracts\PinnedOccurrenceInputs;
use Billium\Schedule\Internal\OccurrenceConformance;
use Billium\Schedule\Tests\OccurrenceTestCase;
use Billium\Schedule\Tests\Support\Terms;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class OccurrenceIdentityTest extends OccurrenceTestCase
{
    public function test_wire_receipt_and_original_input_survive_resolver_loss(): void
    {
        $this->createSchedule();
        $codec = new Codec;
        $base = ['installationRef' => self::INSTALLATION, 'sellerRef' => 'seller-a', 'environment' => 'test'];
        $raw = ['schema' => EnsureBillingOccurrence::SCHEMA, 'commandKey' => '98e68552-8fdb-4edb-8434-3b3bad0b56db', ...$base,
            'componentRef' => 'component-a', 'purpose' => 'recurring_charge', 'scheduleRevision' => 1,
            'interval' => (object) ['start' => '2024-01-31T00:00:00Z', 'end' => '2024-02-29T00:00:00Z']];
        $command = $codec->decode($codec->canonicalize($raw));
        self::assertInstanceOf(EnsureBillingOccurrence::class, $command);
        $resolver = $this->createMock(PinnedOccurrenceInputs::class);
        $resolver->expects(self::once())->method('resolve')->willReturn($this->ensure());
        $receipt = $this->occurrences()->ensureBillingOccurrence($command, $resolver);
        self::assertSame($codec->encode($receipt), $codec->encode($this->occurrences()->ensureBillingOccurrence($command, $resolver)));

        $changed = $codec->decode($codec->canonicalize(array_replace($raw, ['scheduleRevision' => 2])));
        self::assertInstanceOf(EnsureBillingOccurrence::class, $changed);
        try {
            $this->occurrences()->ensureBillingOccurrence($changed, $resolver);
            self::fail('A changed original command replayed');
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::IdempotencyConflict, $problem->problem);
        }
        self::assertSame(1, $this->rows('billium_schedule_billing_occurrence'));

        $queries = $this->application()->make(IdempotencyReceiptQueries::class);
        $scope = new FinancialScope(self::INSTALLATION, 'seller-a', 'test', null);
        $lookup = $codec->decode($codec->canonicalize(['schema' => FindReceipt::SCHEMA, ...$base, 'receiptRef' => $receipt->receiptRef]));
        self::assertInstanceOf(FindReceipt::class, $lookup);
        $found = $queries->find($scope, $lookup);
        self::assertSame('found', $found->status);
        self::assertSame($codec->encode($receipt), $codec->canonicalize($found->body));

        $missing = $codec->decode($codec->canonicalize(['schema' => FindReceipt::SCHEMA, ...$base, 'receiptRef' => 'missing-receipt']));
        self::assertInstanceOf(FindReceipt::class, $missing);
        try {
            $queries->find($scope, $missing);
            self::fail('A database miss certified absence');
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::LookupUnavailable, $problem->problem);
        }
        $proof = $this->createMock(ReceiptAbsenceAuthority::class);
        $proof->expects(self::once())->method('confirmAbsent')->with(self::isInstanceOf(Caller::class), $scope, 'missing-receipt')
            ->willReturn((object) ['proofRef' => 'history-proof']);
        $absent = $queries->find($scope, $missing, $proof);
        self::assertSame('absent', $absent->status);
        self::assertEquals((object) ['proofRef' => 'history-proof'], $absent->absenceCertificate);
    }

    /** @return iterable<string, array{string, string}> */
    public static function shiftedIntervals(): iterable
    {
        yield 'start differs from the pinned period' => ['2024-01-30T00:00:00Z', '2024-02-29T00:00:00Z'];
        yield 'end differs from the pinned period' => ['2024-01-31T00:00:00Z', '2024-03-01T00:00:00Z'];
    }

    #[DataProvider('shiftedIntervals')]
    public function test_a_wire_interval_that_differs_from_the_pinned_period_is_refused(string $start, string $end): void
    {
        $this->createSchedule();
        $codec = new Codec;
        $command = $codec->decode($codec->canonicalize(['schema' => EnsureBillingOccurrence::SCHEMA, 'commandKey' => '5a0f7c38-1d0e-4e43-9a51-0d6f3a2b7c11',
            'installationRef' => self::INSTALLATION, 'sellerRef' => 'seller-a', 'environment' => 'test', 'componentRef' => 'component-a',
            'purpose' => 'recurring_charge', 'scheduleRevision' => 1, 'interval' => (object) ['start' => $start, 'end' => $end]]));
        self::assertInstanceOf(EnsureBillingOccurrence::class, $command);
        $resolver = $this->createMock(PinnedOccurrenceInputs::class);
        $resolver->method('resolve')->willReturn($this->ensure());
        try {
            $this->occurrences()->ensureBillingOccurrence($command, $resolver);
            self::fail('A wire interval outside the pinned period was accepted');
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::AuthDenied, $problem->problem);
            self::assertSame('', $problem->detail);
        }
        self::assertSame(0, $this->rows('billium_schedule_billing_occurrence'));
    }

    public function test_module_owned_conformance_dispatch_uses_the_same_occurrence_writer(): void
    {
        $this->createSchedule();
        $handler = $this->application()->make('billium.conformance.schedule');
        self::assertInstanceOf(OccurrenceConformance::class, $handler);
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

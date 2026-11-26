<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Feature;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Exceptions\Modules\ModuleAdmissionRefused;
use Billium\Core\Tests\Support\UsesFreshProfileDatabase;
use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Enums\BookingOutcome;
use Billium\Rating\Tests\RatingTestCase;
use Billium\Usage\Tests\Support\UsageFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class RatingPipelineNoInvoiceTest extends RatingTestCase
{
    use UsesFreshProfileDatabase;

    /** @return list<array{string}> */
    public static function lostEvidence(): array
    {
        return [['erased'], ['retention_expired']];
    }

    #[DataProvider('lostEvidence')]
    public function test_erased_or_expired_aggregate_refuses_first_ledger_posting_of_unposted_basis(string $event): void
    {
        $prepared = $this->prepared();
        $this->owner()->table('billium_module_state')->where('module_id', 'ledger')->update([
            'state' => 'draining', 'generation' => 2, 'last_transition_ref' => 'disable-for-freeze']);
        self::assertSame(BookingOutcome::Unposted, $this->book($prepared)->outcome);
        $id = $this->value('SELECT id FROM billium_usage_aggregate_snapshot WHERE snapshot_ref=?', [$this->snapshotRef]);
        $this->owner()->table('billium_usage_aggregate_reproducibility_event')->insert([
            'snapshot_id' => $id, 'reproducibility' => $event]);
        $this->owner()->table('billium_module_state')->where('module_id', 'ledger')->update([
            'state' => 'accepting', 'generation' => 3, 'last_transition_ref' => 'enable-for-post']);
        try {
            $this->book(prepared: $prepared, key: 'post-lost-evidence');
            self::fail('Unreproducible retained evidence was posted');
        } catch (ProblemException $error) {
            self::assertSame('billium.usage.not_reproducible', $error->detail);
        }
        self::assertSame(1, $this->rows('billium_rating_rated_basis'));
        self::assertSame(0, $this->rows('billium_obligation'));
        self::assertSame(0, $this->rows('billium_rating_booking'));
    }

    public function test_pinned_price_can_be_published_for_unposted_rating_with_ledger_disabled(): void
    {
        $this->owner()->table('billium_module_state')->where('module_id', 'ledger')->update([
            'state' => 'draining', 'generation' => 2, 'last_transition_ref' => 'publication-disable']);
        config(['billium.modules.enabled' => ['platform', 'rating', 'usage', 'schedule']]);
        $this->application()->forgetInstance(BilliumOptions::class);
        $this->application()->make(PriceVersionStore::class)->publish(
            price: $this->price(version: 2, fixed: '140'), commandKey: 'price-while-ledger-disabled');
        self::assertSame(BookingOutcome::Unposted, $this->book($this->prepared(priceVersion: 2))->outcome);
        self::assertSame(0, $this->rows('billium_obligation'));
    }

    public function test_superseded_aggregate_refuses_first_ledger_posting_of_unposted_basis(): void
    {
        $prepared = $this->prepared();
        $this->owner()->table('billium_module_state')->where('module_id', 'ledger')->update([
            'state' => 'draining', 'generation' => 2, 'last_transition_ref' => 'disable-for-freeze']);
        self::assertSame(BookingOutcome::Unposted, $this->book($prepared)->outcome);
        $this->usage()->submitUsageFact(UsageFixtures::fact(static function ($fact): void {
            $fact->factId = 'late-fact';
            $fact->value->quantity = '50';
        }));
        $this->freeze($prepared->inputs->aggregates[0]->snapshotDigest);
        $this->owner()->table('billium_module_state')->where('module_id', 'ledger')->update([
            'state' => 'accepting', 'generation' => 3, 'last_transition_ref' => 'enable-for-post']);
        try {
            $this->book(prepared: $prepared, key: 'post-superseded-evidence');
            self::fail('Superseded retained evidence was posted');
        } catch (ProblemException $error) {
            self::assertSame('billium.usage.snapshot_superseded', $error->detail);
        }
        self::assertSame(0, $this->rows('billium_obligation'));
        self::assertSame(0, $this->rows('billium_rating_booking'));
    }

    public function test_lifecycle_disable_alone_preserves_an_unposted_basis_without_admitting_ledger(): void
    {
        $prepared = $this->prepared();
        $this->owner()->table('billium_module_state')->where('module_id', 'ledger')->update([
            'state' => 'draining', 'generation' => 2, 'last_transition_ref' => 'lifecycle-disable']);
        self::assertSame(BookingOutcome::Unposted, $this->book($prepared)->outcome);
        self::assertSame(1, $this->rows('billium_idempotency_receipt', "command_kind='rating.book.v1'"));
        self::assertSame(0, $this->rows('billium_obligation'));
        $this->owner()->table('billium_module_state')->where('module_id', 'ledger')->update([
            'state' => 'accepting', 'generation' => 3, 'last_transition_ref' => 'lifecycle-enable']);
        self::assertSame(BookingOutcome::Booked, $this->book(prepared: $prepared, key: 'enabled-again')->outcome);
        self::assertSame(1, $this->rows('billium_obligation'));
    }

    public function test_a_required_module_refusal_cannot_fall_back_to_unposted(): void
    {
        $prepared = $this->prepared();
        $this->owner()->table('billium_module_state')->where('module_id', 'rating')->update([
            'state' => 'draining', 'generation' => 2, 'last_transition_ref' => 'rating-disable']);
        try {
            $this->book($prepared);
            self::fail('required module admission was bypassed');
        } catch (ModuleAdmissionRefused $error) {
            self::assertSame('rating', $error->moduleId);
        }
        self::assertSame(0, array_sum($this->ratingCounts()));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind='rating.book.v1'"));
    }

    public function test_booking_has_no_legal_render_payment_or_execution_prerequisite(): void
    {
        self::assertSame(BookingOutcome::Booked, $this->book($this->prepared())->outcome);
        foreach (['billium_invoice', 'billium_payment'] as $table) {
            self::assertSame(0, $this->rows($table));
        }
        self::assertNull($this->value("SELECT to_regclass('public.billium_execution_operation')::text"));
    }

    public function test_disabled_ledger_freezes_only_then_posts_the_same_basis_once(): void
    {
        $prepared = $this->prepared();
        config(['billium.modules.enabled' => ['platform', 'rating', 'usage', 'schedule']]);
        $this->application()->forgetInstance(BilliumOptions::class);
        // The disabled module is genuinely inadmissible, even if another root tried to include it.
        $this->owner()->table('billium_module_state')->where('module_id', 'ledger')->update(['state' => 'draining', 'generation' => 2, 'last_transition_ref' => 'test-disable']);
        $first = $this->book($prepared);
        self::assertSame(BookingOutcome::Unposted, $first->outcome);
        self::assertSame(0, $this->rows('billium_obligation'));
        self::assertSame('rated', $this->value('SELECT state FROM billium_schedule_billing_occurrence'));
        $this->tax->missing = true;
        self::assertSame($prepared->draft->ratedDigest, $this->prepared()->draft->ratedDigest);
        self::assertSame(1, $this->tax->calls);
        config(['billium.modules.enabled' => ['platform', 'ledger', 'rating', 'usage', 'schedule']]);
        $this->application()->forgetInstance(BilliumOptions::class);
        $this->owner()->table('billium_module_state')->where('module_id', 'ledger')->update(['state' => 'accepting', 'generation' => 3, 'last_transition_ref' => 'test-enable']);
        $posted = $this->book(prepared: $prepared, key: 'post-after-enable');
        self::assertSame(BookingOutcome::Booked, $posted->outcome);
        self::assertSame($first->basisRef, $posted->basisRef);
        self::assertSame(1, $this->rows('billium_rating_run'));
        self::assertSame(1, $this->rows('billium_obligation'));
        self::assertSame('obligated', $this->value('SELECT state FROM billium_schedule_billing_occurrence'));
    }
}

<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\EventEnvelopeV2;
use Billium\Contracts\Data\Envelopes\FeedPage as WireFeedPage;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Modules\PublicEventDeclaration;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Contracts\Outbox\Outbox;
use Billium\Platform\Data\EventContracts\RegisteredPublicEvent;
use Billium\Platform\Data\Feed\FeedSelection;
use Billium\Platform\Data\Outbox\OutboxEvent;
use Billium\Platform\Internal\Outbox\EventFeedReader;
use Billium\Platform\Tests\Outbox\OutboxTestCase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;

#[Group('EVT-01')]
#[Group('EVT-02')]
#[Group('EVT-06')]
#[Group('pg')]
final class FilteredFeedCompatibilityTest extends OutboxTestCase
{
    public function test_original_v1_selection_does_not_grow_after_v2_registration(): void
    {
        $selection = $this->selection(PublicEventDeclaration::STRICT_V1_TYPES);
        $digest = $selection->selectionDigest;
        $this->registerExecution();
        $this->events(1);
        $page = $this->feed()->read($selection);
        self::assertSame($digest, $page->selectionDigest);
        self::assertCount(7, $selection->eventTypes);
        self::assertCount(1, $page->events);
        self::assertSame('billium.event.v1', $page->events[0]['schema']);
    }

    public function test_unselected_empty_pages_advance_over_every_scanned_physical_row(): void
    {
        $selection = $this->selection();
        $this->events(1, 'invoice.issued.v1');
        $this->events(1);
        $this->events(1, 'invoice.issued.v1');
        $first = $this->feed()->read($selection, limit: 1);
        self::assertSame([], $first->events);
        self::assertSame('1', $first->nextCursor->position->id);
        self::assertTrue($first->hasMore);
        $second = $this->feed()->read($selection, $first->nextCursor->token, 1);
        self::assertCount(1, $second->events);
        $third = $this->feed()->read($selection, $second->nextCursor->token, 1);
        self::assertSame([], $third->events);
        self::assertSame('3', $third->nextCursor->position->id);
        self::assertFalse($third->hasMore);
    }

    public function test_changed_type_set_catalog_subscription_or_signature_cannot_reuse_a_cursor(): void
    {
        $old = $this->selection();
        $new = $this->selection(['invoice.issued.v1']);
        try {
            $this->feed()->read($new, $old->cursor->token);
            self::fail('new subscription must replay or bootstrap');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
        }
        $changed = new FeedSelection($old->subscriptionRef, $old->scope, 'events-2026-10-v2', $old->envelopeSchemas, $old->eventTypes, $old->selectionDigest, $old->cursor);
        try {
            $this->feed()->read($changed);
            self::fail('mutating a selection must fail');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
        }
        $this->expectException(ProblemException::class);
        $this->feed()->read($old, $old->cursor->token.'tampered');
    }

    public function test_cross_seller_scope_is_denied_before_disclosing_events(): void
    {
        $old = $this->selection();
        $this->callers->as('seller-b');
        $this->expectException(ProblemException::class);
        $this->feed()->read($old);
    }

    public function test_lower_xid_commit_is_not_skipped_even_when_its_identity_is_higher(): void
    {
        $selection = $this->selection();
        $late = TestEnvironment::pdo(owner: true);
        $late->beginTransaction();
        try {
            $xid = self::text(self::pdoValue($late, 'SELECT pg_current_xact_id()::text'));
            $reader = $this->feed();
            self::assertInstanceOf(EventFeedReader::class, $reader);
            $this->events(1); // Higher xid, earlier allocated identity, already committed.
            self::assertSame([], $this->feed()->read($selection)->events, 'xmin must hold back even the committed higher xid');
            self::assertSame($xid, $reader->diagnostics($selection)['safeHorizonTxId']);
            self::assertSame(0, $reader->diagnostics($selection)['safePendingRows']);
            $statement = $late->prepare("INSERT INTO billium_outbox_event(event_id,seller_ref,environment,type,schema_version,aggregate_ref,aggregate_revision,occurred_at,digest,payload,source_scope) VALUES (?,'seller-a','test','obligation.created.v1','billium.event.v1','late:1',1,clock_timestamp(),?,'{}',?)");
            $statement->execute([(string) Str::ulid(), 'sha256:'.hash('sha256', '{}'), $selection->scope->key()]);
            $late->commit();
        } finally {
            if ($late->inTransaction()) {
                $late->rollBack();
            }
        }
        $first = $this->feed()->read($selection, limit: 1);
        self::assertSame('2', $first->nextCursor->position->id);
        self::assertSame('late:1', $first->events[0]['aggregateRef']);
        $second = $this->feed()->read($selection, $first->nextCursor->token, 1);
        self::assertSame('1', $second->nextCursor->position->id);
        self::assertCount(1, $second->events);
    }

    public function test_v2_fact_is_negotiated_and_same_revision_with_changed_payload_conflicts(): void
    {
        $old = $this->selection(PublicEventDeclaration::STRICT_V1_TYPES);
        $contract = $this->registerExecution();
        $this->owner()->insert("INSERT INTO billium_module_state(installation_ref,module_id,state,config_revision,schema_version,generation,last_transition_ref) VALUES ('inst-test','execution','accepting',1,0,1,'fixture:execution')");
        $payload = self::effectPayload();
        $event = new RegisteredPublicEvent($contract, new OutboxEvent((string) Str::ulid(), 'payment.effect.outcome.v1', 'billium.event.v2', 'capture:1', 1, new \DateTimeImmutable('2026-10-28T10:00:00Z'), $payload));
        $body = function (TransactionContext $context) use ($event): Receipt {
            $id = $this->application()->make(Outbox::class)->record($context, $event);

            return self::receipt(['id' => $id]);
        };
        $this->runner()->run($this->spec(modules: ['execution', 'platform']), $body);
        $this->runner()->run($this->spec(modules: ['execution', 'platform']), $body);
        self::assertSame(1, $this->rows('billium_outbox_event'));
        self::assertSame([], $this->feed()->read($old)->events);
        $new = $this->feed()->openFeed($old->scope, 'events-2026-10-v2', ['billium.event.v2'], ['payment.effect.outcome.v1']);
        self::assertCount(1, $this->feed()->read($new)->events);
        $changed = new RegisteredPublicEvent($contract, new OutboxEvent($event->event->eventId, $event->event->type, $event->event->schemaVersion, 'capture:1', 1, $event->event->occurredAt, [...$payload, 'effectTotal' => '13.00']));
        try {
            $this->runner()->run($this->spec(modules: ['execution', 'platform']), function (TransactionContext $context) use ($changed): Receipt {
                $this->application()->make(Outbox::class)->record($context, $changed);

                return self::receipt();
            });
            self::fail('An economic revision must not change its content');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::IdempotencyConflict, $error->problem);
        }
    }

    public function test_registered_event_is_wire_valid_before_content_or_deliveries_commit(): void
    {
        $contract = $this->registerExecution();
        $this->owner()->insert("INSERT INTO billium_module_state(installation_ref,module_id,state,config_revision,schema_version,generation,last_transition_ref) VALUES ('inst-test','execution','accepting',1,0,1,'fixture:execution')");
        $payload = self::effectPayload();
        $at = new \DateTimeImmutable('2026-10-28T10:00:00Z');
        $invalid = new RegisteredPublicEvent($contract, new OutboxEvent('evt-1', 'payment.effect.outcome.v1', 'billium.event.v2', 'capture:1', 1, $at, $payload));
        try {
            $this->runner()->run($this->spec(modules: ['execution', 'platform']), function (TransactionContext $context) use ($invalid): Receipt {
                $this->application()->make(Outbox::class)->record($context, $invalid);

                return self::receipt();
            });
            self::fail('An immutable event with a non-ULID identity must not commit');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
        }
        self::assertSame(0, $this->rows('billium_outbox_event'));
        self::assertSame(0, $this->rows('billium_outbox_delivery'));

        $id = (string) Str::ulid();
        $valid = new RegisteredPublicEvent($contract, new OutboxEvent($id, 'payment.effect.outcome.v1', 'billium.event.v2', 'capture:1', 1, $at, $payload));
        $this->runner()->run($this->spec(modules: ['execution', 'platform']), function (TransactionContext $context) use ($valid): Receipt {
            $this->application()->make(Outbox::class)->record($context, $valid);

            return self::receipt();
        });
        $scope = $this->selection()->scope;
        $selection = $this->feed()->openFeed($scope, 'events-2026-10-v2', ['billium.event.v2'], ['payment.effect.outcome.v1']);
        $page = $this->feed()->read($selection);
        self::assertCount(1, $page->events);
        self::assertSame($id, $page->events[0]['eventId']);
        $codec = new Codec;
        $eventJson = $codec->canonicalize($page->events[0]);
        self::assertInstanceOf(EventEnvelopeV2::class, $codec->decode($eventJson));
        $pageJson = $codec->canonicalize($page->toArray());
        $decoded = $codec->decode($pageJson);
        self::assertInstanceOf(WireFeedPage::class, $decoded);
        self::assertSame($pageJson, $codec->encode($decoded));
        self::assertSame(1, $this->rows('billium_outbox_delivery'));
    }
}

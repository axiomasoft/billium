<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\ExecutionCertifiedNoEffectEvent;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\EventPublisher;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Support\CertifiesAbsentRefund;
use Billium\Platform\Contracts\Outbox\Outbox;
use Billium\Platform\Data\EventContracts\RegisteredPublicEvent;
use Billium\Platform\Data\Outbox\OutboxEvent;
use Billium\Platform\Internal\EventContracts\DatabaseEventContractRegistrar;
use Billium\Platform\Internal\Outbox\OutboxRelay;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessIdentity;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
final class CertifiedNoEffectHandoffTest extends ExecutionTestCase
{
    use CertifiesAbsentRefund;

    public static function register(Application $app): void
    {
        $modules = $app->make(ModuleRegistry::class);
        $descriptor = $modules->descriptor('execution');
        self::assertNotNull($descriptor);
        $registrar = new DatabaseEventContractRegistrar($app->make(DatabaseManager::class)->connection('billium_owner'), $modules);
        $registrar->register($registrar->plan($descriptor, true));
    }

    public function test_refund_absence_publishes_only_its_certificate_backed_terminal_revision_rolls_back_and_retries(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            Refunds::captured($app, $path);
            $app->make(RefundCommands::class)->admit(Fixture::scope(), Refunds::refund('old', 70, 2))->orThrow();
            $outbox = $app->make(Outbox::class);
            $app->instance(Outbox::class, new readonly class($outbox) implements Outbox
            {
                public function __construct(private Outbox $outbox) {}

                public function record(TransactionContext $context, OutboxEvent|RegisteredPublicEvent $event): int
                {
                    $this->outbox->record($context, $event);
                    throw new RuntimeException('rollback_after_certified_disposition');
                }
            });
            try {
                $this->certifyAbsentAndAdoptNewSession($path);
                self::fail('Certified refund release committed without its terminal event');
            } catch (RuntimeException $error) {
                self::assertSame('rollback_after_certified_disposition', $error->getMessage());
            }
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND confirmed_refund_minor=0 AND held_refund_minor=70'));
            self::assertSame(0, $this->rows('billium_execution_certified_absence'));
            self::assertSame(0, $this->rows('billium_execution_operation_revision', "operation_ref='old'"));
            self::assertSame(0, $this->rows('billium_outbox_event', "type='payment.certified_no_effect.v1'"));
            $work = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint='old' AND state='due' AND last_error_code='financial_processing_deferred'"));
            $app->instance(Outbox::class, $outbox);
            $this->owner()->update('UPDATE billium_execution_provider_work SET due_at=clock_timestamp() WHERE work_ref=?', [$work]);
            $reducer = $app->make(EvidenceReducer::class);
            $result = $reducer->reduce($work);
            self::assertSame(ReductionDisposition::Applied, $result->disposition);
            self::assertCount(1, $result->publicEventIds);
            self::assertSame([], $result->observationCommandRefs);
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND confirmed_refund_minor=0 AND held_refund_minor=0'));
            self::assertSame(1, $this->rows('billium_execution_operation', "operation_ref='old' AND revision=1 AND state='failed'"));
            self::assertSame(0, $this->rows('billium_execution_provider_fact', "operation_ref='old'"));
            self::assertSame(1, $this->rows('billium_execution_observation_emission', "operation_ref='root-a' AND source_sequence=1"));
            self::assertSame(1, $this->rows('billium_outbox_command'));
            self::assertSame([], $reducer->reduce($work)->publicEventIds);
            $proof = $this->row('SELECT certificate_ref,certificate_digest FROM billium_execution_certified_absence');
            $event = $result->publicEventIds[0];
            $receiver = new readonly class($path.'.receiver', $event, self::text($proof['certificate_ref']), self::text($proof['certificate_digest'])) implements EventPublisher
            {
                public function __construct(private string $journal, private string $event, private string $certificate, private string $digest) {}

                public function publish(string $destinationRef, array $message): void
                {
                    $codec = new Codec;
                    $wire = $codec->decode($codec->canonicalize($message));
                    Assert::assertInstanceOf(ExecutionCertifiedNoEffectEvent::class, $wire);
                    Assert::assertSame(['default', 'events-2026-11-v4', $this->event, 'inst-test', 'test', 'seller-a', 'old', 1],
                        [$destinationRef, $wire->eventContractRevision, $wire->eventId, $wire->installationRef, $wire->environment, $wire->sellerRef, $wire->aggregateRef, $wire->revision]);
                    Assert::assertSame(['old', 'root-a', 'refund', 1, 'failed-terminal', $this->certificate, $this->digest],
                        [$wire->payload->operationRef, $wire->payload->rootOperationRef, $wire->payload->operationKind, $wire->payload->revision, $wire->payload->status,
                            $wire->payload->certificateRef, $wire->payload->certificateDigest]);
                    Assert::assertEquals((object) ['amount' => '0.70', 'currency' => 'RUB'], $wire->payload->requested);
                    Assert::assertFalse(property_exists($wire->payload, 'capturedTotal'));
                    Assert::assertFalse(property_exists($wire->payload, 'refundedTotal'));
                    $journal = new DurableJournal($this->journal);
                    $prior = $journal->records()[0] ?? null;
                    if ($prior === null) {
                        $journal->append(new JournalEvent('terminal', ['id' => $wire->eventId, 'digest' => $wire->digest], ProcessIdentity::current()));
                        throw new RuntimeException('simulated_ack_loss_after_recipient_commit');
                    }
                    Assert::assertInstanceOf(JournalEvent::class, $prior);
                    Assert::assertSame(['id' => $wire->eventId, 'digest' => $wire->digest], $prior->data);
                }
            };
            $relay = new OutboxRelay($app->make(DatabaseManager::class)->connection(), $receiver);
            self::assertSame(1, $relay->relay()->failed);
            self::assertCount(1, new DurableJournal($path.'.receiver')->records());
            $this->owner()->update('UPDATE billium_outbox_delivery SET available_at=clock_timestamp() WHERE event_id=?', [$event]);
            self::assertSame(1, $relay->relay()->delivered);
            self::assertCount(1, new DurableJournal($path.'.receiver')->records());
            self::assertSame(1, $this->rows('billium_outbox_delivery', "event_id=? AND status='delivered' AND attempts=2", [$event]));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.lookup'));
        } finally {
            UnknownResultTest::clean($path);
            if (is_file($path.'.receiver')) {
                unlink($path.'.receiver');
            }
        }
    }
}

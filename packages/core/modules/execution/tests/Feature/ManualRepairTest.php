<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Execution\Contracts\ExecutionReconciliationQueries;
use Billium\Execution\Contracts\RepairCommands;
use Billium\Execution\Data\RecoveryAction;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Platform\Contracts\Outbox\Outbox;
use Billium\Platform\Data\EventContracts\RegisteredPublicEvent;
use Billium\Platform\Data\Outbox\OutboxEvent;
use Billium\Platform\Internal\EventContracts\DatabaseEventContractRegistrar;
use Billium\Platform\Internal\Outbox\EventRows;
use Billium\Testkit\Fixtures\ChildProcess;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use stdClass;

#[Group('pg')]
#[Group('PF-11')]
final class ManualRepairTest extends ExecutionTestCase
{
    /** @param list<string> $evidence */
    private function action(string $kind, string $key, string $review, array $evidence, string $reason = 'Statement and case reviewed', string $root = 'root-a'): RecoveryAction
    {
        $scope = Fixture::scope();
        $actor = $this->callers->current()->actorRef;
        $digest = PaymentIdentity::digest(['schema' => 'billium.execution.repair.v1', 'scope' => get_object_vars($scope), 'source' => 'remote-billium',
            'root' => $root, 'review' => $review, 'action' => $kind, 'actor' => $actor, 'reason' => $reason, 'evidence' => $evidence]);

        return new RecoveryAction($key, $scope, $kind, $evidence, $actor, $reason, $digest, $root);
    }

    public static function restartReview(string $path): void
    {
        $app = Fixture::workerApp(915);
        InventoryReconciliationTest::provisionCursorKey($app);
        $caller = $app->make(CallerContext::class);
        self::assertInstanceOf(FixedCallerContext::class, $caller);
        InventoryReconciliationTest::grant($caller);
        $queries = $app->make(ExecutionReconciliationQueries::class);
        $asOf = new DateTimeImmutable;
        $resolved = $queries->openDiscrepancies(Fixture::scope(), 'remote-billium', 'resolved', $asOf);
        $due = $queries->dueReview(Fixture::scope(), 'remote-billium', $asOf);
        $backend = $app->make(DatabaseManager::class)->connection()->scalar('SELECT pg_backend_pid()');
        file_put_contents($path.'.restart', json_encode([getmypid(), $backend, $resolved->rows[0]->provenance, $resolved->rows[0]->receiptRef,
            $resolved->rows[0]->reviewRef, count($due->rows)], JSON_THROW_ON_ERROR));
    }

    public function test_adopting_reported_external_effect_is_privileged_keyed_and_atomic_with_original_history_and_outbox(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            Refunds::captured($app, $path);
            InventoryReconciliationTest::grant($this->callers);
            InventoryReconciliationTest::enableInventory($app, $path);
            InventoryReconciliationTest::page($path, [DisputeSettlementTest::line('external_manual', 45, 'external-correction-7')]);
            InventoryReconciliationTest::scan($app, 'audit-run', new DateTimeImmutable('-1 hour'), new DateTimeImmutable);
            $event = self::text($this->value('SELECT event_ref FROM billium_execution_recon_review'));
            $modules = $app->make(ModuleRegistry::class);
            $registration = new DatabaseEventContractRegistrar($app->make(DatabaseManager::class)->connection('billium_owner'), $modules);
            $descriptor = $modules->descriptor('execution');
            self::assertNotNull($descriptor);
            $registration->register($registration->plan($descriptor, true));
            $api = $app->make(RepairCommands::class);
            $action = $this->action('adopt_external_effect', 'operator-key', $event, ['external-correction-7']);
            InventoryReconciliationTest::grant($this->callers, ['execution.reconcile']);
            HistoricalMerchantRefundTest::problem(ProblemCode::AuthDenied, static fn () => $api->repair($action, $event));
            InventoryReconciliationTest::grant($this->callers);
            foreach (['record_verified_absence', 'mark_verified_duplicate', 'approve_linked_correction'] as $unsupported) {
                $unproven = $this->action($unsupported, 'other-'.$unsupported, $event, ['external-correction-7', 'unverified-assertion']);
                HistoricalMerchantRefundTest::problem(ProblemCode::RecoveryRequired, static fn () => $api->repair($unproven, $event));
            }
            self::assertSame(0, $this->rows('billium_execution_repair_receipt'));
            $actualOutbox = $app->make(Outbox::class);
            $app->instance(Outbox::class, new readonly class($actualOutbox) implements Outbox
            {
                public function __construct(private Outbox $delegate) {}

                public function record(TransactionContext $context, OutboxEvent|RegisteredPublicEvent $event): int
                {
                    $this->delegate->record($context, $event);
                    throw new RuntimeException('Injected failure after durable outbox insert');
                }
            });
            try {
                $app->make(RepairCommands::class)->repair($action, $event)->orThrow();
                self::fail('Injected failure committed a partial repair');
            } catch (RuntimeException $failure) {
                self::assertSame('Injected failure after durable outbox insert', $failure->getMessage());
            } finally {
                $app->instance(Outbox::class, $actualOutbox);
            }
            self::assertSame(0, $this->rows('billium_execution_repair_receipt'));
            self::assertSame(1, $this->rows('billium_execution_recon_review'));
            self::assertSame(0, $this->rows('billium_outbox_event', "type='execution.repair_recorded.v1'"));
            $receipt = $api->repair($action, $event)->orThrow();
            $replay = $api->repair($action, $event)->orThrow();
            self::assertTrue($replay->replayed);
            self::assertSame($receipt->receiptId, $replay->receiptId);
            self::assertSame($receipt->body, $replay->body);
            $changed = $this->action('adopt_external_effect', 'operator-key', $event, ['external-correction-7'], 'Another resolution');
            HistoricalMerchantRefundTest::problem(ProblemCode::IdempotencyConflict, static fn () => $api->repair($changed, $event));
            self::assertSame(1, $this->rows('billium_execution_repair_receipt', 'before_digest<>after_digest AND repair_ref=?', [$receipt->body['receiptRef']]));
            self::assertSame(1, $this->rows('billium_execution_recon_review', "status='open' AND event_ref=?", [$event]));
            self::assertSame(1, $this->rows('billium_execution_recon_review', "status='resolved' AND provenance='adopt_external_effect' AND receipt_ref=?", [$receipt->body['receiptRef']]));
            self::assertSame(1, $this->rows('billium_outbox_event', "type='execution.repair_recorded.v1' AND schema_version='billium.event.v3' AND contract_revision='events-2026-11-v3' AND aggregate_ref=?", [$receipt->body['receiptRef']]));
            $db = $app->make(DatabaseManager::class)->connection();
            $outbox = $db->table('billium_outbox_event')->where('type', 'execution.repair_recorded.v1')->sole();
            $wire = EventRows::envelope($outbox);
            $codec = new Codec;
            $public = $codec->decode($codec->canonicalize($wire));
            self::assertSame($codec->digest($wire), $outbox->digest);
            self::assertSame('seller-a', $public->raw->sellerRef);
            self::assertSame(1, $public->raw->revision);
            $payload = $public->raw->payload;
            self::assertInstanceOf(stdClass::class, $payload);
            self::assertSame('root-a', $payload->rootOperationRef);
            self::assertSame($receipt->body['receiptRef'], $public->raw->aggregateRef);
            $audit = $db->table('billium_execution_repair_receipt')->sole();
            self::assertSame($audit->before_digest, $payload->beforeDigest);
            self::assertSame($audit->after_digest, $payload->afterDigest);
            self::assertSame([$event, $payload->reviewEventRef, $outbox->event_id], json_decode(self::text($audit->correction_refs), true, flags: JSON_THROW_ON_ERROR));
            self::assertSame(1, $this->rows('billium_outbox_delivery', 'event_id=?', [$outbox->event_id]));
            $worker = new ChildProcess([PHP_BINARY, '-r', 'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Feature\\ManualRepairTest::restartReview($argv[1]);', $path]);
            self::assertSame(0, $worker->wait());
            $contents = file_get_contents($path.'.restart');
            self::assertIsString($contents);
            $persisted = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($persisted);
            self::assertSame($worker->pid, $persisted[0]);
            self::assertNotEquals($this->value('SELECT pg_backend_pid()'), $persisted[1]);
            self::assertSame(['adopt_external_effect', $receipt->body['receiptRef'], $payload->reviewEventRef, 0], array_slice($persisted, 2));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND confirmed_refund_minor=0'));
            self::assertSame(1, $this->rows('billium_execution_operation'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            InventoryReconciliationTest::grant($this->callers, []);
            HistoricalMerchantRefundTest::problem(ProblemCode::AuthDenied, static fn () => $api->repair($action, $event));
        } finally {
            InventoryReconciliationTest::clear($path);
        }
    }

    public function test_database_refuses_invalid_discrepancy_shape_code_resolution_and_rewrites(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            Refunds::captured($app, $path);
            InventoryReconciliationTest::grant($this->callers);
            InventoryReconciliationTest::enableInventory($app, $path);
            InventoryReconciliationTest::page($path, [DisputeSettlementTest::line('dispute', 75, 'case')]);
            InventoryReconciliationTest::scan($app, 'run', new DateTimeImmutable('-1 hour'), new DateTimeImmutable);
            $db = $app->make(DatabaseManager::class)->connection();
            $review = (array) $db->table('billium_execution_recon_review')->sole();
            unset($review['id']);
            foreach ([['expected' => '[]'], ['expected' => '{"status":""}'], ['observed' => '{}'], ['observed' => '{"factRef":null}'], ['observed' => '{"lookupState":[]}'], ['code' => 'ordinary_refund'], ['status' => 'resolved', 'receipt_ref' => null, 'next_review_at' => null]] as $i => $invalid) {
                try {
                    $db->table('billium_execution_recon_review')->insert(array_replace($review, $invalid, ['event_ref' => 'corrupt-'.$i]));
                    self::fail('Invalid discrepancy persisted');
                } catch (QueryException $error) {
                    self::assertSame('23514', $error->getCode());
                }
            }
            try {
                $db->table('billium_execution_recon_review')->update(['severity' => 'high']);
                self::fail('Allowed immutable review update');
            } catch (QueryException $error) {
                self::assertSame('42501', $error->getCode());
            }
            self::assertSame(1, $this->rows('billium_execution_recon_review'));
            foreach (['merchant_account_ref' => 'other-account', 'currency' => 'USD', 'source_authority' => 'standalone-billium', 'owner_namespace' => 'other-owner'] as $field => $value) {
                $foreign = 'foreign-'.$field;
                $db->table('billium_execution_recon_review')->insert(array_replace($review, ['event_ref' => $foreign, 'case_ref' => $foreign, $field => $value]));
                $action = $this->action('close_namespace', 'wrong-'.$field, $foreign, ['case']);
                HistoricalMerchantRefundTest::problem(ProblemCode::RecoveryRequired, static fn () => $app->make(RepairCommands::class)->repair($action, $foreign));
            }
            self::assertSame(0, $this->rows('billium_execution_repair_receipt'));
            self::assertSame(1, $this->rows('billium_execution_namespace', "state='open'"));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            InventoryReconciliationTest::clear($path);
        }
    }
}

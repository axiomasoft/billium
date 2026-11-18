<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Drill;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\CapturePayment;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\FinancialAdmissionToken;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\Drill\ExecutionRestoreTest as Restore;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Core\Tests\TestEnvironment;
use Billium\Execution\Contracts\AuthorizationCommands;
use Billium\Execution\Contracts\CapacityParticipants;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\OriginalIdentityArchive;
use Billium\Execution\Contracts\OriginalIdentityRecovery;
use Billium\Execution\Contracts\PaymentDriver;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\ReconciliationCommands;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ProviderLookupState;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest;
use Billium\Execution\Tests\Feature\AuthorizationLifecycleTest as Lifecycle;
use Billium\Execution\Tests\Feature\CallbackCustodyTest;
use Billium\Execution\Tests\Feature\InventoryReconciliationTest as Inventory;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Ledger\Internal\Commands\BootstrapPartitionCommand;
use Billium\Platform\Contracts\Inbox\ObservationBootstrapAuthenticator;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Platform\Data\Inbox\BootstrapPartition;
use Billium\Platform\Data\Inbox\VerifiedObservationBootstrap;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Restore\PostgresSnapshot;
use Billium\Testkit\Restore\SignedOriginalArchive;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

#[Group('pg')]
#[Group('ACC-RST-04')]
final class ArchivedChildOrderingTest extends ExecutionTestCase
{
    #[TestWith(['refund', true])]
    #[TestWith(['refund', false])]
    #[TestWith(['capture', true])]
    #[TestWith(['capture', false])]
    #[TestWith(['refund', true, true])]
    public function test_reconstructs_the_original_child_across_parent_report_and_archive_lookup_orders(string $kind, bool $parentFirst, bool $conflict = false): void
    {
        $app = $this->application();
        $scope = Fixture::scope();
        $selected = new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium');
        $path = Fixture::tempPath();
        $source = TestEnvironment::databaseName();
        $backup = null;
        try {
            $this->seedLedger($app, $selected);
            $refund = $kind === 'refund';
            $ref = 'archived-'.$kind;
            if ($refund) {
                RefundCapacityTest::captured($app, $path);
                $wire = RefundCapacityTest::refund($ref, 80, 22);
            } else {
                Lifecycle::authorize($app, $path);
                $wire = Lifecycle::child($ref, 80);
                self::assertInstanceOf(CapturePayment::class, $wire);
            }
            Inventory::enableInventory($app, $path);
            $key = getenv('BILLIUM_SIMULATED_AUTHORITY_KEY') ?: throw new LogicException('Missing independent archive key');
            $archive = new SignedOriginalArchive($path, $key, $this->authority);
            $backup = new PostgresSnapshot(TestEnvironment::pdo(owner: true), $source, $path.'.dump');
            $archive->recordSource($wire);
            if ($wire instanceof CapturePayment) {
                $app->make(AuthorizationCommands::class)->admit($scope, $wire)->orThrow();
            } else {
                $app->make(RefundCommands::class)->admit($scope, $wire)->orThrow();
            }
            Lifecycle::dispatch($app, $ref);
            $store = $app->make(ExecutionStore::class);
            $original = $store->operation($scope, $ref);
            $attempt = $store->attempt($scope, RefundCapacityTest::attemptRef($ref));
            $accepted = array_find(new DurableJournal($path)->records(), static fn (object $row): bool => $row instanceof ProviderRecord && $row->kind === EffectKind::from($kind));
            self::assertInstanceOf(ProviderRecord::class, $accepted);
            self::assertNotNull($original);
            self::assertNotNull($attempt);
            $oldToken = $this->tokens->currentToken(self::INSTALLATION);
            self::assertNotNull($oldToken);
            $archive->recordDispatch($accepted, $original, $attempt, $store->merchantRevision($scope, 'merchant-a'), AuthorityClaims::sourceLineage($oldToken, 1));
            self::assertSame(ReductionDisposition::Applied, Lifecycle::receive($app, $ref, Lifecycle::evidence($app, $ref))->disposition);
            // Fresh admission on the original active attachment still has only twenty units available.
            if ($refund) {
                Lifecycle::refuses(ProblemCode::RefundCapacityExceeded, fn () => $app->make(RefundCommands::class)->admit($scope, RefundCapacityTest::refund('too-large', 21, 23))->orThrow());
            } else {
                Lifecycle::refuses(ProblemCode::CaptureCapacityExceeded, fn () => $app->make(AuthorizationCommands::class)->admit($scope, Lifecycle::child('too-large', 21))->orThrow());
            }
            $duplicate = "INSERT INTO billium_execution_reservation (installation_ref,seller_ref,environment,reservation_ref,root_operation_ref,operation_ref,kind,requested_minor,currency,state)
                SELECT installation_ref,seller_ref,environment,'another-reservation',root_operation_ref,operation_ref,kind,requested_minor,currency,'held' FROM billium_execution_reservation";
            self::assertSame('23514', self::sqlState(TestEnvironment::pdo(), $duplicate));

            [$clone, $receiver, $receiverDb, $token] = $this->restorePair($app, $backup, $selected, $original->binding, $path, $key);
            $db = $clone->make(DatabaseManager::class)->connection();
            self::assertSame(1, self::pdoValue($receiverDb, "SELECT count(*) FROM billium_inbox_message WHERE status='applied'"));
            self::assertSame($refund ? 100 : 0, self::pdoValue($receiverDb, "SELECT captured_minor FROM billium_payment WHERE operation_ref='root-a'"));
            $recover = fn () => $clone->make(OriginalIdentityRecovery::class)->recover($scope, 'remote-billium', 'account-a', $attempt->providerReference);
            $rootReport = Lifecycle::evidence($clone, 'root-a');
            $childReport = Lifecycle::evidence($clone, $ref);
            $moneyColumn = $refund ? 'confirmed_refund_minor' : 'confirmed_capture_minor';
            if ($parentFirst) {
                Inventory::page($path, [$rootReport, $childReport]);
                $clone->make(ReconciliationCommands::class)->inventory($scope, 'remote-billium', 'overlap-inventory', 'merchant-a', 'RUB', new DateTimeImmutable('-5 minutes'), new DateTimeImmutable)->orThrow();
                self::assertSame(80, $db->table('billium_execution_capacity')->value($moneyColumn));
                self::assertSame(0, $db->table('billium_execution_operation')->where('operation_ref', $ref)->count());
                self::assertSame('unmatched_signed_operation', $db->table('billium_execution_provider_work')->where('evidence_ref', $childReport->evidenceRef)->value('last_error_code'));
                if ($conflict) {
                    $overRefund = CallbackCustodyTest::signed($rootReport, ['revision' => '3', 'refunded' => ['amount' => '1.20', 'currency' => 'RUB']]);
                    self::assertSame(ReductionDisposition::HeldDiscrepancy, Lifecycle::receive($clone, 'root-a', $overRefund)->disposition);
                    self::assertTrue($db->table('billium_execution_capacity')->value('financial_hold'));
                }
                $drivers = $clone->make(PaymentDrivers::class);
                $merchant = $store->merchantRevision($scope, 'merchant-a');
                $unknown = self::createStub(PaymentDriver::class);
                $unknown->method('contractVersion')->willReturn($merchant->driverContractVersion);
                $unknown->method('lookup')->willReturn(new ProviderLookupResult(ProviderLookupState::Ambiguous, null, null, null, 'unknown', null));
                $unavailable = self::createStub(PaymentDrivers::class);
                $unavailable->method('capabilities')->willReturn($drivers->capabilities($merchant));
                $unavailable->method('resolve')->willReturn($unknown);
                $clone->instance(PaymentDrivers::class, $unavailable);
                Lifecycle::refuses(ProblemCode::RecoveryRequired, $recover);
                $remaining = $db->table('billium_execution_reservation')->where('operation_ref', $ref)->sole();
                self::assertSame(['held', 0, null], [$remaining->state, $remaining->consumed_minor, $remaining->release_evidence_ref]);
                self::assertSame(80, $db->table('billium_execution_capacity')->value($refund ? 'held_refund_minor' : 'held_capture_minor'));
                self::assertSame(80, $db->table('billium_execution_capacity')->value($moneyColumn));
                self::assertSame(0, $db->table('billium_execution_provider_fact')->where('operation_ref', $ref)->count());
                self::assertSame(0, $db->table('billium_execution_dispatch_work')->where('operation_ref', $ref)->count());
                self::assertSame(2, UnknownResultTest::events($path, 'provider.call'));
                $clone->instance(PaymentDrivers::class, $drivers);
                if ($conflict) {
                    self::assertSame(ReductionDisposition::HeldDiscrepancy, $recover()->disposition);
                    self::assertTrue($db->table('billium_execution_capacity')->value('financial_hold'));
                    self::assertSame(80, $db->table('billium_execution_capacity')->value('confirmed_refund_minor'));
                    self::assertSame(80, $db->table('billium_execution_capacity')->value('held_refund_minor'));
                    self::assertSame('held', $db->table('billium_execution_reservation')->where('operation_ref', $ref)->value('state'));
                    self::assertGreaterThan(0, $db->table('billium_execution_provider_fact')->where('operation_ref', $ref)->count());
                    self::assertSame(2, UnknownResultTest::events($path, 'provider.call'));

                    return;
                }
            }
            self::assertSame(ReductionDisposition::Applied, $recover()->disposition);
            if (! $parentFirst) {
                $result = Lifecycle::receive($clone, 'root-a', $rootReport);
                self::assertContains($result->disposition, [ReductionDisposition::Applied, ReductionDisposition::Duplicate]);
            }
            self::assertSame(ReductionDisposition::Duplicate, $recover()->disposition);
            $capacity = $db->table('billium_execution_capacity')->sole();
            self::assertSame($refund ? 100 : 80, $capacity->confirmed_capture_minor);
            self::assertSame($refund ? 80 : 0, $capacity->confirmed_refund_minor);
            self::assertSame(0, $capacity->held_capture_minor);
            self::assertSame(0, $capacity->held_refund_minor);
            // An existing archive cannot exempt a terminal attempt or a reservation for a different seller.
            self::assertSame('23514', self::sqlState(TestEnvironment::pdo(), $duplicate));
            self::assertSame('23514', self::sqlState(TestEnvironment::pdo(), str_replace('SELECT installation_ref,seller_ref', "SELECT installation_ref,'foreign-seller'", $duplicate)));
            self::assertFalse($capacity->financial_hold);
            $reservation = $db->table('billium_execution_reservation')->where('operation_ref', $ref)->sole();
            self::assertSame(['consumed', 80, $childReport->evidenceRef], [$reservation->state, $reservation->consumed_minor, $reservation->release_evidence_ref]);
            self::assertSame(1, $db->table('billium_execution_operation_revision')->where('operation_ref', $ref)->count());
            self::assertSame(0, $db->table('billium_execution_dispatch_work')->where('operation_ref', $ref)->count());
            self::assertSame('succeeded', $db->table('billium_execution_attempt')->where('attempt_ref', $attempt->attemptRef)->value('state'));
            self::assertSame(2, UnknownResultTest::events($path, 'provider.call'));
            self::assertCount(2, array_filter(new DurableJournal($path)->records(), static fn (object $entry): bool => $entry instanceof ProviderRecord));
            $emissions = $db->table('billium_execution_observation_emission')->orderBy('id')->get();
            $sequences = [];
            foreach ($emissions as $emission) {
                self::assertSame(($sequences[$emission->partition_ref] ?? 0) + 1, $emission->source_sequence);
                $sequences[$emission->partition_ref] = $emission->source_sequence;
                if ($emission->source_lineage === AuthorityClaims::sourceLineage($token, 2)) {
                    Restore::relay($db, $receiver, $path, 1);
                }
            }
            self::assertSame($refund ? 1 : 0, $emissions->where('operation_ref', $ref)->count());
            self::assertSame($refund ? 0 : 1, $db->table('billium_outbox_event')->where('type', 'payment.effect.outcome.v1')->count());
            self::assertSame($refund ? 100 : 80, self::pdoValue($receiverDb, "SELECT captured_minor FROM billium_payment WHERE operation_ref='root-a'"));
            self::assertSame($refund ? 80 : 0, self::pdoValue($receiverDb, "SELECT refunded_minor FROM billium_payment WHERE operation_ref='root-a'"));
            self::assertSame($refund ? 100 : 80, self::pdoValue($receiverDb, "SELECT allocated_minor FROM billium_payment WHERE operation_ref='root-a'"));
            self::assertSame($refund ? 1 : 0, self::pdoValue($receiverDb, "SELECT count(*) FROM billium_allocation_receipt WHERE kind='refund_detail'"));
            self::assertSame($emissions->count(), self::pdoValue($receiverDb, "SELECT count(*) FROM billium_inbox_message WHERE status='applied'"));
        } finally {
            self::selectDatabase($source);
            $backup?->close();
            foreach (glob($path.'*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    #[TestWith([false, 'parent-first'])]
    #[TestWith([true, 'parent-first'])]
    #[TestWith([false, 'interleaved'])]
    #[TestWith([true, 'ordinary'])]
    #[TestWith([false, 'ordinary-overlap'])]
    #[TestWith([false, 'over-refund'])]
    #[TestWith([false, 'contradictory-children'])]
    public function test_recovers_two_separately_unresolved_archived_refunds(bool $reverse, string $scenario): void
    {
        $app = $this->application();
        $scope = Fixture::scope();
        $selected = new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium');
        $path = Fixture::tempPath();
        $source = TestEnvironment::databaseName();
        $backup = null;
        try {
            $this->seedLedger($app, $selected);
            RefundCapacityTest::captured($app, $path);
            $ordinary = in_array($scenario, ['ordinary', 'ordinary-overlap'], true) ? 20 : 0;
            if ($ordinary > 0) {
                $app->make(RefundCommands::class)->admit($scope, RefundCapacityTest::refund('ordinary', $ordinary, 24))->orThrow();
            }
            Inventory::enableInventory($app, $path);
            $key = getenv('BILLIUM_SIMULATED_AUTHORITY_KEY') ?: throw new LogicException('Missing independent archive key');
            $archive = new SignedOriginalArchive($path, $key, $this->authority);
            $backup = new PostgresSnapshot(TestEnvironment::pdo(owner: true), $source, $path.'.dump');
            $refs = ['original-a', 'original-b'];
            $store = $app->make(ExecutionStore::class);
            $merchant = $store->merchantRevision($scope, 'merchant-a');
            $oldToken = $this->tokens->currentToken(self::INSTALLATION);
            self::assertNotNull($oldToken);
            $references = [];
            foreach ($refs as $index => $ref) {
                $wire = RefundCapacityTest::refund($ref, 40, 25 + $index);
                $archive->recordSource($wire);
                $app->make(RefundCommands::class)->admit($scope, $wire)->orThrow();
                Lifecycle::dispatch($app, $ref);
                $record = array_find(new DurableJournal($path)->records(), static fn (object $row): bool => $row instanceof ProviderRecord && $row->request->permit->operationRef === $ref);
                $operation = $store->operation($scope, $ref);
                $attempt = $store->attempt($scope, RefundCapacityTest::attemptRef($ref));
                self::assertInstanceOf(ProviderRecord::class, $record);
                self::assertNotNull($operation);
                self::assertNotNull($attempt);
                $references[$ref] = $attempt->providerReference;
                $archive->recordDispatch($record, $operation, $attempt, $merchant, AuthorityClaims::sourceLineage($oldToken, 1));
                self::assertSame(ReductionDisposition::Applied, Lifecycle::receive($app, $ref, Lifecycle::evidence($app, $ref))->disposition);
            }
            // The active source has exactly twenty left, shared with any ordinary outstanding request.
            Lifecycle::refuses(ProblemCode::RefundCapacityExceeded, fn () => $app->make(RefundCommands::class)
                ->admit($scope, RefundCapacityTest::refund('one-too-many', 21 - $ordinary, 27))->orThrow());
            if ($ordinary === 0) {
                $app->make(RefundCommands::class)->admit($scope, RefundCapacityTest::refund('remaining-twenty', 20, 28))->orThrow();
            }
            $root = $store->operation($scope, 'root-a');
            self::assertNotNull($root);
            [$clone, $receiver, $receiverDb, $token] = $this->restorePair($app, $backup, $selected, $root->binding, $path, $key);
            $db = $clone->make(DatabaseManager::class)->connection();
            $report = Lifecycle::evidence($clone, 'root-a');
            if ($scenario === 'contradictory-children') {
                // Two distinct authenticated actuals of forty cannot both fit a cumulative refund of forty.
                $report = CallbackCustodyTest::signed($report, ['refunded' => ['amount' => '0.40', 'currency' => 'RUB']]);
            }
            if (! in_array($scenario, ['interleaved', 'ordinary-overlap'], true)) {
                self::assertSame(ReductionDisposition::Applied, Lifecycle::receive($clone, 'root-a', $report)->disposition);
            }
            $drivers = $clone->make(PaymentDrivers::class);
            $unknown = self::createStub(PaymentDriver::class);
            $unknown->method('contractVersion')->willReturn($merchant->driverContractVersion);
            $unknown->method('lookup')->willReturn(new ProviderLookupResult(ProviderLookupState::Ambiguous, null, null, null, 'unknown', null));
            $unavailable = self::createStub(PaymentDrivers::class);
            $unavailable->method('capabilities')->willReturn($drivers->capabilities($merchant));
            $unavailable->method('resolve')->willReturn($unknown);
            $recover = fn (string $ref) => $clone->make(OriginalIdentityRecovery::class)->recover($scope, 'remote-billium', 'account-a', $references[$ref]);
            foreach ($refs as $i => $ref) {
                $clone->instance(PaymentDrivers::class, $unavailable);
                Lifecycle::refuses(ProblemCode::RecoveryRequired, fn () => $recover($ref));
                $clone->instance(PaymentDrivers::class, $drivers);
                if ($scenario === 'interleaved' && $i === 0) {
                    self::assertSame(ReductionDisposition::Applied, Lifecycle::receive($clone, 'root-a', $report)->disposition);
                }
            }
            $capacity = $db->table('billium_execution_capacity');
            $reservations = $db->table('billium_execution_reservation');
            self::assertSame(80 + $ordinary, $capacity->value('held_refund_minor'));
            self::assertSame(2, $db->table('billium_execution_original_identity_import')->whereIn('operation_ref', $refs)->count());
            self::assertSame(0, $db->table('billium_execution_provider_fact')->whereIn('operation_ref', $refs)->count());
            self::assertSame(0, $db->table('billium_execution_dispatch_work')->whereIn('operation_ref', $refs)->count());
            self::assertSame(3, UnknownResultTest::events($path, 'provider.call'));
            if ($scenario === 'ordinary-overlap') {
                $report = CallbackCustodyTest::signed($report, ['revision' => '4', 'refunded' => ['amount' => '0.81', 'currency' => 'RUB']]);
                $result = Lifecycle::receive($clone, 'root-a', $report);
                self::assertSame(ReductionDisposition::HeldDiscrepancy, $result->disposition);
                self::assertSame('refund_reservation_overlap', $db->table('billium_execution_discrepancy')->where('discrepancy_ref', $result->discrepancyRef)->value('exposure->reason'));
                self::assertTrue($capacity->value('financial_hold'));
                self::assertSame(100, $capacity->value('held_refund_minor'));
                self::assertSame(0, $capacity->value('confirmed_refund_minor'));
                self::assertSame(3, (clone $reservations)->where('state', 'held')->count());

                return;
            }
            if ($scenario === 'ordinary') {
                // Observe the real participant called at the production boundary for both recovered facts.
                $participant = $clone->make(CapacityParticipants::class);
                $observed = $this->createMock(CapacityParticipants::class);
                $observed->method('current')->willReturnCallback($participant->current(...));
                $observed->expects(self::exactly(2))->method('settle')->willReturnCallback($participant->settle(...));
                $observed->expects(self::never())->method('reserve');
                $clone->instance(CapacityParticipants::class, $observed);
            }
            foreach ($reverse ? array_reverse($refs) : $refs as $i => $ref) {
                $fact = Lifecycle::evidence($clone, $ref);
                if (in_array($scenario, ['over-refund', 'contradictory-children'], true)) {
                    $amount = $scenario === 'over-refund' ? '1.20' : '0.40';
                    $fact = CallbackCustodyTest::signed($fact, ['revision' => '4', 'refunded' => ['amount' => $amount, 'currency' => 'RUB']]);
                    $result = Lifecycle::receive($clone, $ref, $fact);
                } else {
                    $result = $recover($ref);
                }
                if ($scenario === 'over-refund' || ($scenario === 'contradictory-children' && $i === 1)) {
                    self::assertSame(ReductionDisposition::HeldDiscrepancy, $result->disposition);
                    self::assertTrue($capacity->value('financial_hold'));
                    self::assertSame($scenario === 'over-refund' ? 80 : 40, $capacity->value('held_refund_minor'));
                    $conflict = $db->table('billium_execution_discrepancy')->where('discrepancy_ref', $result->discrepancyRef);
                    self::assertSame($scenario === 'over-refund' ? 'over_refund' : 'known_child_effects_exceed_cumulative', $conflict->value('exposure->reason'));
                    self::assertSame($scenario === 'over-refund' ? '20' : '0', $conflict->value('exposure->overRefunded->minor'));
                    self::assertSame($scenario === 'over-refund' ? 80 : 40, $capacity->value('confirmed_refund_minor'));
                    self::assertSame('held', (clone $reservations)->where('operation_ref', $ref)->value('state'));
                    self::assertSame(3, UnknownResultTest::events($path, 'provider.call'));

                    return;
                }
                self::assertSame(ReductionDisposition::Applied, $result->disposition);
                if ($scenario !== 'contradictory-children') {
                    self::assertSame(ReductionDisposition::Duplicate, $recover($ref)->disposition);
                }
                $reservation = (clone $reservations)->where('operation_ref', $ref)->sole();
                self::assertSame(['consumed', 40, $fact->evidenceRef], [$reservation->state, $reservation->consumed_minor, $reservation->release_evidence_ref]);
                self::assertSame(($i === 0 ? 40 : 0) + $ordinary, $capacity->value('held_refund_minor'));
                self::assertSame($scenario === 'contradictory-children' ? 40 : 80, $capacity->value('confirmed_refund_minor'));
                self::assertFalse($capacity->value('financial_hold'));
            }
            if ($ordinary > 0) {
                self::assertSame('held', (clone $reservations)->where('operation_ref', 'ordinary')->value('state'));
            }
            self::assertSame(3, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(0, $db->table('billium_execution_dispatch_work')->whereIn('operation_ref', $refs)->count());
            self::assertSame(100, $capacity->value('confirmed_capture_minor'));
            $emissions = $db->table('billium_execution_observation_emission')->where('source_lineage', AuthorityClaims::sourceLineage($token, 2))->orderBy('id')->get();
            self::assertSame(2, $emissions->whereIn('operation_ref', $refs)->count());
            foreach ($emissions as $emission) {
                Restore::relay($db, $receiver, $path, 1);
            }
            self::assertSame(100, self::pdoValue($receiverDb, "SELECT captured_minor FROM billium_payment WHERE operation_ref='root-a'"));
            self::assertSame(80, self::pdoValue($receiverDb, "SELECT refunded_minor FROM billium_payment WHERE operation_ref='root-a'"));
            self::assertSame(100, self::pdoValue($receiverDb, "SELECT allocated_minor FROM billium_payment WHERE operation_ref='root-a'"));
            $details = self::pdoValue($receiverDb, "SELECT count(*) FROM billium_allocation_receipt WHERE kind='refund_detail'");
            self::assertSame(2, $details);
            fwrite(STDOUT, json_encode(['archiveRecovery' => $scenario, 'terminalOrder' => $reverse ? array_reverse($refs) : $refs,
                'confirmedRefund' => $capacity->value('confirmed_refund_minor'), 'heldRefund' => $capacity->value('held_refund_minor'),
                'financialHold' => $capacity->value('financial_hold'), 'receiverRefundDetails' => $details,
                'providerCalls' => UnknownResultTest::events($path, 'provider.call')], JSON_THROW_ON_ERROR).PHP_EOL);
        } finally {
            self::selectDatabase($source);
            $backup?->close();
            foreach (glob($path.'*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    public function test_distinct_active_child_actuals_must_fit_the_provider_cumulative_without_any_archive(): void
    {
        $app = $this->application();
        $scope = Fixture::scope();
        $path = Fixture::tempPath();
        try {
            RefundCapacityTest::captured($app, $path);
            Inventory::enableInventory($app, $path);
            foreach (['ordinary-a', 'ordinary-b'] as $i => $ref) {
                $app->make(RefundCommands::class)->admit($scope, RefundCapacityTest::refund($ref, 40, $i + 51))->orThrow();
                Lifecycle::dispatch($app, $ref);
                $fact = Lifecycle::evidence($app, $ref);
                if ($i === 1) {
                    $fact = CallbackCustodyTest::signed($fact, ['revision' => '4', 'refunded' => ['amount' => '0.40', 'currency' => 'RUB']]);
                }
                $result = Lifecycle::receive($app, $ref, $fact);
                self::assertSame($i === 0 ? ReductionDisposition::Applied : ReductionDisposition::HeldDiscrepancy, $result->disposition);
            }
            $db = $app->make(DatabaseManager::class)->connection();
            self::assertSame(0, $db->table('billium_execution_original_identity_import')->count());
            $capacity = $db->table('billium_execution_capacity')->sole();
            self::assertSame([40, 40, true], [$capacity->confirmed_refund_minor, $capacity->held_refund_minor, $capacity->financial_hold]);
            self::assertSame('known_child_effects_exceed_cumulative', $db->table('billium_execution_discrepancy')->value('exposure->reason'));
            self::assertSame(3, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            foreach (glob($path.'*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    private function seedLedger(Application $app, FinancialScope $selected): void
    {
        $ledger = $app->make(LedgerParticipants::class);
        $spec = $this->spec(financialScope: $selected);
        $account = new BillingAccountScope('seller-a', 'test', 'billing-a');
        $this->runner()->run($spec, static fn (TransactionContext $ctx) => $ledger->recordObligation($ctx, new RecordObligation('debt', $account, 'debt', 'basis', 'buyer', 'component', new Money(100, Currency::of('RUB')), 'sha256:'.str_repeat('d', 64))))->orThrow();
        $this->runner()->run($spec, static fn (TransactionContext $ctx) => $ledger->declareSettlementIntent($ctx, new DeclareSettlementIntent('declare', $account, 'intent-a', 'RUB', ['debt'])))->orThrow();
    }

    /** @return array{Application,string,PDO,FinancialAdmissionToken} */
    private function restorePair(Application $app, PostgresSnapshot $backup, FinancialScope $selected, FrozenBinding $binding, string $path, string $key): array
    {
        $receiver = $backup->restore();
        self::selectDatabase($receiver);
        $receiverDb = TestEnvironment::pdo();
        $receiverApp = Restore::receiverApplication();
        self::selectDatabase($backup->restore());
        $clone = Restore::cloneApplication($app);
        $db = $clone->make(DatabaseManager::class)->connection();
        $authority = $clone->make(SimulatedFinancialAuthorityEvidence::class);
        $authority->issueTransition('open', 'archive-open', ['owner-2', 2, 2, 2, 'original-workers-retired']);
        $clone->make(Installation::class)->openSession('archive-open', 'owner-2', 2, 2, 2, 'original-workers-retired');
        $token = FixedSessionAdmission::token(self::INSTALLATION, 'owner-2', 2, 2, 2, [FinancialMode::Recovery]);
        $clone->make(FinancialSessionAdmission::class)->set($token);
        $authority->issueProcess($token, [$selected]);
        $clone->instance(OriginalIdentityArchive::class, new SignedOriginalArchive($path, $key, $authority));
        Restore::relay($db, $receiver, $path, 1);
        $this->adoptNewPartition($clone, $receiverApp, $binding, $selected, $token, $backup->sha256, $key);

        return [$clone, $receiver, $receiverDb, $token];
    }

    private function adoptNewPartition(Application $producer, Application $receiver, FrozenBinding $binding, FinancialScope $selection,
        FinancialAdmissionToken $token, string $backupDigest, string $key): void
    {
        $scope = $binding->scope;
        $result = $producer->make(OperationRunner::class)->run(new OperationSpec('execution.snapshot_restored_partition', $scope->sellerRef, $scope->environment,
            FinancialMode::Recovery, ModuleOperationClass::UnknownResolution, ['execution', 'platform'], financialScope: $selection),
            static function (TransactionContext $ctx) use ($producer, $token, $binding): PlatformReceipt {
                $store = $producer->make(ExecutionStore::class);
                $part = $store->ensureObservationPartition($ctx, $binding->scope, $binding->executionProfile, AuthorityClaims::sourceLineage($token, $ctx->sessionEpoch()), $binding->rootOperationRef);
                $store->lock($ctx, $store->plan($binding->scope, $binding->rootOperationRef, context: $ctx));
                self::assertEquals($binding, $store->operation($binding->scope, $binding->rootOperationRef, $ctx)?->binding);
                self::assertSame(0, $part->lastSequence);

                return new PlatformReceipt('execution.partition_snapshot', ['partition' => $part->partitionRef, 'lineage' => $part->sourceLineage]);
            })->orThrow();
        $namespace = ['channel' => 'payment.observation', 'sourceAuthority' => $binding->executionProfile,
            'sourceLineage' => self::text($result->body['lineage']), 'sellerRef' => $scope->sellerRef, 'environment' => $scope->environment, 'partitionRef' => self::text($result->body['partition'])];
        $snapshot = new BootstrapPartition('restore-'.$backupDigest, ...$namespace, cursorSequence: 0, snapshotDigest: BootstrapPartition::digestOf(0, [], $namespace),
            sourceRetentionLeaseRef: 'fixture-dump-'.$backupDigest, expectedItemCount: 0, items: []);
        $root = $binding->rootOperationRef;
        $account = $binding->merchantAccountRef;
        $digest = VerifiedObservationBootstrap::digest($snapshot, $selection, $root, $account);
        $proof = 'sha256:'.hash_hmac('sha256', $digest, $key);
        $host = self::createStub(ObservationBootstrapAuthenticator::class);
        $host->method('authenticate')->willReturnCallback(static function (BootstrapPartition $candidate) use ($selection, $root, $account, $proof, $key): VerifiedObservationBootstrap {
            $received = VerifiedObservationBootstrap::digest($candidate, $selection, $root, $account);
            if (! hash_equals($proof, 'sha256:'.hash_hmac('sha256', $received, $key))) {
                throw new LogicException('Unrecognized signed partition snapshot');
            }

            return new VerifiedObservationBootstrap($selection, $root, $account, 'simulated-archive-host', VerifiedObservationBootstrap::AUDIENCE, new DateTimeImmutable('+1 hour'), $received, $proof);
        });
        $receiver->instance(ObservationBootstrapAuthenticator::class, $host);
        self::assertSame('adopted', $receiver->make(BootstrapPartitionCommand::class)->executeQualified($snapshot)->status);
    }

    private static function selectDatabase(string $database): void
    {
        $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;
        putenv('DB_DATABASE='.$database);
    }
}

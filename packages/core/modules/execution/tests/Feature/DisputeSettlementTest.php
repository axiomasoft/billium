<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Data\InventoryReportFact;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
#[Group('PB-24')]
#[Group('PF-11')]
final class DisputeSettlementTest extends ExecutionTestCase
{
    public static function line(string $kind, int $minor, string $ref, string $revision = '1', ?DateTimeImmutable $finalAfter = null): InventoryReportFact
    {
        return new InventoryReportFact($kind, $ref, $revision, 'root-a', new Money($minor, Currency::of('RUB')), new DateTimeImmutable,
            'sha256:'.hash('sha256', implode('|', [$kind, $ref, $revision, (string) $minor])), $finalAfter);
    }

    public function test_disputes_fees_net_settlement_and_manual_effects_keep_typed_exposure_without_refund_debt_or_replacement(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            Refunds::captured($app, $path);
            InventoryReconciliationTest::enableInventory($app, $path);
            InventoryReconciliationTest::grant($this->callers);
            $lines = [];
            foreach (['dispute' => 72, 'chargeback' => 40, 'fee' => 3, 'settlement' => 57, 'external_manual' => 12] as $kind => $minor) {
                $lines[] = self::line($kind, $minor, 'report-'.$kind);
            }
            InventoryReconciliationTest::page($path, $lines);
            $from = new DateTimeImmutable('-10 minutes');
            self::assertSame('complete', InventoryReconciliationTest::scan($app, 'typed-a', $from, new DateTimeImmutable));
            self::assertSame('complete', InventoryReconciliationTest::scan($app, 'typed-b', $from, new DateTimeImmutable));
            foreach ($lines as $line) {
                self::assertSame(1, $this->rows('billium_execution_recon_review', "code=? AND status='open' AND (observed->>'minor')::bigint=? AND (expected->>'capturedMinor')::bigint=100", [$line->kind, $line->amount->minor]));
            }
            self::assertSame(5, $this->rows('billium_execution_recon_review'));
            self::assertSame(1, $this->rows('billium_execution_provider_fact'));
            self::assertSame(1, $this->rows('billium_execution_operation'));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND confirmed_refund_minor=0'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            InventoryReconciliationTest::clear($path);
        }
    }

    public function test_fixed_charge_revision_tracks_three_changing_refund_bases_then_appends_resolution_without_erasing_alerts(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            Refunds::captured($app, $path);
            InventoryReconciliationTest::grant($this->callers);
            InventoryReconciliationTest::enableInventory($app, $path);
            $from = new DateTimeImmutable('-10 minutes');
            $probe = self::line('balance_comparison', 30, 'root-refunds');
            $provisional = self::line('balance_comparison', 30, 'root-refunds', 'preliminary', new DateTimeImmutable('+10 minutes'));
            InventoryReconciliationTest::page($path, [$provisional]);
            self::assertSame('complete', InventoryReconciliationTest::scan($app, 'provisional', $from, new DateTimeImmutable));
            self::assertSame(0, $this->rows('billium_execution_recon_review'));
            InventoryReconciliationTest::page($path, [$probe]);
            $scope = Fixture::scope();
            self::assertSame('complete', InventoryReconciliationTest::scan($app, 'compare-initial', $from, new DateTimeImmutable));
            for ($i = 1; $i <= 3; $i++) {
                $app->make(RefundCommands::class)->admit($scope, Refunds::refund('refund-'.$i, 10, 20 + $i))->orThrow();
                self::assertSame('complete', InventoryReconciliationTest::scan($app, 'reserved-'.$i, $from, new DateTimeImmutable));
            }
            self::assertSame(1, self::integer($this->value("SELECT count(DISTINCT expected->>'rootRevision') FROM billium_execution_recon_review")));
            self::assertSame(4, self::integer($this->value("SELECT count(DISTINCT expected->>'contributingRevisionsDigest') FROM billium_execution_recon_review")));
            $db = $app->make(DatabaseManager::class)->connection();
            for ($i = 1; $i <= 3; $i++) {
                $op = 'refund-'.$i;
                $app->make(AttemptWorker::class)->dispatch($scope, Refunds::attemptRef($op), 'refund-worker', new DateTimeImmutable('+1 minute'))->orThrow();
                $work = $db->table('billium_execution_provider_work')->where('operation_hint', $op)->where('handler_contract_version', 'simulated-driver.v1')->value('work_ref');
                self::assertIsString($work);
                $app->make(EvidenceReducer::class)->reduce($work);
                self::assertSame('complete', InventoryReconciliationTest::scan($app, 'compare-'.$i, $from, new DateTimeImmutable));
            }
            self::assertSame(6, $this->rows('billium_execution_recon_review', "code='balance_mismatch' AND status='open'"));
            self::assertSame(1, $this->rows('billium_execution_recon_review', "code='balance_mismatch' AND status='resolved' AND receipt_ref='compare-3'"));
            self::assertSame(7, self::integer($this->value('SELECT count(DISTINCT basis_digest) FROM billium_execution_recon_review')));
            self::assertSame(4, self::integer($this->value("SELECT count(DISTINCT expected->>'refundedMinor') FROM billium_execution_recon_review")));
            self::assertSame(1, $this->rows('billium_execution_provider_fact', "operation_ref='root-a'"));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_refund_minor=30'));
            self::assertSame(4, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            InventoryReconciliationTest::clear($path);
        }
    }
}

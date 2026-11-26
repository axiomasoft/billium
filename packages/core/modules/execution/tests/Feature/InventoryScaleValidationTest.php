<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Data\InventoryReportFact;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class InventoryScaleValidationTest extends ExecutionTestCase
{
    public function test_a_wrong_scale_cannot_resolve_a_review_or_advance_its_watermark(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            Refunds::captured($app, $path);
            InventoryReconciliationTest::enableInventory($app, $path);
            InventoryReconciliationTest::grant($this->callers);
            $from = new DateTimeImmutable('-10 minutes');
            InventoryReconciliationTest::page($path, [DisputeSettlementTest::line('balance_comparison', 10, 'same-comparison')]);
            self::assertSame('complete', InventoryReconciliationTest::scan($app, 'valid-scale', $from, new DateTimeImmutable));
            self::assertSame(1, $this->rows('billium_execution_recon_review', "status='open'"));
            $line = DisputeSettlementTest::line('balance_comparison', 0, 'same-comparison', '2');
            InventoryReconciliationTest::page($path, [$line]);
            $file = $path.'.page.'.hash('sha256', '');
            $raw = file_get_contents($file);
            self::assertIsString($raw);
            /** @var array{items:list<array{amount:array{currency:array{scale:int}}}>} $page */
            $page = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            $page['items'][0]['amount']['currency']['scale'] = 3;
            file_put_contents($file, json_encode($page, JSON_THROW_ON_ERROR));
            HistoricalMerchantRefundTest::problem(ProblemCode::MoneyScaleInvalid,
                static fn (): string => InventoryReconciliationTest::scan($app, 'wrong-scale', $from, new DateTimeImmutable));
            self::assertSame(1, $this->rows('billium_execution_recon_review', "status='open'"));
            self::assertSame(0, $this->rows('billium_execution_recon_review', "status='resolved'"));
            self::assertSame(1, $this->rows('billium_execution_reconciliation_run', "run_ref='wrong-scale' AND completeness_watermark IS NULL"));
            self::assertSame(1, $this->rows('billium_execution_recon_page'));
            HistoricalMerchantRefundTest::problem(ProblemCode::MoneyScaleInvalid,
                static fn (): InventoryReportFact => new InventoryReportFact('fee', 'bad-scale', '1', 'root-a', new Money(100, new Currency('RUB', 3)),
                    new DateTimeImmutable, 'sha256:'.str_repeat('a', 64)));
        } finally {
            InventoryReconciliationTest::clear($path);
        }
    }
}

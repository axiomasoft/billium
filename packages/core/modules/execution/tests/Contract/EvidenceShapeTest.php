<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Contract;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Data\AbsenceCertificate;
use Billium\Execution\Data\AdmissionConflict;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\Fiscal\FiscalActualEffect;
use Billium\Execution\Data\Fiscal\FiscalExternalOutcome;
use Billium\Execution\Data\Fiscal\FiscalProjectionInput;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\InventoryReportFact;
use Billium\Execution\Enums\Fiscal\FiscalOperationKind;
use Billium\Execution\Enums\Fiscal\FiscalReceiptKind;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Fiscal, absence, conflict and statement evidence refuse an impossible shape at construction. */
final class EvidenceShapeTest extends TestCase
{
    private const string DIGEST = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @return iterable<string, array{Closure(): object}> */
    public static function impossible(): iterable
    {
        $scope = new ExecutionScope('installation', 'seller', 'test', 'owner');
        $zero = Money::parse('0.00', 'RUB');
        $now = new DateTimeImmutable('2026-11-25T10:00:00Z');
        yield 'zero fiscal actual effect' => [static fn (): object => new FiscalActualEffect($scope, 'remote-billium', 'effect', 'root', 'operation',
            FiscalOperationKind::Charge, FiscalReceiptKind::Sale, $zero, 'merchant-revision', 'account', 'evidence')];
        yield 'negative fiscal correction revision' => [static fn (): object => new FiscalExternalOutcome('operation', 'effect', FiscalReceiptKind::Sale, 'pending', self::DIGEST, null, -1)];
        yield 'base fiscal outcome with a correction receipt' => [static fn (): object => new FiscalExternalOutcome('operation', 'effect', FiscalReceiptKind::CorrectionSale, 'pending', self::DIGEST)];
        yield 'zero fiscal projection' => [static fn (): object => new FiscalProjectionInput($scope, 'authority', 'effect', 'root', 'operation', FiscalReceiptKind::Sale, 0, $zero, 'pending')];
        yield 'reversed absence coverage' => [static fn (): object => new AbsenceCertificate('certificate', new FrozenBinding($scope, 'remote-billium', 'intent', 'root', 'revision',
            'account', 'driver', 'driver.v1', 'capability', 'semantic', 'execution.intent.v1', null, 'shop'), 'reference', 'namespace', 'watermark',
            $now, $now->modify('-1 second'), 'manifest', 'retirement', 'fence', 'qualification', 'issuer', 'signature', $now->modify('+1 hour'))];
        yield 'conflict of a non-payment command' => [static fn (): object => new AdmissionConflict($scope, 'payment.find.v1', self::DIGEST, self::DIGEST, null)];
        yield 'balance comparison without root' => [static fn (): object => new InventoryReportFact('balance_comparison', 'fact', 'r1', null, Money::parse('1.00', 'RUB'), $now, self::DIGEST)];
        yield 'final-after on a fee line' => [static fn (): object => new InventoryReportFact('fee', 'fact', 'r1', 'root', Money::parse('1.00', 'RUB'), $now, self::DIGEST, $now->modify('+1 day'))];
    }

    /** @param Closure(): object $make */
    #[DataProvider('impossible')]
    public function test_an_impossible_evidence_shape_is_refused(Closure $make): void
    {
        try {
            $make();
            self::fail('impossible evidence shape');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }
    }
}

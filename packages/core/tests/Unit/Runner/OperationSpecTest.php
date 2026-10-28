<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit\Runner;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\OperationSpec;
use Billium\Core\Data\StoredOutcome;
use Billium\Core\Enums\FinancialMode;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('D31')]
#[Group('D55')]
final class OperationSpecTest extends TestCase
{
    public function test_spec_sorts_the_closure_and_flags_keyed_monetary_operations(): void
    {
        $spec = new OperationSpec('ledger.record', 's', 'e', FinancialMode::Active, ModuleOperationClass::NewIntent, ['platform', 'ledger'], 'k', 'd', 'ledger.record');
        self::assertSame(['ledger', 'platform'], $spec->modules);
        self::assertTrue($spec->keyed());
        self::assertTrue($spec->monetary());
    }

    public function test_spec_rejects_incoherent_declarations(): void
    {
        $cases = [
            'key without digest' => static fn () => new OperationSpec('ledger.x', 's', 'e', FinancialMode::Active, ModuleOperationClass::NewIntent, ['platform'], 'k'),
            'monetary administration' => static fn () => new OperationSpec('platform.x', 's', 'e', FinancialMode::Administrative, ModuleOperationClass::HistoricalRead, [], commitKind: 'x'),
            'financial without closure' => static fn () => new OperationSpec('ledger.x', 's', 'e', FinancialMode::Active, ModuleOperationClass::NewIntent, []),
            'session control with modules' => static fn () => new OperationSpec('platform.x', 's', 'e', FinancialMode::SessionControl, ModuleOperationClass::HistoricalRead, ['platform']),
            'duplicate module' => static fn () => new OperationSpec('ledger.x', 's', 'e', FinancialMode::Active, ModuleOperationClass::NewIntent, ['platform', 'platform']),
            'invalid kind' => static fn () => new OperationSpec('Ledger', 's', 'e', FinancialMode::Active, ModuleOperationClass::NewIntent, ['platform']),
        ];
        foreach ($cases as $case => $build) {
            try {
                $build();
                self::fail($case);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_only_stored_policy_codes_become_refusal_receipts(): void
    {
        foreach (ProblemCode::cases() as $code) {
            try {
                $outcome = StoredOutcome::refused(new ProblemException($code, 'detail'), 7);
                self::assertSame('stored', $code->receipt(), $code->value);
                self::assertSame(['problem' => $code->value, 'detail' => 'detail'], $outcome->result());
                self::assertEquals($outcome, StoredOutcome::fromResult('refused', $outcome->result(), 7, false));
            } catch (InvalidArgumentException) {
                self::assertSame('none', $code->receipt(), $code->value);
            }
        }
    }
}

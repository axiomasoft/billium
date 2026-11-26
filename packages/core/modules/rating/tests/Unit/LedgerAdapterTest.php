<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Unit;

use Billium\Core\Exceptions\Modules\ModuleAdmissionRefused;
use Billium\Rating\Internal\Adapters\LedgerAdapter;
use Billium\Rating\Internal\Adapters\ObligationCommandMapper;
use PHPUnit\Framework\TestCase;

final class LedgerAdapterTest extends TestCase
{
    public function test_absent_ledger_is_reported_as_a_coded_ledger_module_refusal(): void
    {
        $adapter = new LedgerAdapter(new ObligationCommandMapper);
        self::assertFalse($adapter->available());
        try {
            $adapter->obligation('obligation-1');
            self::fail('An absent ledger answered an obligation read');
        } catch (ModuleAdmissionRefused $error) {
            self::assertSame('ledger', $error->moduleId);
            self::assertSame('module_not_installed', $error->reason);
        }
    }
}

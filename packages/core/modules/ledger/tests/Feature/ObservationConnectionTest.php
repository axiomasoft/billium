<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\Group;

/**
 * Embedded hosts keep their own default database (D45): with billium.connection different from database.default the
 * observation path reads receipts and selects drain/reconcile work on the Billium connection only. The host default
 * here points at an absent database, so any query against it would fail.
 */
#[Group('D60')]
final class ObservationConnectionTest extends ObservationTestCase
{
    public function test_receipts_and_work_selection_use_the_billium_connection_not_the_host_default(): void
    {
        $this->debt('o1', 1000);
        $this->debt('o2', 1000);
        $this->intent('intent-a', ['o1']);
        $config = $this->application()->make(Repository::class);
        $config->set('database.connections.host', [...TestEnvironment::connection(), 'database' => 'billium_host_absent']);
        $config->set('database.default', 'host');

        $receipt = $this->observe(ObservationFactory::charge('op-1', 1, 1000, sequence: 1));
        self::assertSame('observation:op-1#1', $receipt->receiptRef);
        self::assertSame('1000', self::arr($receipt->body['applied'])['captured_minor']);

        $late = ObservationFactory::charge('op-2', 1, 500, sequence: 2, fields: ['businessIntentRef' => 'intent-late']);
        $this->attempt($late);
        $this->intent('intent-late', ['o2']);
        self::assertSame(['applied'], $this->drainer()->retryParked());
        self::assertSame(500, $this->allocated('o2'));

        $this->observe(ObservationFactory::charge('op-1', 2, 1000, 300, sequence: 3));
        self::assertSame(1, $this->reconciler()->reconcile(0)['compared']);

        $databases = $this->application()->make(DatabaseManager::class);
        self::assertSame('host', $databases->getDefaultConnection());
        self::assertArrayNotHasKey('host', $databases->getConnections(), 'the host default database was never opened');
    }
}

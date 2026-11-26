<?php

declare(strict_types=1);

namespace Billium\Server\Tests\Feature;

use Billium\Collection\Contracts\DunningCaseService;
use Billium\Documents\Contracts\DocumentRenderer;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Server\Tests\Support\MinimalHost;
use BilliumMinimalHost\Host;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('server')]
final class CompositionTest extends TestCase
{
    /** @return array<string, array{string, bool, bool, bool, bool}> */
    public static function compositions(): array
    {
        return [
            'A embedded, external authority' => ['embedded-commerz', false, false, false, false],
            'A server, external authority' => ['embedded-commerz', true, false, true, false],
            'B embedded, journal only' => ['embedded-commerz', false, false, false, false],
            'B server, journal only' => ['ledger-only', true, false, true, false],
            'C embedded, Billium authority' => ['standalone-billium', false, true, false, false],
            'C embedded, execution disabled' => ['standalone-billium', false, false, false, false],
            'C server with embedded profile refused' => ['standalone-billium', true, true, false, false],
            'D server, Billium authority' => ['remote-billium', true, true, false, true],
            'D server without execution refused' => ['remote-billium', true, false, false, false],
        ];
    }

    #[DataProvider('compositions')]
    public function test_host_composition_only_exposes_enabled_delivery(string $profile, bool $server, bool $execution, bool $observations, bool $commands): void
    {
        MinimalHost::load();
        $host = new Host('composition-test');
        $app = MinimalHost::application($host, 'unused_test', $profile, $execution ? ['platform', 'ledger', 'execution'] : ['platform', 'ledger'], $server);
        foreach ([PaymentDrivers::class, DocumentRenderer::class, WriterAdmission::class] as $port) {
            $app->beforeResolving($port, static fn () => self::fail('Boot resolved an optional host port'));
        }
        $app->make(Kernel::class)->bootstrap();

        $routes = $app->make(Router::class)->getRoutes();
        self::assertSame($server, $routes->hasNamedRoute('billium.server.live'));
        self::assertSame($server, $routes->hasNamedRoute('billium.server.ready'));
        self::assertSame($server, $routes->hasNamedRoute('billium.server.ledger.query'));
        self::assertSame($observations, $routes->hasNamedRoute('billium.server.ledger.observations'));
        self::assertSame($commands, $routes->hasNamedRoute('billium.server.execution.execute'));
        self::assertSame($server && $execution, $routes->hasNamedRoute('billium.server.execution.find'));
        self::assertTrue($app->bound(LedgerQueries::class));
        self::assertSame($execution, $app->bound(AttemptWorker::class));
        self::assertFalse($app->bound(DunningCaseService::class));
        self::assertFalse($app->bound(DocumentRenderer::class));
        self::assertFalse($app->bound(WriterAdmission::class));
        self::assertSame([], $app->make(DatabaseManager::class)->getConnections());
    }
}

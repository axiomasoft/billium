<?php

declare(strict_types=1);

namespace Billium\Server\Tests\Integration;

use Billium\Conformance\Http\LoopbackHost;
use Billium\Core\Exceptions\Modules\ModuleAdmissionRefused;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use BilliumMinimalHost\Database;
use Closure;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('pg')]
#[Group('server')]
final class AdmissionDrainRaceTest extends TestCase
{
    public function test_admitted_request_commits_while_drain_waits_and_only_the_original_can_replay(): void
    {
        $this->withStand(static function (LoopbackHost $host, Application $app, string $directory): void {
            $results = ConcurrencyStand::run(AdmissionDrainScenario::class, 4, ['directory' => $directory]);
            [$admitted, $drain, $refused, $replay] = array_column($results, 'ok');
            self::assertSame(202, $admitted['status']);
            self::assertTrue($drain['waited']);
            self::assertSame(409, $refused['status']);
            self::assertSame('billium.module.unavailable', $refused['body']['code']);
            self::assertSame('none', $refused['body']['receipt']);
            self::assertSame($admitted, $replay);

            $snapshot = $host->snapshot($app);
            self::assertSame('draining', $snapshot['modules']['execution']);
            self::assertSame(['root-a'], array_column($snapshot['operations'], 'operation_ref'));
            self::assertSame(['root-a'], array_column($snapshot['attempts'], 'operation_ref'));
            self::assertSame(1, count(array_filter($snapshot['receipts'], static fn (object $receipt): bool => $receipt->command_kind === 'payment.execute.v1')));
            self::assertSame(['calls' => [], 'lookups' => []], $host->providerEvidence());
            try {
                $host->worker($app, 'root-a', 'dispatch', 'success');
                self::fail('Drain must also close the first provider send');
            } catch (ModuleAdmissionRefused $refusal) {
                self::assertSame('draining_closes_new_intent', $refusal->reason);
            }
            self::assertSame(['calls' => [], 'lookups' => []], $host->providerEvidence());
        });
    }

    public function test_request_waiting_on_drain_leaves_no_receipt_or_effect(): void
    {
        $this->withStand(static function (LoopbackHost $host, Application $app, string $directory): void {
            $before = $host->snapshot($app);
            $results = ConcurrencyStand::run(AdmissionDrainScenario::class, 2, ['directory' => $directory, 'drainFirst' => true]);
            [$drain, $refused] = array_column($results, 'ok');
            self::assertTrue($drain['waited']);
            self::assertSame(409, $refused['status']);
            self::assertSame('billium.module.unavailable', $refused['body']['code']);
            $after = $host->snapshot($app);
            self::assertSame([], $after['operations']);
            self::assertSame([], $after['attempts']);
            self::assertEquals($before['receipts'], array_values(array_filter($after['receipts'],
                static fn (object $receipt): bool => $receipt->command_kind !== 'platform.module.transition')));
            self::assertEquals($before['events'], $after['events']);
            self::assertSame($before['counts']['money_commit_log'], $after['counts']['money_commit_log']);
            self::assertSame(['calls' => [], 'lookups' => []], $host->providerEvidence());
        });
    }

    /** @param Closure(LoopbackHost,Application,string):void $proof */
    private function withStand(Closure $proof): void
    {
        require_once dirname(__DIR__, 4).'/conformance/http/bootstrap.php';
        $directory = dirname(__DIR__, 4).'/build/http-race-'.bin2hex(random_bytes(8));
        mkdir($directory.'/bootstrap/cache', 0700, true);
        touch($directory.'/.env');
        $database = Database::create();
        $previous = getenv('DB_DATABASE');
        $previousEnv = $_ENV['DB_DATABASE'] ?? null;
        $previousServer = $_SERVER['DB_DATABASE'] ?? null;
        try {
            putenv('DB_DATABASE='.$database);
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;
            $host = new LoopbackHost($directory, 'execution');
            $app = $host->application($database);
            $host->install($app);

            $proof($host, $app, $directory);
        } finally {
            putenv($previous === false ? 'DB_DATABASE' : 'DB_DATABASE='.$previous);
            unset($_ENV['DB_DATABASE'], $_SERVER['DB_DATABASE']);
            if ($previousEnv !== null) {
                $_ENV['DB_DATABASE'] = $previousEnv;
            }
            if ($previousServer !== null) {
                $_SERVER['DB_DATABASE'] = $previousServer;
            }
            Database::drop($database);
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }
}

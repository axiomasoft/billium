<?php

declare(strict_types=1);

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Contracts\CallerContext;
use Billium\Core\Contracts\FinancialSessionAdmission;
use Billium\Core\Data\Caller;
use Billium\Core\Tests\Support\Concurrency\WorkerApplication;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\Allocate;
use Billium\Ledger\Data\BillingAccountScope;
use Illuminate\Database\DatabaseManager;

require dirname(__DIR__, 5).'/vendor/autoload.php';

try {
    $mode = $argv[1];
    $owner = TestEnvironment::pdo(owner: true);
    if ($mode === 'backfill') {
        $owner->exec("SET application_name = 'billium-rolling-backfill'");
        // Commit one batch; kill the next batch while it holds uncommitted writes.
        $owner->exec("UPDATE billium_allocation SET drill_note='backfilled' WHERE id=(SELECT min(id) FROM billium_allocation WHERE drill_note IS NULL)");
        $owner->beginTransaction();
        $owner->exec("UPDATE billium_allocation SET drill_note='interrupted' WHERE drill_note IS NULL");
        $owner->query('SELECT pg_sleep(30)');
        $owner->commit();
        throw new RuntimeException('backfill was not interrupted');
    }

    $index = (int) $argv[2];
    $app = WorkerApplication::create();
    $app->instance(CallerContext::class, new FixedCallerContext(new Caller('seller-a', 'test', 'inst-test', 'rolling-'.$index)));
    $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token('inst-test', processRef: 'rolling-'.$index)));
    $ledger = $app->make(Ledger::class);
    if ($mode === 'new') {
        // Test-only trigger copies this new optional field during the same guarded allocation INSERT.
        $app->make(DatabaseManager::class)->connection('pgsql')->unprepared("SET billium.drill_note = 'new-generation'");
    }
    $successes = [];
    $refusals = 0;
    $iteration = 0;
    $deadline = microtime(true) + 30;
    $phase = $owner->prepare('SELECT phase FROM billium_drill_workers WHERE id=100');
    do {
        $phase->execute();
        $stage = $phase->fetchColumn();
        // Keep legacy commands live during DDL, and reserve three successes per old worker for after expand.
        $target = $mode === 'old' ? $index + ($stage === 'running' ? 0 : 4 * (count($successes) % 4)) : 16 + $index + 2 * $iteration;
        $ref = 'drill-'.$mode.'-'.$index.'-'.$iteration;
        $result = $ledger->allocate(new Allocate($ref, new BillingAccountScope('seller-a', 'test', 'account-a'), $ref, 'rolling-'.$target, Money::parse('10.00', 'RUB'), 'payment-a'));
        if ($result->accepted) {
            $successes[] = $ref;
        } elseif (in_array($result->problem, [ProblemCode::AllocationExceedsOpen, ProblemCode::AllocationExceedsAvailable], true)) {
            $refusals++;
        } else {
            throw new RuntimeException('unexpected business refusal: '.$result->problem?->value);
        }
        if ($iteration === 0) {
            $ready = $owner->prepare("INSERT INTO billium_drill_workers(id,phase) VALUES (?, 'ready')");
            $ready->execute([$mode === 'old' ? $index : $index + 4]);
        }
        $iteration++;
        if ($mode === 'new' && $iteration === 2) {
            break;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('old generation was not stopped');
        }
        usleep(10_000);
        $phase->execute();
    } while ($phase->fetchColumn() !== 'stop');
    fwrite(STDOUT, json_encode(['successes' => $successes, 'refusals' => $refusals], JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage());
    exit(1);
}

<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Migration;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\RecoveryCoverage;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\Support\Concurrency\WorkerApplication;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Platform\Data\Migration\SourceFreezeCertificate;
use Billium\Platform\Exceptions\TransitionRefused;
use Billium\Platform\Tests\PlatformTestCase;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\ProcessBarrier;
use Billium\Testkit\Restore\PostgresSnapshot;
use Billium\Testkit\Restore\RestoreSessionAuthority;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Symfony\Component\Process\Process;

#[Group('pg')]
#[Group('P9.7')]
final class CounterIndependentRecoveryTest extends PlatformTestCase
{
    public function test_mgate_01_old_workers_still_alive_after_freeze_cannot_mutate(): void
    {
        $this->activeSession();
        $path = tempnam(sys_get_temp_dir(), 'billium-mgate-01-');
        self::assertIsString($path);
        $barrier = new ProcessBarrier(new DurableJournal($path));
        $workers = [];
        try {
            foreach (['api', 'cron'] as $channel) {
                $process = self::worker('attemptAfterFreeze', $path, $channel);
                $process->start();
                $workers[$channel] = $process;
            }
            $barrier->await('old-worker-ready', 2, 15_000);
            self::assertSame(['before-api', 'before-cron'], $this->facts($this->application()));
            $this->authority->issueTransition('sealing', 'close-source-ingress', [1]);
            self::assertSame('sealing', $this->installation()->beginSealing('close-source-ingress', 1)->state);
            $this->authority->issueTransition('seal', 'freeze-source', [1]);
            self::assertSame('sealed', $this->installation()->seal('freeze-source', 1)->state);
            $barrier->arrive('frozen-at-source');
            $denied = [];
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $denied = [...$denied, ...self::arr(json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR))];
            }
            sort($denied);
            self::assertSame(SourceFreezeCertificate::MUTATORS, $denied);
            self::assertSame(['before-api', 'before-cron'], $this->facts($this->application()));
            self::assertSame(2, $this->rows('billium_money_commit_log'));
        } finally {
            foreach ($workers as $worker) {
                $worker->stop(0);
            }
            unlink($path);
        }
    }

    #[TestWith(['lower'], 'lower')]
    #[TestWith(['equal'], 'equal')]
    #[TestWith(['greater'], 'greater')]
    public function test_mgate_06_diagnostic_counter_cannot_authorize_a_restored_attachment(string $variant): void
    {
        $this->activeSession();
        $source = TestEnvironment::databaseName();
        $driver = getenv('DB_CONNECTION');
        $base = tempnam(sys_get_temp_dir(), 'billium-mgate-06-');
        self::assertIsString($base);
        $snapshot = $worker = $clone = null;
        try {
            self::write($this->application(), 'snapshot');
            $barrier = new ProcessBarrier(new DurableJournal($base.'.barrier'));
            $worker = self::worker('delayedCommit', $base.'.barrier');
            $worker->start();
            try {
                $barrier->await('counter-assigned', 1, 15_000);
            } catch (RuntimeException $error) {
                throw new RuntimeException($error->getMessage().': '.$worker->getErrorOutput(), previous: $error);
            }
            if ($variant === 'lower') {
                $snapshot = new PostgresSnapshot(TestEnvironment::pdo(owner: true), $source, $base.'.dump');
            }
            self::write($this->application(), 'committed-first');
            self::assertSame(['committed-first', 'snapshot'], $this->facts($this->application()));
            $snapshot ??= new PostgresSnapshot(TestEnvironment::pdo(owner: true), $source, $base.'.dump');
            $barrier->arrive('commit-allowed');
            self::assertSame(0, $worker->wait());
            $worker = null;
            $this->authority->issueTransition('seal', 'release-source', [1]);
            self::assertSame('sealed', $this->installation()->seal('release-source', 1)->state);
            $expected = $this->facts($this->application());
            self::assertSame(['committed-first', 'delayed', 'snapshot'], $expected);
            $first = $this->value("SELECT commit_no FROM billium_money_commit_log WHERE kind = 'ledger.committed_first'");
            $delayed = $this->value("SELECT commit_no FROM billium_money_commit_log WHERE kind = 'ledger.delayed'");
            self::assertGreaterThan(self::integer($delayed), self::integer($first), 'A reserved n, B committed n+1 before the dump, then A committed n');
            $sourceCounter = self::integer($this->value('SELECT max(commit_no) FROM billium_money_commit_log'));
            $copy = $snapshot->restore();
            self::selectDatabase($copy);
            self::setEnvironment('DB_CONNECTION', 'pgsql');
            $clone = WorkerApplication::create();
            $clone->instance(CallerContext::class, FixedCallerContext::default(installationRef: self::INSTALLATION));
            $tokens = new FixedSessionAdmission;
            $clone->instance(FinancialSessionAdmission::class, $tokens);
            $authority = SimulatedFinancialAuthorityEvidence::install($clone);
            $db = $clone->make(DatabaseManager::class)->connection();
            self::assertSame($copy, $db->scalar('SELECT current_database()'));
            self::assertSame($variant === 'lower' ? ['snapshot'] : ['committed-first', 'snapshot'], $this->facts($clone));
            if ($variant === 'greater') {
                $db->insert("INSERT INTO billium_money_commit_log (kind, session_epoch) VALUES ('diagnostic.only', 1)");
                self::assertSame(['committed-first', 'snapshot'], $this->facts($clone));
            }
            $copyCounter = self::integer($db->scalar('SELECT max(commit_no) FROM billium_money_commit_log'));
            self::assertSame(match ($variant) {
                'lower' => -1,
                'equal' => 0,
                'greater' => 1,
                default => self::fail('Unknown counter case '.$variant),
            }, $copyCounter <=> $sourceCounter);

            $installation = $clone->make(Installation::class);
            self::assertSame('active', $installation->currentSession(self::INSTALLATION)?->state);
            $this->assertWritesRefused($clone, ProblemCode::InstallationQuarantined);
            $copiedToken = FixedSessionAdmission::token(self::INSTALLATION, attachmentGeneration: 2);
            $tokens->set($copiedToken);
            $authority->issueProcess($copiedToken);
            $this->assertWritesRefused($clone, ProblemCode::RecoveryRequired);

            $key = getenv('BILLIUM_SIMULATED_AUTHORITY_KEY');
            self::assertIsString($key);
            $journal = new DurableJournal($base.'.authority');
            $external = new RestoreSessionAuthority($authority, $journal, $key);
            $request = ['restored-owner', 2, 2, 2, 'verified-source-retirement'];
            $open = $authority->issueTransition('open', 'restore-open', $request);
            $retirement = $open->retirementProof;
            self::assertNotNull($retirement);
            $external->adopt($open, $retirement);
            $clone->instance(FinancialAuthorityEvidence::class, $external);
            self::assertSame('recovery', $installation->openSession('restore-open', ...$request)->state);
            $token = FixedSessionAdmission::token(self::INSTALLATION, 'restored-owner', 2, 2, 2, modes: [FinancialMode::Active, FinancialMode::Recovery]);
            $tokens->set($token);
            $authority->issueProcess($token);
            $this->assertWritesRefused($clone, ProblemCode::RecoveryRequired);

            $args = [2, 'restored-certificate', 'attachment-2', 'verified-reconciliation'];
            $authorization = $authority->issueTransition('activate', 'restore-activate', $args);
            $activate = static fn () => $installation->activateSession('restore-activate', ...$args);
            try {
                $activate();
                self::fail('Local recovery and an unverified certificate reference cannot activate');
            } catch (TransitionRefused $refused) {
                self::assertSame('verified_authority_required', $refused->reason);
            }
            self::assertSame('recovery', $installation->currentSession(self::INSTALLATION)?->state);
            self::assertNotSame($expected, $this->facts($clone), 'The diagnostic counter cannot fill the missing tail');

            // The sealed source inventory is held by the fixture host outside the restored DB.
            $codec = new Codec;
            $sourceDigest = $codec->digest($expected);
            $tail = array_diff($expected, $this->facts($clone));
            foreach ([...$tail, ...$tail] as $ref) {
                self::write($clone, $ref, FinancialMode::Recovery);
            }
            self::assertSame($sourceDigest, $codec->digest($this->facts($clone)));
            $this->assertWritesRefused($clone, ProblemCode::RecoveryRequired);
            $certificate = $authorization->activationCertificate;
            self::assertNotNull($certificate);
            $verified = $certificate->coverage;
            $coverage = new RecoveryCoverage('fixture-snapshot:'.$snapshot->sha256, $verified->recoveryHorizon,
                $verified->perAccountWatermarks, $verified->dispatchManifestDigest, $sourceDigest,
                $verified->tombstoneDigest, ['reconciled:'.$sourceDigest], $verified->downstreamValidationReceipts,
                $verified->unresolved, $verified->namespaceStates);
            $external->adopt($authorization, $retirement, $coverage);
            self::assertSame('active', $activate()->state);
            self::write($clone, 'after-activation');
            self::assertSame(['after-activation', ...$expected], $this->facts($clone));
            fwrite(STDOUT, json_encode(['gate' => 'MGATE-06', 'variant' => $variant, 'sourceCounter' => $sourceCounter,
                'restoredCounter' => $copyCounter, 'snapshotSha256' => $snapshot->sha256, 'sourceInventoryDigest' => $sourceDigest], JSON_THROW_ON_ERROR).PHP_EOL);
        } finally {
            self::selectDatabase($source);
            self::setEnvironment('DB_CONNECTION', $driver === false ? null : $driver);
            $worker?->stop(0);
            $clone?->make(DatabaseManager::class)->disconnect();
            $snapshot?->close();
            $files = glob($base.'*');
            foreach ($files === false ? [] : $files as $file) {
                unlink($file);
            }
        }
    }

    public static function delayedCommit(string $path): void
    {
        $app = self::workerApplication('delayed-worker');
        $barrier = new ProcessBarrier(new DurableJournal($path));
        $db = $app->make(DatabaseManager::class)->connection();
        $db->listen(static function (QueryExecuted $query) use ($barrier): void {
            if (str_starts_with($query->sql, 'INSERT INTO public.billium_money_commit_log ')) {
                $barrier->arrive('counter-assigned');
                $barrier->await('commit-allowed', 1, 40_000);
            }
        });
        self::write($app, 'delayed');
    }

    public static function attemptAfterFreeze(string $path, string $channel): void
    {
        $app = self::workerApplication($channel.'-worker');
        $barrier = new ProcessBarrier(new DurableJournal($path));
        self::write($app, 'before-'.$channel);
        $barrier->arrive('old-worker-ready');
        $barrier->await('frozen-at-source', 1, 20_000);
        $routes = $channel === 'api' ? ['admin', 'api', 'import', 'ui'] : ['background', 'collection', 'cron', 'direct'];
        $denied = [];
        foreach ($routes as $route) {
            try {
                self::write($app, 'stale-'.$route);
                self::fail('Old worker admitted after freeze for '.$route);
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::RecoveryRequired, $error->problem);
                $denied[] = $route;
            }
        }
        fwrite(STDOUT, json_encode($denied, JSON_THROW_ON_ERROR));
    }

    private static function worker(string $method, string ...$arguments): Process
    {
        return new Process([PHP_BINARY, '-r', 'require "vendor/autoload.php"; '.self::class.'::'.$method.'(...array_slice($argv, 1));', ...$arguments],
            env: ['DB_CONNECTION' => 'pgsql',
                'BILLIUM_SIMULATED_AUTHORITY_KEY' => (string) getenv('BILLIUM_SIMULATED_AUTHORITY_KEY'),
                'BILLIUM_SIMULATED_AUTHORITY_JOURNAL' => (string) getenv('BILLIUM_SIMULATED_AUTHORITY_JOURNAL')]);
    }

    private static function workerApplication(string $process): Application
    {
        $app = WorkerApplication::create();
        $app->instance(CallerContext::class, FixedCallerContext::default(installationRef: self::INSTALLATION));
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token(self::INSTALLATION, processRef: $process)));
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);

        return $app;
    }

    private static function write(Application $app, string $ref, FinancialMode $mode = FinancialMode::Active): void
    {
        $spec = new OperationSpec('ledger.drill', 'seller-a', 'test', $mode,
            $mode === FinancialMode::Recovery ? ModuleOperationClass::UnknownResolution : ModuleOperationClass::NewIntent,
            ['ledger', 'platform'], commandKey: $mode === FinancialMode::Recovery ? 'tail-'.$ref : null,
            digest: $mode === FinancialMode::Recovery ? (new Codec)->digest(['ref' => $ref]) : null,
            commitKind: 'ledger.'.str_replace('-', '_', $ref));
        $app->make(OperationRunner::class)->run($spec, static function (TransactionContext $ctx) use ($app, $ref): PlatformReceipt {
            $app->make(DatabaseManager::class)->connection($ctx->connectionName())->insert(
                'INSERT INTO billium_test_effect (seller_ref, environment, ref) VALUES (?, ?, ?)', ['seller-a', 'test', $ref],
            );

            return self::receipt();
        })->orThrow();
    }

    private function assertWritesRefused(Application $app, ProblemCode $reason): void
    {
        $before = $this->facts($app);
        try {
            self::write($app, 'forbidden');
            self::fail('Restored attachment admitted a monetary root without activation');
        } catch (ProblemException $refused) {
            self::assertSame($reason, $refused->problem);
        }
        self::assertSame($before, $this->facts($app));
    }

    /** @return list<string> */
    private function facts(Application $app): array
    {
        return array_values($app->make(DatabaseManager::class)->connection()->table('billium_test_effect')->orderBy('ref')->pluck('ref')->map(self::text(...))->all());
    }

    private static function selectDatabase(string $database): void
    {
        self::setEnvironment('DB_DATABASE', $database);
    }

    private static function setEnvironment(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            return;
        }
        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }
}

<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Concurrency;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AuthorizationCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\OriginDecision;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\AuthorizationLifecycleTest as Authorization;
use Billium\Execution\Tests\Feature\CallbackCustodyTest;
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessBarrier;
use Billium\Testkit\Fixtures\ProcessIdentity;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class CaptureVoidCapacityTest extends ExecutionTestCase
{
    private static function child(int $index, string $path, string $race): ChildProcess
    {
        return new ChildProcess([PHP_BINARY, '-r',
            'require "vendor/autoload.php"; Billium\Execution\Tests\Concurrency\CaptureVoidCapacityTest::worker((int)$argv[1], $argv[2], $argv[3]);',
            (string) $index, $path, $race]);
    }

    public static function worker(int $index, string $path, string $race): void
    {
        $app = AdmissionIdentityTest::workerApp($index);
        $scope = AdmissionIdentityTest::scope();
        AdmissionIdentityTest::host($app, $path);
        $control = new DurableJournal($path.'.control');
        $barrier = new ProcessBarrier($control);
        $backend = self::integer($app->make(DatabaseManager::class)->connection()->scalar('SELECT pg_backend_pid()'));
        $barrier->arrive('ready');
        $barrier->await('release', 1, 45_000);
        $ref = 'child-'.$index;
        try {
            if (in_array($race, ['transfer', 'expiry'], true)) {
                Authorization::dispatch($app, $ref);
            } else {
                $terms = $race === 'unrelated' ? ['originalOperationRef' => 'root-b'] : [];
                $app->make(AuthorizationCommands::class)->admit($scope, Authorization::child($ref, $race === 'all-fit' ? 5 : 50, $index % 2 !== 0, $terms))->orThrow();
            }
            $result = 'accepted';
        } catch (ProblemException $problem) {
            $result = $problem->problem->value;
        }
        $control->append(new JournalEvent('result', ['ref' => $ref, 'backend' => $backend, 'result' => $result], ProcessIdentity::current()));
    }

    /** @return list<JournalEvent> */
    private static function results(DurableJournal $journal): array
    {
        return array_values(array_filter($journal->records(), static fn (object $event): bool => $event instanceof JournalEvent && $event->kind === 'result'));
    }

    /** @return list<array{string,int,int}> */
    public static function amounts(): array
    {
        return [['capacity', 50, 2], ['all-fit', 5, 20]];
    }

    #[DataProvider('amounts')]
    public function test_twenty_capture_and_provider_void_commands_reserve_exactly_one_hundred_and_send_each_winner_once(string $race, int $amount, int $expected): void
    {
        $app = $this->application();
        $path = AdmissionIdentityTest::tempPath();
        $clock = new FixtureClock(new DateTimeImmutable);
        try {
            Authorization::authorize($app, $path, clock: $clock);
            $control = new DurableJournal($path.'.control');
            $barrier = new ProcessBarrier($control);
            $workers = [];
            for ($i = 0; $i < 20; $i++) {
                $workers[] = self::child($i, $path, $race);
            }
            $barrier->await('ready', 20, 45_000);
            $barrier->arrive('release');
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait());
            }
            $results = self::results($control);
            self::assertCount(20, $results);
            self::assertCount(20, array_unique(array_map(static fn (JournalEvent $e): int => $e->pid, $results)));
            self::assertCount(20, array_unique(array_map(static fn (JournalEvent $e): mixed => $e->data['backend'], $results)));
            $winners = [];
            foreach ($results as $result) {
                if ($result->data['result'] === 'accepted') {
                    $winners[] = self::text($result->data['ref']);
                } else {
                    self::assertContains($result->data['result'], [ProblemCode::AuthorizationCapacityExceeded->value, ProblemCode::CaptureCapacityExceeded->value]);
                }
            }
            self::assertCount($expected, $winners);
            self::assertSame($expected, $this->rows('billium_execution_child_identity'));
            self::assertSame($expected, $this->rows('billium_execution_reservation', "state='held' AND requested_minor=?", [$amount]));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'held_capture_minor+held_void_minor=100'));
            foreach ($winners as $ref) {
                $clock->now = new DateTimeImmutable;
                Authorization::dispatchAndReduce($app, $ref);
                Authorization::dispatch($app, $ref);
            }
            $records = array_values(array_filter(new DurableJournal($path)->records(), static fn (object $e): bool => $e instanceof ProviderRecord && $e->kind !== EffectKind::Authorize));
            self::assertCount($expected, $records);
            self::assertEqualsCanonicalizing($winners, array_map(static fn (ProviderRecord $r): string => $r->request->permit->operationRef, $records));
            self::assertSame(100, array_sum(array_map(static fn (ProviderRecord $r): int => $r->request->amount->minor, $records)));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor+released_authorization_minor=100 AND held_capture_minor+held_void_minor=0 AND NOT financial_hold'));
            self::assertSame($expected + 1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            self::clean($path);
        }
    }

    /** @return list<array{string}> */
    public static function closures(): array
    {
        return [['expiry'], ['transfer']];
    }

    #[DataProvider('closures')]
    public function test_independent_worker_with_admitted_capture_cannot_send_after_expiry_or_owner_transfer(string $race): void
    {
        $app = $this->application();
        $path = AdmissionIdentityTest::tempPath();
        try {
            Authorization::authorize($app, $path);
            $app->make(AuthorizationCommands::class)->admit(AdmissionIdentityTest::scope(), Authorization::child('child-4', 50))->orThrow();
            $control = new DurableJournal($path.'.control');
            $barrier = new ProcessBarrier($control);
            $child = self::child(4, $path, $race);
            $barrier->await('ready', 1, 45_000);
            if ($race === 'transfer') {
                $this->authority->issueTransition('seal', 'transfer-seal', [1]);
                $this->installation()->seal('transfer-seal', 1);
                $this->authority->issueTransition('open', 'transfer-open', ['owner-2', 2, 2, 2, 'retired-owner-1']);
                self::assertSame('recovery', $this->installation()->openSession('transfer-open', 'owner-2', 2, 2, 2, 'retired-owner-1')->state);
            } else {
                $expiry = CallbackCustodyTest::signed(Authorization::evidence($app, 'root-a'), ['revision' => '3', 'authorizationExpiresAt' => '2020-01-01T00:00:00Z']);
                self::assertSame(ReductionDisposition::Applied, Authorization::receive($app, 'root-a', $expiry)->disposition);
            }
            $barrier->arrive('release');
            self::assertSame(0, $child->wait());
            $results = self::results($control);
            self::assertCount(1, $results);
            self::assertNotSame($this->value('SELECT pg_backend_pid()'), $results[0]->data['backend']);
            $expected = $race === 'transfer' ? ProblemCode::RecoveryRequired : ProblemCode::AuthorizationCapacityExceeded;
            self::assertSame($expected->value, $results[0]->data['result']);
            self::assertSame(1, $this->rows('billium_execution_capacity', 'held_capture_minor=50 AND confirmed_capture_minor=0'));
            self::assertSame(1, $this->rows('billium_execution_attempt', "operation_ref='child-4' AND started_at IS NULL"));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            self::clean($path);
        }
    }

    public function test_independent_root_admission_completes_while_another_root_lock_is_held(): void
    {
        $app = $this->application();
        $path = AdmissionIdentityTest::tempPath();
        try {
            Authorization::authorize($app, $path);
            $scope = AdmissionIdentityTest::scope();
            $origin = new OriginDecision($scope, 'root-b', 'intent-b', 'checkout', 'remote-billium', 1, null, 'sha256:'.str_repeat('a', 64), 'terms-v1', 'period-b');
            AdmissionIdentityTest::host($app, $path, [$origin]);
            $app->make(RootPaymentCommands::class)->admit($scope, AdmissionIdentityTest::wire(['schema' => 'payment.authorize.v1',
                'operationRef' => 'root-b', 'commandKey' => AdmissionIdentityTest::key(505), 'source' => ['context' => 'checkout', 'intentRef' => 'intent-b']]))->orThrow();
            Authorization::dispatchAndReduce($app, 'root-b');
            $control = new DurableJournal($path.'.control');
            $barrier = new ProcessBarrier($control);
            $child = self::child(8, $path, 'unrelated');
            $barrier->await('ready', 1, 45_000);
            $this->owner()->transaction(function () use ($barrier, $child): void {
                $this->owner()->selectOne("SELECT id FROM billium_execution_operation WHERE operation_ref='root-a' FOR UPDATE");
                $barrier->arrive('release');
                self::assertSame(0, $child->wait());
            });
            self::assertSame('accepted', self::results($control)[0]->data['result']);
            self::assertSame(1, $this->rows('billium_execution_capacity', "root_operation_ref='root-b' AND held_capture_minor=50"));
            self::assertSame(1, $this->rows('billium_execution_capacity', "root_operation_ref='root-a' AND held_capture_minor=0"));
        } finally {
            self::clean($path);
        }
    }

    private static function clean(string $path): void
    {
        UnknownResultTest::clean($path);
        if (is_file($path.'.control')) {
            unlink($path.'.control');
        }
    }
}

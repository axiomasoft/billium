<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\AuthorizationCommands;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\ExecutionOriginResolver;
use Billium\Execution\Contracts\ExecutionReadiness;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Testkit\Fixtures\DurableJournal;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class ExecutionReadinessTest extends ExecutionTestCase
{
    /** @return list<array{string}> */
    public static function missingInputs(): array
    {
        return [['disabled'], ['release'], ['retention'], ['verifier'], ['rejected']];
    }

    private function disable(string $input): void
    {
        $app = $this->application();
        if (in_array($input, ['disabled', 'release', 'retention'], true)) {
            $key = match ($input) {
                'disabled' => 'outgoing_enabled', 'release' => 'release_evidence_ref', default => 'retention_policy_ref',
            };
            $app['config']->set('billium.execution.'.$key, $input === 'disabled' ? false : null);
            $raw = $app['config']->get('billium');
            self::assertIsArray($raw);
            $app->instance(BilliumOptions::class, BilliumOptions::fromArray($raw));
        } elseif ($input === 'verifier') {
            $app->offsetUnset(ExecutionReadiness::class);
        } else {
            $app->instance(ExecutionReadiness::class, new readonly class implements ExecutionReadiness
            {
                public function assertConfigured(string $releaseEvidenceRef, string $retentionPolicyRef): void
                {
                    throw new ProblemException(ProblemCode::RecoveryRequired, 'Independent simulated startup dossier rejected');
                }

                public function assertReady(FrozenBinding $binding, EffectKind $kind, Money $amount, string $releaseEvidenceRef, string $retentionPolicyRef): void
                {
                    throw new ProblemException(ProblemCode::RecoveryRequired, 'Independent simulated dossier rejected');
                }
            });
        }
    }

    #[DataProvider('missingInputs')]
    public function test_new_root_has_no_local_or_provider_effect_without_verified_dossiers(string $input): void
    {
        $app = $this->application();
        Fixture::seedMerchant($app);
        $path = Fixture::tempPath();
        try {
            Fixture::host($app, $path);
            $this->disable($input);
            AuthorizationLifecycleTest::refuses(ProblemCode::RecoveryRequired,
                static fn () => $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow());
            foreach (['operation', 'attempt', 'dispatch_work', 'business_binding'] as $table) {
                self::assertSame(0, $this->rows('billium_execution_'.$table));
            }
            self::assertSame([], new DurableJournal($path)->records());
        } finally {
            $this->cleanup($path);
        }
    }

    public function test_admitted_root_replay_uses_retained_input_without_resolving_missing_host_services(): void
    {
        $app = $this->application();
        Fixture::seedMerchant($app);
        $path = Fixture::tempPath();
        try {
            Fixture::host($app, $path);
            $original = $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
            $this->disable('release');
            foreach ([ExecutionOriginResolver::class, AdapterQualification::class, MerchantCredentials::class,
                WriterAdmission::class, DispatchEgress::class, PaymentDrivers::class] as $port) {
                $app->offsetUnset($port);
                $app->bind($port, static fn (): never => self::fail('Receipt replay resolved '.$port));
            }
            $replay = $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
            self::assertSame($original->receiptId, $replay->receiptId);
            self::assertSame($original->body, $replay->body);
            AuthorizationLifecycleTest::refuses(ProblemCode::IdempotencyConflict, static fn () => $app->make(RootPaymentCommands::class)
                ->admit(Fixture::scope(), Fixture::wire(['money' => ['amount' => '2.00', 'currency' => 'RUB']]))->orThrow());
            self::assertSame(1, $this->rows('billium_execution_operation'));
            self::assertSame(1, $this->rows('billium_execution_admission_conflict'));
            self::assertSame([], new DurableJournal($path)->records());
        } finally {
            $this->cleanup($path);
        }
    }

    public function test_dispatch_rechecks_dossier_after_admission_and_preserves_unsent_attempt(): void
    {
        $app = $this->application();
        Fixture::seedMerchant($app);
        $path = Fixture::tempPath();
        try {
            Fixture::host($app, $path);
            $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
            $this->disable('rejected');
            $attempt = PaymentIdentity::reference(Fixture::scope(), 'root-a', 'attempt');
            AuthorizationLifecycleTest::refuses(ProblemCode::RecoveryRequired,
                static fn () => $app->make(AttemptWorker::class)->dispatch(Fixture::scope(), $attempt, 'worker', new DateTimeImmutable('+30 seconds'))->orThrow());
            self::assertSame(AttemptState::Admitted, $app->make(ExecutionStore::class)->attempt(Fixture::scope(), $attempt)?->state);
            self::assertSame([], new DurableJournal($path)->records());
        } finally {
            $this->cleanup($path);
        }
    }

    public function test_child_admission_rechecks_dossier_without_reserving_capacity(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            AuthorizationLifecycleTest::authorize($app, $path);
            $before = $this->rows('billium_execution_reservation');
            $this->disable('retention');
            AuthorizationLifecycleTest::refuses(ProblemCode::RecoveryRequired,
                static fn () => $app->make(AuthorizationCommands::class)->admit(Fixture::scope(), AuthorizationLifecycleTest::child('capture-a', 30))->orThrow());
            self::assertSame($before, $this->rows('billium_execution_reservation'));
            self::assertNull($app->make(ExecutionStore::class)->operation(Fixture::scope(), 'capture-a'));
            self::assertSame(1, $this->rows('billium_execution_attempt'));
        } finally {
            $this->cleanup($path);
        }
    }

    public function test_stored_capacity_refusal_replays_after_host_health_is_lost_and_changed_input_conflicts(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            AuthorizationLifecycleTest::authorize($app, $path);
            $command = AuthorizationLifecycleTest::child('capture-too-large', 101);
            $original = $app->make(AuthorizationCommands::class)->admit(Fixture::scope(), $command);
            self::assertFalse($original->accepted);
            self::assertSame(ProblemCode::CaptureCapacityExceeded, $original->problem);
            self::assertNotNull($original->receiptId);
            $this->disable('release');
            foreach ([AdapterQualification::class, MerchantCredentials::class, PaymentDrivers::class] as $port) {
                $app->offsetUnset($port);
                $app->bind($port, static fn (): never => self::fail('Refusal replay resolved '.$port));
            }
            $replay = $app->make(AuthorizationCommands::class)->admit(Fixture::scope(), $command);
            self::assertSame($original->receiptId, $replay->receiptId);
            self::assertSame($original->result(), $replay->result());
            self::assertTrue($replay->replayed);
            AuthorizationLifecycleTest::refuses(ProblemCode::IdempotencyConflict,
                static fn () => $app->make(AuthorizationCommands::class)->admit(Fixture::scope(),
                    AuthorizationLifecycleTest::child('capture-too-large', 100))->orThrow());
            self::assertSame(1, $this->rows('billium_execution_operation'));
            self::assertSame(0, $this->rows('billium_execution_reservation'));
        } finally {
            $this->cleanup($path);
        }
    }

    /** @return list<array{class-string,ProblemCode}> */
    public static function missingSendPorts(): array
    {
        return [[WriterAdmission::class, ProblemCode::RecoveryRequired], [DispatchEgress::class, ProblemCode::RecoveryRequired],
            [MerchantCredentials::class, ProblemCode::LookupUnavailable]];
    }

    #[DataProvider('missingSendPorts')]
    public function test_missing_send_port_refuses_with_typed_problem_and_zero_provider_calls(string $port, ProblemCode $expected): void
    {
        $app = $this->application();
        Fixture::seedMerchant($app);
        $path = Fixture::tempPath();
        try {
            Fixture::host($app, $path);
            $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
            $app->offsetUnset($port);
            $attempt = PaymentIdentity::reference(Fixture::scope(), 'root-a', 'attempt');
            AuthorizationLifecycleTest::refuses($expected,
                static fn () => $app->make(AttemptWorker::class)->dispatch(Fixture::scope(), $attempt, 'worker', new DateTimeImmutable('+30 seconds'))->orThrow());
            self::assertSame(AttemptState::Admitted, $app->make(ExecutionStore::class)->attempt(Fixture::scope(), $attempt)?->state);
            self::assertSame([], new DurableJournal($path)->records());
        } finally {
            $this->cleanup($path);
        }
    }

    private function cleanup(string $path): void
    {
        foreach ([$path, $path.'.host'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}

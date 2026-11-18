<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Support;

use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\PaymentDriver;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Data\DispatchRequest;
use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\LookupRequest;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ProviderLookupState;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\CertifiedNoEffectHandoffTest;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Drivers\SimulatedAbsenceVerifier;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;

trait CertifiesAbsentRefund
{
    private function certifyAbsentAndAdoptNewSession(string $path): void
    {
        $app = $this->application();
        CertifiedNoEffectHandoffTest::register($app);
        $scope = Fixture::scope();
        $cap = SimulatedCapabilities::snapshot();
        $clock = new FixtureClock(new DateTimeImmutable);
        $host = Fixture::host($app, $path, mode: ResponseMode::Unavailable, clock: $clock);
        $ref = Refunds::attemptRef('old');
        $app->make(AttemptWorker::class)->dispatch($scope, $ref, 'old', new DateTimeImmutable('+1 second'))->orThrow();
        $old = $app->make(ExecutionStore::class)->operation($scope, 'old');
        $attempt = $app->make(ExecutionStore::class)->attempt($scope, $ref);
        self::assertNotNull($old);
        self::assertNotNull($attempt);
        $tickets = new DurableJournal($path.'.host');
        $ticket = array_find($tickets->records(), static fn (object $entry): bool => $entry instanceof DispatchTicket && $entry->claims->operationRef === 'old');
        self::assertInstanceOf(DispatchTicket::class, $ticket);
        $host->retire($ticket->claims, 'independent-egress-retirement');
        usleep(1_100_000);
        $clock->now = new DateTimeImmutable;
        $verifier = new SimulatedAbsenceVerifier(new DurableJournal($path), $tickets, new DurableJournal($path.'.cert'), $clock, $cap,
            static fn (): int => $app->make(DatabaseManager::class)->connection()->transactionLevel());
        $certificate = $verifier->certify($old->binding, $attempt->providerReference, $old->createdAt->modify('-1 minute'), $clock->now);
        $driver = $app->make(PaymentDrivers::class)->resolve($cap->driverId, $cap->driverContractVersion);
        $lookup = self::createStub(PaymentDriver::class);
        $lookup->method('contractVersion')->willReturn($driver->contractVersion());
        $lookup->method('lookup')->willReturnCallback(static function (LookupRequest $request) use ($driver, $certificate): ProviderLookupResult {
            self::assertSame('old', $request->operationRef);
            self::assertSame($certificate->providerReference, $request->providerReference);
            self::assertSame(ProviderLookupState::Ambiguous, $driver->lookup($request)->state);

            return new ProviderLookupResult(ProviderLookupState::CertifiedAbsent, null, null, $certificate, null, null);
        });
        $drivers = self::createStub(PaymentDrivers::class);
        $drivers->method('resolve')->willReturn($lookup);
        $drivers->method('capabilities')->willReturn($cap);
        $app->instance(PaymentDrivers::class, $drivers);
        $app->instance(ProviderAbsenceVerifier::class, $verifier);
        $app->make(AttemptWorker::class)->recover($scope, $ref, 'lookup', new DateTimeImmutable('+1 minute'))->orThrow();
        self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce(self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint='old' AND state='due'")))->disposition);
        self::assertSame(1, $this->rows('billium_execution_certified_absence'));
        self::assertSame(1, UnknownResultTest::events($path, 'provider.lookup'));

        $this->authority->issueTransition('seal', 'refund-seal', [1]);
        $this->installation()->seal('refund-seal', 1);
        $this->authority->issueTransition('open', 'refund-epoch-2', ['owner-2', 2, 2, 2, 'verified-retirement']);
        $this->installation()->openSession('refund-epoch-2', 'owner-2', 2, 2, 2, 'verified-retirement');
        $this->authority->issueTransition('activate', 'refund-activate', [2, 'certificate-2', 'admission-2', 'evidence-2']);
        $this->installation()->activateSession('refund-activate', 2, 'certificate-2', 'admission-2', 'evidence-2');
        $token = FixedSessionAdmission::token(self::INSTALLATION, 'owner-2', 2, 2, 2);
        $this->tokens->set($token);
        $this->authority->issueProcess($token, [new FinancialScope(self::INSTALLATION, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, 'remote-billium')]);
        $newHost = Fixture::host($app, $path);
        $newHost->openSession(new DispatchRequest($scope, 'fixture', 'fixture', 'fixture', EffectKind::Charge, Fixture::wire()->money, 'fixture', 'fixture',
            'owner-2', 2, 2, 2, 2, 1, 'billium.psp_dispatch.v1'));
    }
}

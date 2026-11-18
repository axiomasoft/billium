<?php

declare(strict_types=1);

namespace Billium\Testkit\Tests\Drivers;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Data\AbsenceCertificate;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\LookupRequest;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Execution\Tests\Feature\ExecutionSchemaTest;
use Billium\Testkit\Drivers\DeterministicPaymentDriver;
use Billium\Testkit\Drivers\SimulatedAbsenceVerifier;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\DeterministicWriterHost;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use Billium\Testkit\Tests\HarnessFactory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
final class DurableProviderEvidenceTest extends ExecutionTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/billium-durable-provider-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory.'/*');
        if ($files === false) {
            throw new RuntimeException('Cannot enumerate fixture journals');
        }
        foreach ($files as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function test_original_signed_fixture_normalizes_after_postgres_jsonb_roundtrip_and_rejects_tampering(): void
    {
        $scope = ExecutionSchemaTest::scopeFixture();
        $cap = SimulatedCapabilities::snapshot('account-a');
        $base = HarnessFactory::binding($cap);
        $binding = new FrozenBinding($scope, $base->executionProfile, $base->businessIntentRef, $base->rootOperationRef, $base->merchantRevisionRef, $base->merchantAccountRef,
            $base->driverId, $base->driverContractVersion, $base->capabilityDigest, $base->semanticDigest, $base->canonicalizationVersion, null, 'commerz');
        $request = HarnessFactory::request($cap, binding: $binding);
        $clock = new FixtureClock(new DateTimeImmutable('2026-11-17T00:00:00Z'));
        $journal = new DurableJournal($this->directory.'/effects');
        $driver = new DeterministicPaymentDriver($journal, $clock, $cap, static fn (): int => 0);
        $result = $driver->execute($request);
        $evidence = $result->evidence ?? throw new RuntimeException;
        $store = $this->application()->make(ExecutionStore::class);
        $this->runner()->run($this->spec(modules: ['platform', 'execution']), function (TransactionContext $ctx) use ($store, $evidence) {
            self::assertSame($evidence->evidenceRef, $store->appendEvidence($ctx, $evidence));

            return self::receipt();
        })->orThrow();
        $json = $this->value('SELECT sanitized_payload::text FROM billium_execution_provider_evidence');
        self::assertIsString($json);
        $body = ExecutionCodec::map($json);
        $hydrate = fn (string $payload) => new ProviderEvidence($evidence->evidenceRef, $evidence->scope, $evidence->providerId, $evidence->merchantAccountRef, $evidence->providerEventId,
            $evidence->payloadDigest, $evidence->signatureVerdict, $evidence->handlerContractVersion, $clock->now, ExecutionCodec::map($payload), $evidence->securePayloadRef);
        $clock->advance(2);
        $restarted = new DeterministicPaymentDriver($journal, $clock, $cap, static fn (): int => 0);
        self::assertEquals($result->snapshot, $restarted->normalize($hydrate(ExecutionCodec::encode($body)), $cap));
        $lookup = $restarted->lookup(new LookupRequest($binding, $request->permit->operationRef, $request->providerReference, null, $request->credentials));
        self::assertSame($evidence->evidenceRef, $lookup->evidence?->evidenceRef);
        self::assertEquals($result->snapshot, $lookup->snapshot);
        $material = $body['material'];
        self::assertIsArray($material);
        $material['captured'] = ['amount' => '9999.00', 'currency' => 'RUB'];
        $body['material'] = $material;
        $this->expectException(RuntimeException::class);
        $restarted->normalize($hydrate(ExecutionCodec::encode($body)), $cap);
    }

    public function test_absence_requires_exact_retired_issued_binding_and_complete_window_after_json_roundtrip(): void
    {
        $cap = SimulatedCapabilities::snapshot('account-a');
        $binding = HarnessFactory::binding($cap);
        $request = HarnessFactory::request($cap);
        $clock = new FixtureClock(new DateTimeImmutable('2026-11-17T00:00:00Z'));
        $hostLog = new DurableJournal($this->directory.'/host');
        $host = new DeterministicWriterHost($hostLog, $clock, static fn (): int => 0);
        $claims = HarnessFactory::dispatch($request);
        $host->openSession($claims);
        $host->ticket($claims);
        $verifier = new SimulatedAbsenceVerifier(new DurableJournal($this->directory.'/effects'), $hostLog, new DurableJournal($this->directory.'/issued'), $clock, $cap, static fn (): int => 0);
        try {
            $verifier->certify($binding, $request->providerReference, $clock->now, $clock->now);
            self::fail('Unretired egress certified absence');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('retirement', $e->getMessage());
        }
        $from = $clock->now;
        $host->retire($claims, 'fixture-worker-and-egress-retired');
        $clock->advance(1);
        try {
            $verifier->certify($binding, $request->providerReference, $clock->now, $clock->now);
            self::fail('Window excluded issued ticket');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('horizon', $e->getMessage());
        }
        $cert = $verifier->certify($binding, $request->providerReference, $from, $clock->now);
        $fields = ExecutionCodec::map(ExecutionCodec::encode($cert));
        $clock->advance(1);
        $verifier->verify(ExecutionCodec::decode(AbsenceCertificate::class, ExecutionCodec::encode($fields)), $binding);
        foreach (['providerReference', 'providerNamespace', 'retirementProofDigest'] as $field) {
            $changed = $fields;
            $changed[$field] = 'foreign';
            try {
                $verifier->verify(ExecutionCodec::decode(AbsenceCertificate::class, ExecutionCodec::encode($changed)), $binding);
                self::fail('Altered certificate was accepted');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('certificate', $e->getMessage());
            }
        }
    }
}

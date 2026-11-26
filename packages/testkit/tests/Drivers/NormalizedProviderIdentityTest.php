<?php

declare(strict_types=1);

namespace Billium\Testkit\Tests\Drivers;

use Billium\Execution\Data\LookupRequest;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ProviderLookupState;
use Billium\Testkit\Drivers\DeterministicPaymentDriver;
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\DeterministicWriterHost;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use Billium\Testkit\Tests\HarnessFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class NormalizedProviderIdentityTest extends TestCase
{
    public function test_pending_unknown_amount_can_settle_once_and_old_signed_protocol_retains_unknown_assignment(): void
    {
        $path = sys_get_temp_dir().'/provider-pending-'.bin2hex(random_bytes(12));
        try {
            $journal = new DurableJournal($path);
            $cap = SimulatedCapabilities::snapshot();
            $clock = new FixtureClock(new DateTimeImmutable);
            $driver = new DeterministicPaymentDriver($journal, $clock, $cap, static fn (): int => 0, ResponseMode::Pending);
            $request = HarnessFactory::request($cap);
            $pending = $driver->execute($request)->snapshot ?? throw new RuntimeException;
            self::assertNull($pending->operationAmount);
            self::assertNotNull($pending->providerOperationId);
            $actual = $driver->settle($pending->stableEffectId ?? throw new RuntimeException, 'succeeded')->snapshot ?? throw new RuntimeException;
            self::assertSame(100, $actual->operationAmount?->minor);
            self::assertSame($pending->providerOperationId, $actual->providerOperationId);
            $old = HarnessFactory::request($cap, 'old-authorization', 'original-reference', 70, HarnessFactory::binding($cap, seller: 'old-seller'));
            $recordType = new ReflectionClass(ProviderRecord::class);
            $legacy = $recordType->newInstanceWithoutConstructor();
            foreach (['identity' => 'old-effect', 'kind' => EffectKind::Authorize, 'request' => $old, 'acceptedAt' => $clock->now, 'pid' => 42, 'status' => 'succeeded'] as $field => $value) {
                $recordType->getProperty($field)->setValue($legacy, $value);
            }
            $journal->append($legacy);
            $result = $driver->lookup(new LookupRequest($old->binding, 'old-authorization', 'original-reference', null, $old->credentials));
            $material = $result->evidence?->sanitizedPayload['material'] ?? null;
            self::assertIsArray($material);
            self::assertSame('billium.testkit.provider-material.v1', $material['schema']);
            self::assertSame(70, $result->snapshot?->authorizedTotal->minor);
            self::assertNull($result->snapshot->providerOperationId);
            self::assertNull($result->snapshot->operationAmount);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_sale_records_both_legs_and_recovers_by_independent_assigned_id(): void
    {
        $path = sys_get_temp_dir().'/provider-identity-'.bin2hex(random_bytes(12));
        try {
            $journal = new DurableJournal($path);
            $cap = SimulatedCapabilities::snapshot();
            $driver = new DeterministicPaymentDriver($journal, new FixtureClock(new DateTimeImmutable), $cap, static fn (): int => 0);
            $request = HarnessFactory::request($cap);
            $result = $driver->execute($request);
            $s = $result->snapshot ?? throw new RuntimeException;
            self::assertSame(100, $s->authorizedTotal->minor);
            self::assertSame(100, $s->capturedTotal->minor);
            self::assertSame(100, $s->operationAmount?->minor);
            self::assertEquals($s, $driver->execute($request)->snapshot);
            self::assertSame('reference-a', $s->providerReference);
            self::assertSame(DeterministicWriterHost::bindingDigest($request->binding), $s->bindingDigest);
            self::assertNotNull($s->providerOperationId);
            self::assertNotSame($s->stableEffectId, $s->providerOperationId);
            $lookup = fn (?string $id): ProviderLookupResult => $driver->lookup(new LookupRequest($request->binding, $request->permit->operationRef, $request->providerReference, $id, $request->credentials));
            self::assertEquals($s, $lookup($s->providerOperationId)->snapshot);
            self::assertSame(ProviderLookupState::Ambiguous, $lookup($s->stableEffectId)->state);
            $e = $result->evidence ?? throw new RuntimeException;
            foreach (['operationAmount', 'providerOperationId', 'bindingDigest', 'reference'] as $field) {
                $body = $e->sanitizedPayload;
                self::assertIsArray($body['material']);
                $body['material'][$field] = 'changed';
                try {
                    $driver->normalize(new ProviderEvidence($e->evidenceRef, $e->scope, $e->providerId, $e->merchantAccountRef, $e->providerEventId, $e->payloadDigest, $e->signatureVerdict, $e->handlerContractVersion, $e->receivedAt, $body, $e->securePayloadRef), $cap);
                    self::fail('Altered signed field was accepted');
                } catch (RuntimeException $error) {
                    self::assertStringContainsString('signature', $error->getMessage());
                }
            }
            // This provider explicitly reports capture 120 against authorization 100 and original request 100.
            $authorizedBinding = HarnessFactory::binding($cap, seller: 'seller-b');
            $authorization = HarnessFactory::request($cap, 'original-authorization', 'authorize-reference', 100, $authorizedBinding);
            self::assertSame(100, $driver->authorize($authorization)->snapshot?->authorizedTotal->minor);
            $other = HarnessFactory::request($cap, 'unexpected-capture', 'unexpected-reference', 100, $authorizedBinding);
            $entry = new ProviderRecord('distinct-effect', EffectKind::Capture, $other, new DateTimeImmutable, 1, 'succeeded', 'assigned-capture', ['captured' => 120]);
            $journal->append($entry);
            $actual = $driver->lookup(new LookupRequest($other->binding, $other->permit->operationRef, $other->providerReference, 'assigned-capture', $other->credentials))->snapshot;
            self::assertSame(120, $actual?->operationAmount?->minor);
            self::assertSame(120, $actual->capturedTotal->minor);
            self::assertSame(100, $actual->authorizedTotal->minor);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

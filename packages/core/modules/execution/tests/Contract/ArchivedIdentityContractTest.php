<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Contract;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Data\ArchivedExecutionIdentity;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\OriginDecision;
use Billium\Execution\Enums\EffectKind;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** An archived original is only importable when it agrees with the root binding it claims to continue. */
final class ArchivedIdentityContractTest extends TestCase
{
    private const DIGEST = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const OTHER_DIGEST = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private static function binding(bool $verified = true): FrozenBinding
    {
        $scope = new ExecutionScope('installation', 'seller', 'test', 'owner');
        $origin = $verified ? new OriginDecision($scope, 'root', 'intent', 'shop', 'remote-billium', 17, null, 'commercial-basis', 'policy.v3', null) : null;

        return new FrozenBinding($scope, 'remote-billium', 'intent', 'root', 'revision', 'account', 'driver', 'driver.v1', 'capability',
            self::DIGEST, 'execution.intent.v1', null, 'shop', $origin);
    }

    /** @return array<string, mixed> */
    private static function fields(): array
    {
        return [
            'binding' => self::binding(), 'kind' => EffectKind::Charge, 'requested' => Money::parse('1.00', 'RUB'),
            'businessIntentRef' => 'intent', 'operationRef' => 'root', 'semanticDigest' => self::DIGEST, 'commandKey' => 'command',
            'qualifiedKeyDigest' => self::DIGEST, 'inputDigest' => self::DIGEST, 'attemptRef' => 'attempt', 'providerReference' => 'reference',
            'providerId' => 'provider', 'merchantRevisionDigest' => self::DIGEST, 'providerNamespace' => 'namespace',
            'credentialVersionRef' => 'credential', 'requestDigest' => self::DIGEST, 'originalFence' => 1, 'originalOwner' => 'owner',
            'originalEpoch' => 1, 'originalLineage' => 'lineage', 'firstTicketRef' => 'ticket', 'collectionPermitRef' => null,
            'createdAt' => new DateTimeImmutable('2026-01-01T00:00:00Z'), 'startedAt' => new DateTimeImmutable('2026-01-01T00:00:01Z'),
            'deadline' => new DateTimeImmutable('2026-01-01T00:10:00Z'), 'manifestDigest' => self::DIGEST, 'sourceJournalDigest' => self::DIGEST,
        ];
    }

    public function test_a_root_charge_matching_its_verified_binding_is_accepted(): void
    {
        $identity = new ArchivedExecutionIdentity(...self::fields());

        self::assertSame('payment.execute.v1', $identity->commandSchema());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function divergentOriginals(): iterable
    {
        yield 'binding without a verified origin' => [['binding' => self::binding(false)]];
        yield 'root kind on a child operation' => [['operationRef' => 'child']];
        yield 'child kind on the root operation' => [['kind' => EffectKind::Capture]];
        yield 'root with another business intent' => [['businessIntentRef' => 'another-intent']];
        yield 'root with another semantic digest' => [['semanticDigest' => self::OTHER_DIGEST]];
        yield 'zero requested amount' => [['requested' => Money::parse('0.00', 'RUB')]];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('divergentOriginals')]
    public function test_an_original_that_differs_from_its_binding_is_refused(array $change): void
    {
        try {
            new ArchivedExecutionIdentity(...array_replace(self::fields(), $change));
            self::fail('Expected the divergent original to be refused');
        } catch (ProblemException $exception) {
            self::assertSame('billium.validation.invalid', $exception->problem->value);
            self::assertSame('Archived dispatch differs from its original commercial terms', $exception->detail);
        }
    }
}

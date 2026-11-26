<?php

declare(strict_types=1);

namespace Billium\Testkit\Tests;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\DispatchDecision;
use Billium\Execution\Data\DispatchRequest;
use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\DriverRequest;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\MerchantRevisionPolicy;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Enums\EffectKind;
use Billium\Testkit\Fixtures\DeterministicWriterHost;
use DateTimeImmutable;

final class HarnessFactory
{
    public static function binding(CapabilitySnapshot $capabilities, string $seller = 'seller-a', string $environment = 'testing', string $sourceContext = 'commerz'): FrozenBinding
    {
        return new FrozenBinding(new ExecutionScope('installation-a', $seller, $environment, 'billing'), 'remote-billium', 'intent-a', 'root-a', 'merchant-v1', $capabilities->merchantAccountRef, $capabilities->driverId, $capabilities->driverContractVersion, $capabilities->digest, 'semantic-a', 'canonical.v1', null, $sourceContext);
    }

    public static function credentials(string $account = 'account-a', string $version = 'credentials-v1'): ResolvedCredentials
    {
        return new ResolvedCredentials($account, $version, new SecretRef('secret://fixture/'.$version), 'testing', new DateTimeImmutable('2030-01-01T00:00:00Z'), 'simulated-continuity:'.$account);
    }

    public static function request(CapabilitySnapshot $capabilities, string $operation = 'operation-a', string $reference = 'reference-a', int $amount = 100, ?FrozenBinding $binding = null, ?DispatchTicket $ticket = null): DriverRequest
    {
        return new DriverRequest(new DispatchDecision($operation, 'attempt-a', 1, 1, $ticket === null ? 'ticket-a' : $ticket->ticketRef, $ticket === null ? 'fence-a' : $ticket->egressFenceRef, null, 'request-digest', $ticket?->expiresAt ?? new DateTimeImmutable('2026-11-17T00:00:30Z')), $binding ?? self::binding($capabilities), $capabilities, new Money($amount, Currency::of('RUB')), $reference, self::credentials($capabilities->merchantAccountRef));
    }

    public static function dispatch(DriverRequest $request, EffectKind $kind = EffectKind::Charge): DispatchRequest
    {
        return new DispatchRequest($request->binding->scope, $request->permit->operationRef, $request->permit->attemptRef, $request->providerReference, $kind, $request->amount, DeterministicWriterHost::bindingDigest($request->binding), $request->capabilities->digest, 'owner-a', 1, 1, 1, 1, $request->permit->leaseFence, 'test-process');
    }

    public static function merchant(CapabilitySnapshot $capabilities): MerchantRevision
    {
        return new MerchantRevision('merchant-v1', self::binding($capabilities)->scope, $capabilities->merchantAccountRef, 'simulated-provider', $capabilities->driverId, $capabilities->driverContractVersion, $capabilities->snapshotRef, $capabilities->digest, new SecretRef('secret://fixture/credentials-v1'), 'credentials-v1', ['RUB'], 'merchant-digest', new DateTimeImmutable('2026-11-17T00:00:00Z'), new MerchantRevisionPolicy(new DateTimeImmutable('2026-11-17T00:00:00Z'), null, null, 'simulated-provider.v1', 'fixture-webhook', 'fixture-continuity', 'fixture-settlement-account', 'fixture-mandate-namespace'));
    }

    public static function integerQuery(\PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('Harness SQL query failed');
        }
        $value = $statement->fetchColumn();
        if (! is_numeric($value)) {
            throw new \RuntimeException('Harness SQL result is not an integer');
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $data */
    public static function evidence(string $name, array $data): void
    {
        $directory = getenv('HARNESS_EVIDENCE_DIR');
        if ($directory === false) {
            return;
        }
        if (! str_starts_with($directory, sys_get_temp_dir().'/billium-harness-evidence')) {
            throw new \RuntimeException('Unsafe harness evidence directory');
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Cannot create harness evidence directory');
        }
        if (file_put_contents($directory.'/'.$name.'.json', json_encode(['probe' => $name, 'data' => $data], JSON_THROW_ON_ERROR)) === false) {
            throw new \RuntimeException('Cannot persist harness evidence');
        }
    }
}

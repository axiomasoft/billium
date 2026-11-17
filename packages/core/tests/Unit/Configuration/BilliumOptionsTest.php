<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit\Configuration;

use Billium\Core\Configuration\BilliumOptions;
use LogicException;
use Packman\Config\Data\SecretRef;
use Packman\Config\Exceptions\InvalidConfiguration;
use PHPUnit\Framework\TestCase;

final class BilliumOptionsTest extends TestCase
{
    public function test_default_config_compiles_with_closed_schema_types(): void
    {
        $raw = require dirname(__DIR__, 3).'/config/billium.php';
        $options = BilliumOptions::fromArray($raw, 'billium');

        self::assertNull($options->connection);
        self::assertSame(3, $options->runner->maxAttempts);
        self::assertSame(30_000, $options->runner->rootDeadlineMs);
        self::assertSame(5_000, $options->financialSession->sealLockTimeoutMs);
        self::assertSame(5_000, $options->idempotency->lockTimeoutMs);
        self::assertSame(100, $options->outbox->relayBatch);
        self::assertNull($options->outbox->retentionFloorSeconds);
        self::assertSame(100, $options->feed->drainBatch);
        self::assertFalse($options->feed->signingKey->isPresent());
        self::assertSame(5, $options->inbox->retryBackoffSeconds);
        self::assertTrue($options->runtimeRole->preflight);
        self::assertSame(3_600, $options->ledger->refundReconcileAfterSeconds);
        self::assertSame('embedded-commerz', $options->modules->composition);
        self::assertSame(['platform', 'ledger'], $options->modules->enabled);
        self::assertSame('pgsql', $options->connectionName('pgsql'));
    }

    public function test_unknown_top_and_nested_keys_fail_closed_with_typo_suggestions(): void
    {
        $raw = require dirname(__DIR__, 3).'/config/billium.php';
        $raw['runnr'] = [];

        try {
            BilliumOptions::fromArray($raw, 'billium');
            self::fail('Unknown top-level key must fail closed');
        } catch (InvalidConfiguration $exception) {
            self::assertStringContainsString('billium.runnr', $exception->getMessage());
            self::assertStringContainsString('did you mean "runner"', $exception->getMessage());
        }

        $nested = require dirname(__DIR__, 3).'/config/billium.php';
        $nested['outbox']['unknown_flag'] = true;
        $this->expectException(InvalidConfiguration::class);
        BilliumOptions::fromArray($nested, 'billium');
    }

    public function test_signing_key_is_wrapped_in_secret_ref_and_decodes_base64(): void
    {
        $raw = require dirname(__DIR__, 3).'/config/billium.php';
        $secret = random_bytes(32);
        $raw['feed']['signing_key'] = 'base64:'.base64_encode($secret);

        $options = BilliumOptions::fromArray($raw, 'billium');

        self::assertInstanceOf(SecretRef::class, $options->feed->signingKey);
        self::assertTrue($options->feed->signingKey->isPresent());
        self::assertSame('[redacted]', (string) $options->feed->signingKey);
        self::assertSame(['value' => '[redacted]'], $options->feed->signingKey->__debugInfo());
        self::assertSame($secret, $options->feed->decodedSigningKey());

        $this->expectException(LogicException::class);
        serialize($options->feed->signingKey);
    }
}

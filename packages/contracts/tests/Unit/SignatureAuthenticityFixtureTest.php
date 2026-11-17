<?php

declare(strict_types=1);

namespace Billium\Contracts\Tests\Unit;

use Billium\Contracts\Contracts\Codec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignatureAuthenticityFixtureTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, mixed>, string, string, string, bool}> */
    public static function cases(): iterable
    {
        $raw = file_get_contents(dirname(__DIR__, 4).'/fixtures/qualification/authenticity/callback.json');
        self::assertIsString($raw);
        /** @var array{transcript: array<string, mixed>, canonicalUtf8: string, keyHex: string, signatureHex: string, cases: list<array{id: string, accepted: bool, changes?: array<string, mixed>, keyHex?: string, signatureHex?: string}>} $fixture */
        $fixture = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixture['cases'] as $case) {
            yield $case['id'] => [$case['id'], array_replace($fixture['transcript'], $case['changes'] ?? []),
                $fixture['canonicalUtf8'], $case['keyHex'] ?? $fixture['keyHex'], $case['signatureHex'] ?? $fixture['signatureHex'], $case['accepted']];
        }
    }

    /** @param array<string, mixed> $transcript */
    #[DataProvider('cases')]
    public function test_the_simulated_callback_signature_authenticates_the_exact_transcript(
        string $id, array $transcript, string $expectedBytes, string $keyHex, string $signature, bool $accepted,
    ): void {
        $canonical = new Codec()->canonicalize($transcript);
        $key = hex2bin($keyHex);
        self::assertIsString($key);
        if ($id === 'authentic') {
            self::assertSame($expectedBytes, $canonical);
        }
        $actual = hash_hmac('sha256', $canonical, $key);
        $wrongControl = $id === 'bad-signature-control' && getenv('BILLIUM_AUTHENTICITY_WRONG_CONTROL') === '1';
        self::assertSame($accepted || $wrongControl, hash_equals($signature, $actual), $id);
    }
}

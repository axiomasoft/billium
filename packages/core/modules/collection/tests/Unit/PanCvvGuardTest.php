<?php

declare(strict_types=1);

namespace Billium\Collection\Tests\Unit;

use Billium\Collection\Internal\Support\PanCvvGuard;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/** Direct PanCvvGuard checks. No database. */
final class PanCvvGuardTest extends TestCase
{
    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function forbiddenPayloads(): iterable
    {
        yield 'meta.cardCvv' => [['meta' => ['cardCvv' => 123]]];
        yield 'card_cvv' => [['card_cvv' => '999']];
        yield 'x-api-key' => [['x-api-key' => 'sk_live_secret']];
        yield 'access_token' => [['access_token' => 'ya29.secret-token']];
        yield 'accessToken' => [['accessToken' => 'opaque']];
        yield 'client_secret' => [['client_secret' => 'opaque']];
        yield 'clientSecret' => [['clientSecret' => 'opaque']];
        yield 'provider_token' => [['provider_token' => 'opaque']];
        yield 'providerToken' => [['providerToken' => 'opaque']];
        yield 'provider token' => [['provider token' => 'opaque']];
        yield 'refresh_token' => [['refresh_token' => 'opaque']];
        yield 'json object body' => [['body' => '{"cvv":"123"}']];
        yield 'trimmed json object' => [['body' => "\n{\"cvv\":\"123\"} "]];
        yield 'json array cvv' => [['events' => '[{"cvv":"123"}]']];
        yield 'doubly encoded cvv' => [['body' => json_encode(['inner' => json_encode(['cvv' => '123'])], JSON_THROW_ON_ERROR)]];
        yield 'dotted pan' => [['note' => '4111.1111.1111.1111']];
        yield 'spaced pan' => [['note' => '4111 1111 1111 1111']];
        yield 'hyphenated pan' => [['note' => '4111-1111-1111-1111']];
        yield 'contiguous pan' => [['note' => '4111111111111111']];
        yield 'apiKey' => [['apiKey' => 'sk_live']];
        yield 'api_key' => [['api_key' => 'sk_live']];
        yield 'nested cvv' => [['meta' => ['cvv' => '123']]];
        yield 'exact secret' => [['secret' => 'x']];
        yield 'exact pan' => [['pan' => 'x']];
        yield 'exact pin' => [['pin' => 'ok']];
        yield 'exact cid' => [['cid' => 'abc']];
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function allowedPayloads(): iterable
    {
        yield 'secret_ref locator' => [['secret_ref' => 'secret://merchant/key']];
        yield 'short dotted version' => [['note' => '1.2.3']];
        yield 'pan is not a suffix' => [['company_pan' => 'acme']];
        yield 'pin is not a suffix' => [['routing_pin' => 'ok']];
        yield 'cid is not a suffix' => [['merchant_cid' => 'abc']];
        yield 'bare token is not a provider token' => [['token' => 'tok_opaque']];
        yield 'invalid json without a card pattern' => [['note' => '{not-json}']];
        yield 'empty json object' => [['body' => '{}']];
        yield 'empty json array' => [['body' => '[]']];
    }

    /** @return iterable<string, array{string, string}> */
    public static function forbiddenReferences(): iterable
    {
        yield 'json cvv paymentMethodRef' => ['{"cvv":"123"}', 'paymentMethodRef'];
        yield 'json cvv ref' => ['{"cvv":"123"}', 'ref'];
        yield 'dotted pan reference' => ['4111.1111.1111.1111', 'paymentMethodRef'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function allowedReferences(): iterable
    {
        yield 'short dotted reference' => ['1.2.3', 'paymentMethodRef'];
        yield 'secret locator value' => ['secret://merchant/key', 'tokenLocator'];
        yield 'opaque token' => ['pm_safe_token', 'paymentMethodRef'];
    }

    /** @param array<array-key, mixed> $payload */
    #[DataProvider('forbiddenPayloads')]
    public function test_forbidden_payloads_are_rejected(array $payload): void
    {
        $this->assertSensitive(static function () use ($payload): void {
            PanCvvGuard::assertCleanPayload($payload);
        });
    }

    /** @param array<array-key, mixed> $payload */
    #[DataProvider('allowedPayloads')]
    public function test_safe_payloads_pass(array $payload): void
    {
        $this->expectNotToPerformAssertions();
        PanCvvGuard::assertCleanPayload($payload);
    }

    #[DataProvider('forbiddenReferences')]
    public function test_opaque_reference_rejects_sensitive_values(string $value, string $field): void
    {
        $this->assertSensitive(static function () use ($value, $field): void {
            PanCvvGuard::assertOpaqueReference($value, $field);
        });
    }

    #[DataProvider('allowedReferences')]
    public function test_opaque_reference_keeps_locators(string $value, string $field): void
    {
        $this->expectNotToPerformAssertions();
        PanCvvGuard::assertOpaqueReference($value, $field);
    }

    public function test_public_object_properties_are_still_scanned(): void
    {
        $object = new stdClass;
        $object->cvv = '123';
        $this->assertSensitive(static function () use ($object): void {
            PanCvvGuard::assertCleanPayload(['nested' => $object]);
        });
    }

    public function test_json_string_at_the_decode_cap_is_rejected(): void
    {
        $value = self::paddedCvvJson(65536);
        self::assertSame(65536, strlen($value));
        $this->assertSensitive(static function () use ($value): void {
            PanCvvGuard::assertCleanPayload(['body' => $value]);
        });
    }

    public function test_json_string_past_the_decode_cap_is_not_decoded(): void
    {
        $value = self::paddedCvvJson(65537);
        self::assertSame(65537, strlen($value));
        PanCvvGuard::assertCleanPayload(['body' => $value]);
    }

    public function test_over_cap_values_still_reject_a_visible_pan(): void
    {
        $pan = '4111111111111111';
        $value = str_repeat('x', 65537 - strlen($pan)).$pan;
        self::assertSame(65537, strlen($value));
        $this->assertSensitive(static function () use ($value): void {
            PanCvvGuard::assertCleanPayload(['note' => $value]);
        });
    }

    public function test_nesting_past_ten_keeps_the_depth_detail(): void
    {
        $payload = ['leaf' => 'ok'];
        for ($level = 0; $level < 11; $level++) {
            $payload = ['child' => $payload];
        }

        try {
            PanCvvGuard::assertCleanPayload($payload);
            self::fail('nesting past ten levels must be rejected');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            self::assertSame('actionPayload exceeds maximum nesting depth', $error->detail);
        }
    }

    /** @param callable(): void $probe */
    private function assertSensitive(callable $probe): void
    {
        try {
            $probe();
            self::fail('sensitive card data must be rejected');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            self::assertSame('billium.collection.sensitive_card_data_forbidden', $error->detail);
        }
    }

    private static function paddedCvvJson(int $length): string
    {
        $json = '{"cvv":"123"}';

        return str_repeat(' ', $length - strlen($json)).$json;
    }
}

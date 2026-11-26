<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Support;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Internal;

/**
 * Rejects PAN, CVV/CVC, track data, and raw secret material before persistence or logging.
 */
#[Internal]
final class PanCvvGuard
{
    /** @var list<string> */
    private const array FORBIDDEN_KEYS = [
        'pan',
        'card_number',
        'cardnumber',
        'primary_account_number',
        'cvv',
        'cvc',
        'cvv2',
        'cvc2',
        'cid',
        'security_code',
        'securitycode',
        'track1',
        'track2',
        'pin',
        'raw_card',
        'card_secret',
        'secret',
        'password',
        'private_key',
        'api_key',
        'authorization',
        'bearer',
    ];

    /**
     * Normalized suffixes. pan, pin, cid, and secret stay exact-only so locators such as secret_ref pass.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_KEY_SUFFIXES = [
        'cvv',
        'cvv2',
        'cvc',
        'cvc2',
        'cardnumber',
        'securitycode',
        'apikey',
        'accesstoken',
        'refreshtoken',
        'clientsecret',
        'privatekey',
        'providertoken',
    ];

    /** Longer strings are pattern-scanned and not JSON-decoded. */
    private const int MAX_JSON_SCAN_LENGTH = 65536;

    public static function assertOpaqueReference(string $value, string $field): void
    {
        RowCast::bounded($value, $field);
        self::assertCleanString($value, 0);
    }

    /** @param array<array-key, mixed> $payload */
    public static function assertCleanPayload(array $payload, int $depth = 0): void
    {
        if ($depth > 10) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'actionPayload exceeds maximum nesting depth');
        }
        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isForbiddenKey(self::normalizeKey($key))) {
                self::rejectSensitive();
            }
            if (is_string($value)) {
                self::assertCleanString($value, $depth);
            } elseif (is_int($value)) {
                if (self::containsPanOrCvvPattern((string) $value)) {
                    self::rejectSensitive();
                }
            } elseif (is_array($value)) {
                self::assertCleanPayload($value, $depth + 1);
            } elseif (is_object($value)) {
                self::assertCleanPayload(get_object_vars($value), $depth + 1);
            }
        }
    }

    private static function assertCleanString(string $value, int $depth): void
    {
        if (strlen($value) <= self::MAX_JSON_SCAN_LENGTH) {
            $trimmed = trim($value);
            if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    self::assertCleanPayload($decoded, $depth + 1);
                }
            }
        }
        if (self::containsPanOrCvvPattern($value)) {
            self::rejectSensitive();
        }
    }

    private static function isForbiddenKey(string $normalized): bool
    {
        if ($normalized === '') {
            return false;
        }
        if (in_array($normalized, self::normalizedForbiddenKeys(), true)) {
            return true;
        }

        return array_any(self::FORBIDDEN_KEY_SUFFIXES, fn (string $suffix): bool => str_ends_with($normalized, $suffix));
    }

    private static function normalizeKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
    }

    /** @return list<string> */
    private static function normalizedForbiddenKeys(): array
    {
        return array_map(
            self::normalizeKey(...),
            self::FORBIDDEN_KEYS,
        );
    }

    private static function containsPanOrCvvPattern(string $value): bool
    {
        if (preg_match('/[0-9]{13,19}/', $value) === 1) {
            return true;
        }
        if (preg_match('/(?:^|[^0-9A-Za-z])(?:[0-9][ .-]?){13,19}(?:$|[^0-9A-Za-z])/', $value) === 1) {
            return true;
        }

        return preg_match('/\b(?:cvv2?|cvc2?|pan)\s*[:=]/i', $value) === 1;
    }

    private static function rejectSensitive(): never
    {
        throw new ProblemException(
            ProblemCode::ValidationInvalid,
            'billium.collection.sensitive_card_data_forbidden',
        );
    }
}

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

    public static function assertOpaqueReference(string $value, string $field): void
    {
        RowCast::bounded($value, $field);
        if (self::containsPanOrCvvPattern($value)) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'billium.collection.sensitive_card_data_forbidden',
            );
        }
    }

    /** @param array<array-key, mixed> $payload */
    public static function assertCleanPayload(array $payload, int $depth = 0): void
    {
        if ($depth > 10) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'actionPayload exceeds maximum nesting depth');
        }
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
                if (in_array($normalized, self::normalizedForbiddenKeys(), true)) {
                    throw new ProblemException(
                        ProblemCode::ValidationInvalid,
                        'billium.collection.sensitive_card_data_forbidden',
                    );
                }
            }
            if (is_string($value) || is_int($value)) {
                if (self::containsPanOrCvvPattern(is_int($value) ? (string) $value : $value)) {
                    throw new ProblemException(
                        ProblemCode::ValidationInvalid,
                        'billium.collection.sensitive_card_data_forbidden',
                    );
                }
            } elseif (is_array($value)) {
                self::assertCleanPayload($value, $depth + 1);
            } elseif (is_object($value)) {
                self::assertCleanPayload(get_object_vars($value), $depth + 1);
            }
        }
    }

    /** @return list<string> */
    private static function normalizedForbiddenKeys(): array
    {
        return array_map(
            static fn (string $key): string => strtolower((string) preg_replace('/[^a-z0-9]/', '', $key)),
            self::FORBIDDEN_KEYS,
        );
    }

    private static function containsPanOrCvvPattern(string $value): bool
    {
        if (preg_match('/[0-9]{13,19}/', $value) === 1) {
            return true;
        }
        if (preg_match('/(?:^|[^0-9A-Za-z])(?:[0-9][ -]?){13,19}(?:$|[^0-9A-Za-z])/', $value) === 1) {
            return true;
        }

        return preg_match('/\b(?:cvv2?|cvc2?|pan)\s*[:=]/i', $value) === 1;
    }
}

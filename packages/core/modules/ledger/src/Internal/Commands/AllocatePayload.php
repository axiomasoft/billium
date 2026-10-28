<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Ledger\Data\BillingAccountScope;

/**
 * Shape validation (D31 none policy, before any claim) and the semantic digest of the allocation-family commands.
 * The command key never enters the digest: the business identity replays under any key, a changed payload conflicts.
 */
final class AllocatePayload
{
    /** @param array<string, mixed> $values */
    public static function digest(array $values): string
    {
        return 'sha256:'.hash('sha256', (new Codec)->canonicalize($values));
    }

    /** @return array{minor: string, currency: string} */
    public static function money(Money $money): array
    {
        return ['minor' => (string) $money->minor, 'currency' => $money->currency->code];
    }

    /** @return array<string, string> */
    public static function scope(BillingAccountScope $scope): array
    {
        return ['sellerRef' => $scope->sellerRef, 'environment' => $scope->environment, 'billingAccountRef' => $scope->billingAccountRef];
    }

    public static function text(?string ...$values): void
    {
        foreach ($values as $value) {
            if ($value !== null && ($value === '' || strlen($value) > 200)) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'references are nonempty bounded strings');
            }
        }
    }

    public static function environment(string $environment): void
    {
        if (! in_array($environment, ['test', 'live'], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'unknown environment');
        }
    }

    public static function positive(Money $money): void
    {
        if (Currency::of($money->currency->code)->scale !== $money->currency->scale) {
            throw new ProblemException(ProblemCode::MoneyScaleInvalid, 'currency scale differs from the frozen exponent table');
        }
        if ($money->minor <= 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'an allocation-family amount is positive');
        }
    }
}

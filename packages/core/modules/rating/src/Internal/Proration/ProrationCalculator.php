<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Proration;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Enums\ProrationPolicyId;
use Billium\Rating\Internal\Math\Dec;
use Billium\Rating\Internal\Math\IntegerRounding;
use Billium\Rating\Internal\Storage\RatingRows;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Internal;
use Throwable;

#[Internal]
final class ProrationCalculator
{
    /** @return array{elapsed: string, total: string} */
    public function fraction(ProrationPolicyId $policy, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $effectiveAt, ?string $timezone): array
    {
        if ($start >= $end || $effectiveAt < $start || $effectiveAt > $end) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.amendment_unsupported');
        }
        if ($policy === ProrationPolicyId::None) {
            return ['elapsed' => '1', 'total' => '1'];
        }
        if ($policy === ProrationPolicyId::ElapsedSeconds) {
            foreach ([$start, $end, $effectiveAt] as $instant) {
                if ($instant->format('u') !== '000000') {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.proration_boundary_misaligned');
                }
            }

            return ['elapsed' => (string) ($effectiveAt->getTimestamp() - $start->getTimestamp()),
                'total' => (string) ($end->getTimestamp() - $start->getTimestamp())];
        }
        try {
            $zone = new DateTimeZone($timezone ?? '');
        } catch (Throwable) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.terms_invalid: timezone required');
        }
        $localDate = static function (DateTimeImmutable $instant) use ($zone): DateTimeImmutable {
            $local = $instant->setTimezone($zone);
            if ($local->format('H:i:s.u') !== '00:00:00.000000') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.proration_boundary_misaligned');
            }

            return new DateTimeImmutable($local->format('Y-m-d'), new DateTimeZone('UTC'));
        };
        $startDate = $localDate($start);
        $endDate = $localDate($end);
        $effectiveDate = $localDate($effectiveAt);

        return ['elapsed' => (string) $startDate->diff($effectiveDate)->days, 'total' => (string) $startDate->diff($endDate)->days];
    }

    public function prorateMinor(Dec $fullMajor, int $exponent, string $elapsed, string $total, string $roundingMode): string
    {
        if ($exponent < 0 || $exponent > 4 || preg_match('/^[0-9]+$/D', $elapsed) !== 1
            || preg_match('/^[0-9]+$/D', $total) !== 1 || bccomp(RatingRows::decimal($total), '0', 0) <= 0 || bccomp(RatingRows::decimal($elapsed), RatingRows::decimal($total), 0) > 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.terms_invalid: invalid proration fraction');
        }
        $numerator = bcmul(bcmul($fullMajor->unscaled, bcpow('10', (string) $exponent, 0), 0), RatingRows::decimal($elapsed), 0);
        $denominator = bcmul(bcpow('10', (string) $fullMajor->scale, 0), RatingRows::decimal($total), 0);

        return IntegerRounding::divide(num: $numerator, den: $denominator, mode: $roundingMode);
    }
}

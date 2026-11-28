<?php

declare(strict_types=1);

namespace Billium\Contracts\Internal;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Contracts\SpecRevision;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Internal;
use stdClass;

/** Cross-field wire consistency only (BC-ENV-33..43); never rating or calendar calculation. */
#[Internal]
final class P4Wire
{
    public const array SCHEMAS = [
        'usage.metric.v1', 'usage.fact.v2', 'usage.receipt.v2', 'usage.aggregate.v1', 'usage.quantity.v1',
        'rating.price.v1', 'period.commercial.v1', 'rating.basis.v1', 'occurrence.ensure.v2',
        'obligation.prepare.v2', 'occurrence.no_charge.v1',
    ];

    /** @var list<string> Strict P4 envelopes whose unit is constrained by p4Unit (legacy usage.fact.v1 is excluded). */
    private const array STRICT_P4_UNIT_SCHEMAS = [
        'usage.metric.v1',
        'usage.fact.v2',
        'usage.quantity.v1',
        'usage.aggregate.v1',
        'rating.price.v1',
        'rating.basis.v1',
    ];

    public static function validate(stdClass $value, string $revision = SpecRevision::CURRENT): void
    {
        self::walk($value);
        self::validateTokenProfile($value, $revision);
        if ($value->schema === 'usage.aggregate.v1') {
            $completeness = self::object($value->completeness);
            $window = self::object($value->window);
            if ($completeness->status === 'final') {
                self::require(self::instant(self::text($completeness->watermark)) >= self::instant(self::text($window->end)), 'final watermark precedes window end');
            }
        }
        if ($value->schema === 'usage.fact.v2' && isset($value->correction)) {
            self::require(self::object($value->correction)->supersedesRevision < $value->revision, 'correction must supersede an earlier revision');
        }
        if ($value->schema === 'period.commercial.v1') {
            self::instant(self::text($value->localStart).'Z');
            self::instant(self::text($value->localEnd).'Z');
        }
        if (isset($value->period)) {
            $period = self::object($value->period);
            self::require($value->sellerRef === $period->sellerRef, 'period seller mismatch');
            if (isset($value->componentRef)) {
                self::require($value->componentRef === $period->componentRef, 'period component mismatch');
                self::require((new Codec)->canonicalize($value->interval) === (new Codec)->canonicalize($period->interval), 'period interval mismatch');
            }
            if (isset($value->attribution)) {
                $attribution = self::object($value->attribution);
                self::require($attribution->componentRef === $period->componentRef
                    && $attribution->agreementRef === $period->agreementRef
                    && $attribution->agreementRevision === $period->agreementRevision, 'period attribution mismatch');
            }
        }
        if (isset($value->ratedBasis)) {
            self::require($value->sellerRef === self::object($value->ratedBasis)->sellerRef, 'basis seller mismatch');
        }
        if ($value->schema === 'rating.basis.v1' && self::object($value->quantityEvidence)->kind === 'fixed') {
            self::require($value->quantity === '1', 'fixed quantity must be one');
        }
        if ($value->schema === 'rating.basis.v1' && isset($value->correction)) {
            $correction = self::object($value->correction);
            self::require($correction->originalRatedBasisRef !== $value->ratedBasisRef
                && $correction->originalPeriodRef === self::object($value->period)->periodRef, 'correction must link the original basis/period');
            $amount = self::object($value->amount);
            self::require(self::object($correction->previouslyBookedAmount)->currency === $amount->currency
                && self::object($correction->recomputedAmount)->currency === $amount->currency, 'correction currency mismatch');
        }
    }

    private static function validateTokenProfile(stdClass $envelope, string $revision): void
    {
        $schema = is_string($envelope->schema ?? null) ? $envelope->schema : '';
        $unit = is_string($envelope->unit ?? null) ? $envelope->unit : null;

        if ($unit === 'token' && $revision === '2026-10' && in_array($schema, self::STRICT_P4_UNIT_SCHEMAS, true)) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'unit "token" is not registered in spec revision 2026-10',
            );
        }

        if ($unit === 'token' && $revision === '2026-12' && in_array($schema, self::STRICT_P4_UNIT_SCHEMAS, true)) {
            $quantity = self::extractQuantity($envelope);
            if ($quantity !== null && preg_match('/^(0|[1-9][0-9]{0,23})(\.0{1,9})?$/D', $quantity) !== 1) {
                throw new ProblemException(
                    ProblemCode::ValidationInvalid,
                    'BC-ENV-45: token quantity must be a non-negative integer',
                );
            }
        }

        if ($schema === 'rating.price.v1' && isset($envelope->strategy) && $envelope->strategy instanceof stdClass) {
            $strategy = $envelope->strategy;
            if (($strategy->id ?? null) === 'per_unit' && ($strategy->version ?? null) === 2) {
                if ($revision === '2026-10') {
                    throw new ProblemException(
                        ProblemCode::ValidationInvalid,
                        'strategy per_unit@2 is not registered in spec revision 2026-10',
                    );
                }
                $conv = $strategy->quantityConversion ?? null;
                if (! $conv instanceof stdClass
                    || ! is_string($conv->numerator ?? null)
                    || ! is_string($conv->denominator ?? null)
                    || ! is_numeric($conv->numerator)
                    || ! is_numeric($conv->denominator)
                    || ! is_int($conv->quantityScale ?? null)
                    || $conv->quantityScale < 0
                    || $conv->quantityScale > 12
                    || bccomp($conv->denominator, '0', 0) <= 0) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'BC-ENV-46: invalid quantityConversion');
                }
                $scaledUnit = bcmul($conv->numerator, bcpow('10', (string) $conv->quantityScale, 0), 0);
                if (bcmod($scaledUnit, $conv->denominator, 0) !== '0') {
                    throw new ProblemException(
                        ProblemCode::ValidationInvalid,
                        'BC-ENV-46: quantityConversion is not exact for a single unit at quantityScale',
                    );
                }
            }
        }
    }

    private static function extractQuantity(stdClass $envelope): ?string
    {
        if (($envelope->schema ?? null) === 'usage.fact.v2'
            && ($envelope->value ?? null) instanceof stdClass
            && ($envelope->value->kind ?? null) === 'decimal'
            && is_string($envelope->value->quantity ?? null)) {
            return $envelope->value->quantity;
        }
        if (in_array($envelope->schema ?? null, ['usage.quantity.v1', 'usage.aggregate.v1', 'rating.basis.v1'], true)
            && is_string($envelope->quantity ?? null)) {
            return $envelope->quantity;
        }

        return null;
    }

    private static function walk(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::walk($item);
            }

            return;
        }
        if (! $value instanceof stdClass) {
            return;
        }
        if (isset($value->start, $value->end)) {
            self::require(self::instant(self::text($value->start)) < self::instant(self::text($value->end)), 'interval must be nonempty [start,end)');
        }
        if (isset($value->currency)) {
            Currency::of(self::text($value->currency));
        }
        foreach (['eventAt', 'receivedAt', 'effectiveAt', 'watermark'] as $timestamp) {
            if (isset($value->{$timestamp})) {
                self::instant(self::text($value->{$timestamp}));
            }
        }
        if (isset($value->minimumMinor, $value->maximumMinor)) {
            self::require(self::compare(self::text($value->minimumMinor), self::text($value->maximumMinor)) <= 0
                && self::compare(self::text($value->maximumMinor), '9223372036854775807') <= 0, 'invalid monetary bounds');
        }
        if (isset($value->tiers)) {
            $previous = null;
            foreach (self::items($value->tiers) as $tier) {
                $threshold = self::text(self::object($tier)->upTo);
                self::require($previous === null || self::compareDecimal($previous, $threshold) < 0, 'tiers must strictly ascend');
                self::require(self::compareDecimal('0', $threshold) < 0, 'tier threshold must be positive');
                $previous = $threshold;
            }
        }
        if (isset($value->grouping)) {
            $names = array_map(static fn (mixed $dimension): string => self::text(self::object($dimension)->name), self::items($value->grouping));
            self::require(count(array_unique($names)) === count($names), 'duplicate grouping dimension');
        }
        foreach (get_object_vars($value) as $item) {
            self::walk($item);
        }
    }

    private static function instant(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T([0-2]\d):([0-5]\d):([0-5]\d)(?:\.(\d{1,9}))?Z$/D', $value, $parts) !== 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'invalid UTC timestamp');
        }
        self::require(checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) && (int) $parts[4] < 24, 'invalid UTC date');

        return substr($value, 0, 19).'.'.str_pad($parts[7] ?? '', 9, '0');
    }

    private static function compare(string $a, string $b): int
    {
        $lengthOrder = strlen($a) <=> strlen($b);

        return $lengthOrder !== 0 ? $lengthOrder : strcmp($a, $b);
    }

    private static function compareDecimal(string $a, string $b): int
    {
        [$ai, $af] = array_pad(explode('.', $a, 2), 2, '');
        [$bi, $bf] = array_pad(explode('.', $b, 2), 2, '');

        $integerOrder = self::compare($ai, $bi);

        return $integerOrder !== 0 ? $integerOrder : strcmp(str_pad($af, 9, '0'), str_pad($bf, 9, '0'));
    }

    private static function require(bool $condition, string $detail): void
    {
        if (! $condition) {
            throw new ProblemException(ProblemCode::ValidationInvalid, $detail);
        }
    }

    private static function object(mixed $value): stdClass
    {
        return $value instanceof stdClass ? $value : throw new ProblemException(ProblemCode::ValidationInvalid, 'expected object');
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new ProblemException(ProblemCode::ValidationInvalid, 'expected string');
    }

    /** @return list<mixed> */
    private static function items(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : throw new ProblemException(ProblemCode::ValidationInvalid, 'expected list');
    }
}

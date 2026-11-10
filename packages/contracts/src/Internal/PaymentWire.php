<?php

declare(strict_types=1);

namespace Billium\Contracts\Internal;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use DateTimeImmutable;
use stdClass;

/** Cross-member wire validation only; no provider execution or authority inference (BC-CAP-02/03). */
final class PaymentWire
{
    public static function validate(stdClass $value): void
    {
        if (in_array($value->schema, ['payment.capture.v1', 'payment.void_authorization.v1'], true)
            && $value->operationRef === $value->originalOperationRef) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Child operation must differ from its root');
        }
        if ($value->schema !== 'payment.driver_lookup.v1' || $value->status !== 'certified_absent') {
            return;
        }
        $certificate = $value->absenceCertificate;
        if (! $certificate instanceof stdClass) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Missing absence certificate');
        }
        foreach (['merchantAccountRef', 'environment', 'selectorKind', 'selectorValue'] as $key) {
            if ($value->{$key} !== $certificate->{$key}) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Absence certificate scope mismatch');
            }
        }
        $from = new DateTimeImmutable(self::text($certificate, 'dispatchFrom'));
        $through = new DateTimeImmutable(self::text($certificate, 'dispatchThrough'));
        if ($from > $through || new DateTimeImmutable(self::text($certificate, 'retainedFrom')) > $from
            || new DateTimeImmutable(self::text($certificate, 'providerCompleteThrough')) < $through) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Absence certificate has incomplete coverage');
        }
    }

    private static function text(stdClass $certificate, string $key): string
    {
        $value = $certificate->{$key} ?? null;

        return is_string($value) ? $value : throw new ProblemException(ProblemCode::ValidationInvalid, 'Expected certificate string');
    }
}

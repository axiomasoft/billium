<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Import;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Platform\Data\Import\ImportRecordInput;
use Billium\Platform\Data\Import\ImportRecordKind;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Internal;
use stdClass;

/**
 * Deterministic builder for payment.observation.v1 envelopes from proven external import records.
 *
 * Enforces D71 invariants:
 * - Never fabricates missing providerAccountRef or invents fake providerOperationId values.
 * - Never promotes unknown / accepted-unknown remote outcomes into succeeded/paid observations.
 * - Uses compound partition identity over (sourceInstallation, sourceBook, sellerRef, environment).
 * - Preserves deterministic eventId, sourceLineage and operationRef across batch repackaging and retries.
 */
#[Internal]
final readonly class ImportObservationEnvelopeBuilder
{
    private const string CROCKFORD_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function __construct(
        private Codec $codec = new Codec,
    ) {}

    public function partitionRef(
        string $sourceInstallation,
        string $sourceBook,
        string $sellerRef,
        string $environment,
    ): string {
        $canonical = $this->codec->canonicalize([
            'environment' => $environment,
            'sellerRef' => $sellerRef,
            'sourceBook' => $sourceBook,
            'sourceInstallation' => $sourceInstallation,
        ]);

        return 'imp-part:'.substr(hash('sha256', $canonical), 0, 40);
    }

    public function sourceLineage(
        string $installationRef,
        string $sellerRef,
        string $environment,
        string $sourceInstallation,
        string $sourceBook,
    ): string {
        $canonical = $this->codec->canonicalize([
            'environment' => $environment,
            'installationRef' => $installationRef,
            'sellerRef' => $sellerRef,
            'sourceBook' => $sourceBook,
            'sourceInstallation' => $sourceInstallation,
        ]);

        return 'imp-lin:'.substr(hash('sha256', $canonical), 0, 40);
    }

    public function deterministicEventId(string $sourceFactRef): string
    {
        $bytes = hash('sha256', 'billium.import.observation.event:'.$sourceFactRef, true);
        // Build a 128-bit value from the first 16 bytes and encode into 26 Crockford Base32 chars (2 + 25*5 = 127+3 bits).
        $bits = '';
        for ($i = 0; $i < 16; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }
        // Prepend two zero bits so 130 bits divide into 26 x 5-bit groups with leading digit in 0..7.
        $padded = '00'.$bits;
        $ulid = '';
        for ($group = 0; $group < 26; $group++) {
            $chunk = substr($padded, $group * 5, 5);
            $index = (int) bindec($chunk);
            $ulid .= self::CROCKFORD_ALPHABET[$index];
        }

        return $ulid;
    }

    public function operationRef(ImportRecordInput $record): string
    {
        $funding = $record->fundingRefs ?? [];
        $refs = $record->originalBusinessRefs;

        if ($record->identity->recordKind === ImportRecordKind::ProvenRefundEvidence) {
            $candidate = self::nonEmptyString($refs['refundRef'] ?? null)
                ?? self::nonEmptyString($funding['providerOperationRef'] ?? null)
                ?? ('imp-rfnd:'.$record->identity->sourceRecordId);

            return self::boundedRef($candidate, 'imp-rfnd');
        }

        $candidate = self::nonEmptyString($funding['originalOperationRef'] ?? null)
            ?? self::nonEmptyString($refs['paymentRef'] ?? null)
            ?? self::nonEmptyString($funding['providerOperationRef'] ?? null)
            ?? ('imp-pay:'.$record->identity->sourceRecordId);

        return self::boundedRef($candidate, 'imp-pay');
    }

    public function businessIntentRef(ImportRecordInput $record, ?string $resolvedOperationRef = null): string
    {
        $refs = $record->originalBusinessRefs;
        $explicit = self::nonEmptyString($refs['settlementIntentRef'] ?? null)
            ?? self::nonEmptyString($refs['businessIntentRef'] ?? null);
        if ($explicit !== null) {
            return self::boundedRef($explicit, 'imp-int');
        }
        $opRef = $resolvedOperationRef ?? $this->operationRef($record);

        return self::boundedRef('imp-intent:'.$opRef, 'imp-int');
    }

    /**
     * Builds and Codec-validates a canonical payment.observation.v1 stdClass envelope.
     */
    public function build(
        ImportRecordInput $record,
        string $sourceAuthority,
        string $sourceFactRef,
        int $sourceSequence,
        ?string $resolvedOriginalPaymentRef = null,
        ?DateTimeImmutable $recordedAt = null,
    ): stdClass {
        if ($sourceSequence < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Observation sourceSequence must be >= 1');
        }
        $identity = $record->identity;
        if (! in_array($identity->recordKind, [ImportRecordKind::ProvenPaymentEvidence, ImportRecordKind::ProvenRefundEvidence], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Only proven payment or refund records map to payment.observation.v1');
        }
        if ($record->money === null || $record->money->minor <= 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Observation requires positive proven Money');
        }
        $funding = $record->fundingRefs ?? [];
        $providerAccountRef = self::nonEmptyString($funding['providerAccountRef'] ?? null);
        if ($providerAccountRef === null) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'Observation requires explicit fundingRefs.providerAccountRef; synthetic provider accounts are forbidden',
            );
        }

        $fundingStatus = strtolower(trim(self::nonEmptyString($funding['fundingStatus'] ?? null) ?? 'captured'));
        if (in_array($fundingStatus, ['unknown', 'accepted-unknown', 'pending_unknown', 'unverified'], true)) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'Unresolved remote funding status cannot be projected as a paid observation',
            );
        }
        $wireState = match ($fundingStatus) {
            'captured', 'succeeded', 'settled', 'refunded', 'completed' => 'succeeded',
            'pending', 'authorized' => 'pending',
            'failed', 'failed-terminal' => 'failed-terminal',
            'cancelled', 'voided' => 'cancelled',
            default => throw new ProblemException(
                ProblemCode::ValidationInvalid,
                "Unsupported fundingStatus '{$fundingStatus}' for proven observation",
            ),
        };

        $isRefund = $identity->recordKind === ImportRecordKind::ProvenRefundEvidence;
        $operationKind = $isRefund ? 'refund' : 'charge';
        $operationRef = $this->operationRef($record);
        $currency = $record->money->currency->code;
        $zero = new Money(0, Currency::of($currency));

        if ($isRefund) {
            $originalPaymentRef = $resolvedOriginalPaymentRef
                ?? self::nonEmptyString($funding['originalOperationRef'] ?? null);
            if ($originalPaymentRef === null) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Refund observation requires originalOperationRef');
            }
            $requested = $record->money;
            $authorizedTotal = $wireState === 'succeeded' ? $record->money : $zero;
            $capturedTotal = $wireState === 'succeeded' ? $record->money : $zero;
            $refundedTotal = $zero;
            $businessIntentRef = self::boundedRef('imp-rfnd-intent:'.$operationRef, 'imp-rint');
        } else {
            $originalPaymentRef = null;
            $requested = $record->money;
            $authorizedTotal = self::parseOptionalMoney($funding['authorizedTotal'] ?? null, $currency) ?? $record->money;
            $capturedTotal = $wireState === 'succeeded'
                ? (self::parseOptionalMoney($funding['capturedTotal'] ?? null, $currency) ?? $record->money)
                : $zero;
            $refundedTotal = self::parseOptionalMoney($funding['refundedTotal'] ?? null, $currency) ?? $zero;
            $businessIntentRef = $this->businessIntentRef($record, $operationRef);
        }

        $evidenceRef = $record->evidenceRefs !== []
            ? $record->evidenceRefs[0]->evidenceRef
            : self::boundedRef('ev:'.$sourceFactRef, 'ev');

        $observedAt = self::parseTimestamp($funding['observedAt'] ?? null) ?? $record->eventBusinessDate;
        $effectiveRecordedAt = $recordedAt ?? $record->exportCutoff;

        $operationRevision = max(1, $record->sourceRecordRevision);
        $body = [
            'schema' => 'payment.observation.v1',
            'eventId' => $this->deterministicEventId($sourceFactRef),
            'sourceAuthority' => $sourceAuthority,
            'sourceLineage' => $this->sourceLineage(
                $identity->installationRef,
                $identity->sellerRef,
                $identity->environment,
                $identity->sourceInstallation,
                $identity->sourceBook,
            ),
            'partitionRef' => $this->partitionRef(
                $identity->sourceInstallation,
                $identity->sourceBook,
                $identity->sellerRef,
                $identity->environment,
            ),
            'sourceSequence' => $sourceSequence,
            'installationRef' => $identity->installationRef,
            'environment' => $identity->environment,
            'sellerRef' => $identity->sellerRef,
            'operationRef' => $operationRef,
            'operationKind' => $operationKind,
            'businessIntentRef' => $businessIntentRef,
            'operationRevision' => $operationRevision,
            'state' => $wireState,
            'currency' => $currency,
            'requested' => $requested->toWire(),
            'authorizedTotal' => $authorizedTotal->toWire(),
            'capturedTotal' => $capturedTotal->toWire(),
            'refundedTotal' => $refundedTotal->toWire(),
            'providerAccountRef' => $providerAccountRef,
            'evidenceRef' => $evidenceRef,
            'observedAt' => $observedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'recordedAt' => $effectiveRecordedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        ];
        if ($originalPaymentRef !== null) {
            $body['originalPaymentRef'] = self::boundedRef($originalPaymentRef, 'imp-orig');
        }
        // Only propagate providerOperationId when explicitly proven in source evidence; never synthesize fake PSP IDs.
        $explicitProviderOpId = self::nonEmptyString($funding['providerOperationRef'] ?? null);
        if ($explicitProviderOpId !== null) {
            $body['providerOperationId'] = self::boundedRef($explicitProviderOpId, 'psp-op');
        }
        if (is_array($funding['discrepancy'] ?? null)) {
            $body['discrepancy'] = $funding['discrepancy'];
        }

        $body['contentDigest'] = $this->codec->digest($body, 'contentDigest');
        $canonicalJson = $this->codec->canonicalize($body);
        $decoded = $this->codec->decode($canonicalJson);
        if (! $decoded instanceof PaymentObservation) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Constructed import observation failed payment.observation.v1 validation');
        }

        return $decoded->raw;
    }

    private static function parseOptionalMoney(mixed $raw, string $expectedCurrency): ?Money
    {
        if (! is_array($raw)) {
            return null;
        }
        $money = Money::fromWire($raw);
        if ($money->currency->code !== $expectedCurrency) {
            throw new ProblemException(ProblemCode::MoneyCurrencyMismatch, 'Observation funding total currency mismatch');
        }

        return $money;
    }

    private static function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return new DateTimeImmutable($value);
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' || str_contains($trimmed, "\0") ? null : $trimmed;
    }

    private static function boundedRef(string $value, string $prefix): string
    {
        if (strlen($value) <= 200) {
            return $value;
        }

        return $prefix.':'.hash('sha256', $value);
    }
}

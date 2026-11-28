<?php

declare(strict_types=1);

namespace Billium\Documents\Internal\Reducers;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Documents\Data\DocumentFinalizationView;
use Billium\Documents\Data\DocumentLineBasis;
use Billium\Documents\Data\DocumentProfile;
use Billium\Documents\Data\DocumentTaxLineSnapshot;
use Billium\Documents\Data\DocumentTaxSnapshot;
use Billium\Documents\Data\TaxSnapshotRequest;
use Billium\Documents\Enums\DiscountTaxOrder;
use Billium\Documents\Enums\TaxConvention;
use Billium\Documents\Internal\Canonical\CanonicalJson;
use Billium\Documents\Internal\Math\Dec;
use Billium\Documents\Internal\Support\RowCast;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DocumentSnapshotAssembler
{
    /** @var list<string> */
    private const array ALLOWED_ROUNDING_MODES = ['down', 'up', 'half_up', 'half_even'];

    /**
     * Pure validation of draft/revision inputs before persistence.
     *
     * @param  list<DocumentLineBasis>  $lines
     */
    public function validateDraftInputs(
        int $basisRevision,
        string $currencyCode,
        int $currencyExponent,
        string $roundingMode,
        array $lines,
    ): Currency {
        if ($basisRevision < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'basisRevision must be positive');
        }

        $currency = Currency::of($currencyCode);
        if ($currency->scale !== $currencyExponent) {
            throw new ProblemException(ProblemCode::MoneyScaleInvalid, 'currency exponent mismatch for '.$currency->code);
        }

        if (! in_array($roundingMode, self::ALLOWED_ROUNDING_MODES, true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'unsupported rounding mode: '.$roundingMode);
        }

        $this->validateLines($lines);

        return $currency;
    }

    /**
     * @param  list<DocumentLineBasis>  $lines
     * @return list<array<string, mixed>>
     */
    public function serializeLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $out[] = [
                'lineRef' => $line->lineRef,
                'obligationRef' => $line->obligationRef,
                'componentRef' => $line->componentRef,
                'quantity' => $line->quantity,
                'unitPriceDecimal' => $line->unitPriceDecimal,
                'netMinor' => $line->netMinor,
                'discountMinor' => $line->discountMinor,
                'taxCode' => $line->taxCode,
                'taxRate' => $line->taxRate,
                'description' => $line->description,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $buyerSnapshot
     * @param  array<string, mixed>  $termsSnapshot
     */
    public function computeInputDigest(
        string $sellerRef,
        string $environment,
        string $finalizationRef,
        string $documentType,
        string $economicRef,
        int $basisRevision,
        string $basisDigest,
        int $profileId,
        int $profileVersion,
        string $currency,
        int $currencyExponent,
        string $convention,
        string $roundingMode,
        string $discountTaxOrder,
        array $lines,
        array $buyerSnapshot,
        array $termsSnapshot,
    ): string {
        return CanonicalJson::digest([
            'sellerRef' => $sellerRef,
            'environment' => $environment,
            'finalizationRef' => $finalizationRef,
            'documentType' => $documentType,
            'economicRef' => $economicRef,
            'basisRevision' => $basisRevision,
            'basisDigest' => $basisDigest,
            'profileId' => $profileId,
            'profileVersion' => $profileVersion,
            'currency' => $currency,
            'currencyExponent' => $currencyExponent,
            'convention' => $convention,
            'roundingMode' => $roundingMode,
            'discountTaxOrder' => $discountTaxOrder,
            'lines' => $lines,
            'buyerSnapshot' => $buyerSnapshot,
            'termsSnapshot' => $termsSnapshot,
        ]);
    }

    /** @return array<string, mixed> */
    public function serializeTaxSnapshot(DocumentTaxSnapshot $snapshot): array
    {
        $lines = [];
        foreach ($snapshot->lines as $line) {
            $lines[] = [
                'lineRef' => $line->lineRef,
                'taxCode' => $line->taxCode,
                'rateDecimal' => $line->rateDecimal,
                'netMinor' => $line->netMinor,
                'taxMinor' => $line->taxMinor,
                'grossMinor' => $line->grossMinor,
            ];
        }

        return [
            'taxSnapshotRef' => $snapshot->taxSnapshotRef,
            'taxPolicyVersion' => $snapshot->taxPolicyVersion,
            'taxPoint' => $snapshot->taxPoint,
            'convention' => $snapshot->convention->value,
            'currency' => $snapshot->currency,
            'currencyExponent' => $snapshot->currencyExponent,
            'subtotalMinor' => $snapshot->subtotalMinor,
            'taxMinor' => $snapshot->taxMinor,
            'totalMinor' => $snapshot->totalMinor,
            'lines' => $lines,
            'inputDigest' => $snapshot->inputDigest,
            'evidenceDigest' => $snapshot->evidenceDigest,
            'resolver' => $snapshot->resolver,
            'resolverVersion' => $snapshot->resolverVersion,
            'noTaxReason' => $snapshot->noTaxReason,
        ];
    }

    /** @param array<string, mixed> $payload */
    public function hydrateTaxSnapshot(array $payload): DocumentTaxSnapshot
    {
        $rawLines = $payload['lines'] ?? null;
        if (! is_array($rawLines)) {
            throw new LogicException('Expected snapshot lines array');
        }
        $lines = [];
        foreach ($rawLines as $raw) {
            if (! is_array($raw)) {
                throw new LogicException('Expected snapshot line object');
            }
            $lines[] = new DocumentTaxLineSnapshot(
                lineRef: RowCast::str($raw['lineRef'] ?? null, 'lineRef'),
                taxCode: RowCast::str($raw['taxCode'] ?? null, 'taxCode'),
                rateDecimal: RowCast::str($raw['rateDecimal'] ?? null, 'rateDecimal'),
                netMinor: RowCast::integer($raw['netMinor'] ?? null, 'netMinor'),
                taxMinor: RowCast::integer($raw['taxMinor'] ?? null, 'taxMinor'),
                grossMinor: RowCast::integer($raw['grossMinor'] ?? null, 'grossMinor'),
            );
        }

        return new DocumentTaxSnapshot(
            taxSnapshotRef: RowCast::str($payload['taxSnapshotRef'] ?? null, 'taxSnapshotRef'),
            taxPolicyVersion: RowCast::str($payload['taxPolicyVersion'] ?? null, 'taxPolicyVersion'),
            taxPoint: RowCast::str($payload['taxPoint'] ?? null, 'taxPoint'),
            convention: TaxConvention::from(RowCast::str($payload['convention'] ?? null, 'convention')),
            currency: RowCast::str($payload['currency'] ?? null, 'currency'),
            currencyExponent: RowCast::integer($payload['currencyExponent'] ?? null, 'currencyExponent'),
            subtotalMinor: RowCast::integer($payload['subtotalMinor'] ?? null, 'subtotalMinor'),
            taxMinor: RowCast::integer($payload['taxMinor'] ?? null, 'taxMinor'),
            totalMinor: RowCast::integer($payload['totalMinor'] ?? null, 'totalMinor'),
            lines: $lines,
            inputDigest: RowCast::str($payload['inputDigest'] ?? null, 'inputDigest'),
            evidenceDigest: RowCast::str($payload['evidenceDigest'] ?? null, 'evidenceDigest'),
            resolver: RowCast::str($payload['resolver'] ?? null, 'resolver'),
            resolverVersion: RowCast::str($payload['resolverVersion'] ?? null, 'resolverVersion'),
            noTaxReason: RowCast::optStr($payload['noTaxReason'] ?? null, 'noTaxReason'),
        );
    }

    /** @param array<string, mixed> $taxSnapshotPayload */
    public function computeTaxSnapshotDigest(array $taxSnapshotPayload): string
    {
        return CanonicalJson::digest($taxSnapshotPayload);
    }

    public function computeApprovedSnapshotDigest(
        string $finalizationRef,
        int $finalizationRevision,
        int $basisRevision,
        string $basisDigest,
        string $inputDigest,
        string $taxSnapshotDigest,
        int $profileId,
        int $profileVersion,
        DocumentTaxSnapshot $snapshot,
    ): string {
        return CanonicalJson::digest([
            'finalizationRef' => $finalizationRef,
            'finalizationRevision' => $finalizationRevision,
            'basisRevision' => $basisRevision,
            'basisDigest' => $basisDigest,
            'inputDigest' => $inputDigest,
            'taxSnapshotDigest' => $taxSnapshotDigest,
            'profileId' => $profileId,
            'profileVersion' => $profileVersion,
            'currency' => $snapshot->currency,
            'subtotalMinor' => $snapshot->subtotalMinor,
            'taxMinor' => $snapshot->taxMinor,
            'totalMinor' => $snapshot->totalMinor,
        ]);
    }

    public function buildTaxSnapshotRequest(
        DocumentFinalizationView $finalization,
        DocumentProfile $profile,
        TaxConvention $convention,
        string $roundingMode,
        DiscountTaxOrder $discountTaxOrder,
    ): TaxSnapshotRequest {
        return new TaxSnapshotRequest(
            sellerRef: $finalization->sellerRef,
            environment: $finalization->environment,
            finalizationRef: $finalization->finalizationRef,
            finalizationRevision: $finalization->finalizationRevision,
            documentType: $finalization->documentType,
            economicRef: $finalization->economicRef,
            basisRevision: $finalization->basisRevision,
            basisDigest: $finalization->basisDigest,
            inputDigest: $finalization->inputDigest,
            taxSnapshotSchema: $profile->taxSnapshotSchema,
            jurisdictionRef: $profile->jurisdictionRef,
            currency: $finalization->currency,
            currencyExponent: $finalization->currencyExponent,
            convention: $convention,
            roundingMode: $roundingMode,
            discountTaxOrder: $discountTaxOrder,
            lines: $finalization->lines,
            buyerSnapshot: $finalization->buyerSnapshot,
            sellerSnapshot: $profile->sellerSnapshot,
        );
    }

    /** @return list<array<string, mixed>> */
    public function serializeFinalizationLines(
        DocumentFinalizationView $finalization,
        string $defaultRoundingPolicyVersion,
    ): array {
        $taxByLine = [];
        if ($finalization->taxSnapshot !== null) {
            foreach ($finalization->taxSnapshot->lines as $line) {
                $taxByLine[$line->lineRef] = $line;
            }
        }

        $linePolicies = is_array($finalization->termsSnapshot['lineRoundingPolicies'] ?? null)
            ? $finalization->termsSnapshot['lineRoundingPolicies']
            : [];

        $out = [];
        foreach ($finalization->lines as $line) {
            $t = $taxByLine[$line->lineRef] ?? null;
            $lineSpecific = $linePolicies[$line->lineRef] ?? null;
            $lineRounding = is_string($lineSpecific) && trim($lineSpecific) !== ''
                ? $lineSpecific
                : $defaultRoundingPolicyVersion;

            $out[] = [
                'lineRef' => $line->lineRef,
                'obligationRef' => $line->obligationRef,
                'componentRef' => $line->componentRef,
                'quantity' => $line->quantity,
                'unitPriceDecimal' => $line->unitPriceDecimal,
                'netMinor' => $t->netMinor ?? $line->netMinor,
                'discountMinor' => $line->discountMinor,
                'taxCode' => $line->taxCode,
                'taxRate' => $t->rateDecimal ?? $line->taxRate,
                'taxMinor' => $t->taxMinor ?? 0,
                'grossMinor' => $t->grossMinor ?? $line->netMinor,
                'roundingPolicyVersion' => $lineRounding,
                'description' => $line->description,
            ];
        }

        return $out;
    }

    /** @param list<DocumentLineBasis> $lines */
    private function validateLines(array $lines): void
    {
        if ($lines === []) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'document draft requires at least one line');
        }
        $seen = [];
        foreach ($lines as $line) {
            RowCast::bounded($line->lineRef, 'lineRef');
            RowCast::bounded($line->obligationRef, 'obligationRef');
            RowCast::bounded($line->componentRef, 'componentRef');
            if (isset($seen[$line->lineRef])) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'duplicate lineRef: '.$line->lineRef);
            }
            $seen[$line->lineRef] = true;
            if ($line->netMinor < 0 || $line->discountMinor < 0) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'line minor amounts must be non-negative');
            }
            Dec::parse($line->quantity);
            Dec::parse($line->unitPriceDecimal);
            Dec::parse($line->taxRate);
        }
    }
}

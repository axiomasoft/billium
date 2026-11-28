<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Import\Support;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Platform\Data\Import\ImportRecordKind;
use Billium\Platform\Internal\Import\Sources\GenericExportProfile;
use Billium\Platform\Internal\Import\Sources\GenericExportReader;

/**
 * Product-agnostic sanitized export artifact builders for ImportScenarioTest.
 */
final class ImportScenarioFixtures
{
    public const string DIGEST_1 = 'sha256:1111111111111111111111111111111111111111111111111111111111111111';

    public const string DIGEST_2 = 'sha256:2222222222222222222222222222222222222222222222222222222222222222';

    public const string DIGEST_3 = 'sha256:3333333333333333333333333333333333333333333333333333333333333333';

    public const string DIGEST_4 = 'sha256:4444444444444444444444444444444444444444444444444444444444444444';

    /**
     * @param  list<array<string, mixed>>  $rawRecords
     */
    public static function readerForRecords(
        string $installationRef,
        string $batchId,
        string $sellerRef,
        array $rawRecords,
    ): GenericExportReader {
        $doc = self::artifactForRecords($installationRef, $batchId, $sellerRef, $rawRecords);

        return GenericExportReader::fromJson(json_encode($doc, JSON_THROW_ON_ERROR), new GenericExportProfile, 'import-policy-v1', new Codec);
    }

    /**
     * @param  list<array<string, mixed>>  $rawRecords
     * @return array<string, mixed>
     */
    public static function artifactForRecords(
        string $installationRef,
        string $batchId,
        string $sellerRef,
        array $rawRecords,
    ): array {
        $codec = new Codec;
        $grouped = [];
        foreach ($rawRecords as $r) {
            if (! is_array($r['money'] ?? null) || ! is_string($r['money']['currency'] ?? null)) {
                continue;
            }
            $kind = match ($r['recordType'] ?? '') {
                'obligation' => ImportRecordKind::ProvenObligation->value,
                'allocation' => ImportRecordKind::ProvenAllocation->value,
                'payment_evidence' => ImportRecordKind::ProvenPaymentEvidence->value,
                'refund_evidence' => ImportRecordKind::ProvenRefundEvidence->value,
                'historical_invoice' => ImportRecordKind::ProvenInvoiceHistory->value,
                default => null,
            };
            if ($kind === null) {
                continue;
            }
            $curr = $r['money']['currency'];
            $money = Money::fromWire($r['money']);
            $key = $curr.'|'.$kind;
            if (! isset($grouped[$key])) {
                $grouped[$key] = ['currency' => $curr, 'recordKind' => $kind, 'count' => 0, 'minor' => 0];
            }
            $grouped[$key]['count']++;
            $grouped[$key]['minor'] += $money->minor;
        }
        ksort($grouped, SORT_STRING);
        $declaredTotals = [];
        foreach ($grouped as $g) {
            $declaredTotals[] = [
                'sellerRef' => $sellerRef,
                'currency' => $g['currency'],
                'recordKind' => $g['recordKind'],
                'count' => $g['count'],
                'totalAmount' => (new Money($g['minor'], Currency::of($g['currency'])))->toWire(),
                'authoritativeCurrentBalance' => false,
            ];
        }

        return [
            'exportSchema' => 'source.export.v1',
            'profile' => 'generic',
            'exportVersion' => '1.0.0',
            'batchId' => $batchId,
            'sourceSystem' => 'host-billing/export',
            'sourceInstallation' => 'src-inst-shared',
            'sourceBook' => 'book-shared',
            'sourceExportRevision' => 1,
            'exportCutoff' => '2026-10-20T00:00:00Z',
            'installationRef' => $installationRef,
            'environment' => 'test',
            'sellerRef' => $sellerRef,
            'importPolicyVersion' => 'import-policy-v1',
            'sanitizationPolicy' => ['piiRedacted' => true, 'secretsStripped' => true, 'policyVersion' => 'sanitize-v1'],
            'totalsByCurrency' => $declaredTotals,
            'records' => $rawRecords,
            'recordCount' => count($rawRecords),
            'recordsChecksum' => 'sha256:'.hash('sha256', $codec->canonicalize($rawRecords)),
        ];
    }

    /** @return array<string, mixed> */
    public static function obligation(
        string $id,
        string $amount,
        string $currency,
        string $priceVersionRef,
        ?string $currentPlanAmount = null,
        ?string $currentPlanVersionRef = null,
    ): array {
        $record = [
            'sourceRecordId' => $id,
            'sourceRecordRevision' => 1,
            'recordType' => 'obligation',
            'eventBusinessDate' => '2025-11-01T09:00:00Z',
            'originalBusinessRefs' => [
                'customerRef' => 'cust-1',
                'billingAccountRef' => 'acct-1',
                'obligationRef' => 'src-'.$id,
            ],
            'partyRoles' => ['subjectRef' => 'subj-1', 'payerRef' => 'payer-1', 'actorRef' => 'actor-1', 'customerRef' => 'cust-1', 'billingAccountRef' => 'acct-1'],
            'evidenceRefs' => [[
                'evidenceRef' => 'ev-'.$id,
                'kind' => 'price_snapshot',
                'digest' => self::DIGEST_1,
                'sanitized' => true,
            ]],
            'money' => ['amount' => $amount, 'currency' => $currency],
            'frozenPriceSnapshot' => [
                'basis' => 'frozen_historical',
                'priceVersionRef' => $priceVersionRef,
                'priceDigest' => self::DIGEST_1,
                'currency' => $currency,
                'termsRef' => 'terms-v1',
                'taxPolicyVersion' => 'tax-v1',
            ],
        ];
        if ($currentPlanAmount !== null && $currentPlanVersionRef !== null) {
            $record['currentPlanSnapshot'] = [
                'priceVersionRef' => $currentPlanVersionRef,
                'unitPrice' => ['amount' => $currentPlanAmount, 'currency' => $currency],
            ];
        }

        return $record;
    }

    /** @return array<string, mixed> */
    public static function allocation(string $id, string $amount, string $currency, string $oblRef, string $payRef): array
    {
        return [
            'sourceRecordId' => $id,
            'sourceRecordRevision' => 1,
            'recordType' => 'allocation',
            'eventBusinessDate' => '2025-11-01T09:01:00Z',
            'originalBusinessRefs' => [
                'customerRef' => 'cust-1',
                'billingAccountRef' => 'acct-1',
                'obligationRef' => $oblRef,
                'paymentRef' => $payRef,
            ],
            'partyRoles' => ['subjectRef' => 'subj-1', 'payerRef' => 'payer-1', 'customerRef' => 'cust-1', 'billingAccountRef' => 'acct-1'],
            'evidenceRefs' => [[
                'evidenceRef' => 'ev-'.$id,
                'kind' => 'allocation_lineage',
                'digest' => self::DIGEST_2,
                'sanitized' => true,
            ]],
            'money' => ['amount' => $amount, 'currency' => $currency],
        ];
    }

    /** @return array<string, mixed> */
    public static function payment(string $id, string $amount, string $currency, string $origOpRef, string $payRef, string $executorRef): array
    {
        return [
            'sourceRecordId' => $id,
            'sourceRecordRevision' => 1,
            'recordType' => 'payment_evidence',
            'eventBusinessDate' => '2025-11-01T09:02:00Z',
            'originalBusinessRefs' => [
                'customerRef' => 'cust-1',
                'billingAccountRef' => 'acct-1',
                'paymentRef' => $payRef,
            ],
            'partyRoles' => ['subjectRef' => 'subj-1', 'payerRef' => 'payer-1', 'actorRef' => 'actor-1', 'customerRef' => 'cust-1', 'billingAccountRef' => 'acct-1'],
            'evidenceRefs' => [[
                'evidenceRef' => 'ev-'.$id,
                'kind' => 'payment_provider_fact',
                'digest' => self::DIGEST_3,
                'sanitized' => true,
            ]],
            'money' => ['amount' => $amount, 'currency' => $currency],
            'fundingRefs' => [
                'sourceAuthority' => 'source-gateway',
                'fundingStatus' => 'captured',
                'providerAccountRef' => 'pa-acct-1',
                'providerOperationRef' => 'psp-op-'.$id,
                'originalOperationRef' => $origOpRef,
                'originalExecutorRef' => $executorRef,
                'observedAt' => '2025-11-01T09:02:00Z',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function refund(string $id, string $amount, string $currency, string $origOpRef, string $executorRef): array
    {
        return [
            'sourceRecordId' => $id,
            'sourceRecordRevision' => 1,
            'recordType' => 'refund_evidence',
            'eventBusinessDate' => '2025-11-05T12:00:00Z',
            'originalBusinessRefs' => [
                'customerRef' => 'cust-1',
                'billingAccountRef' => 'acct-1',
                'originalPaymentRef' => $origOpRef,
            ],
            'partyRoles' => ['subjectRef' => 'subj-1', 'payerRef' => 'payer-1', 'actorRef' => 'actor-1', 'customerRef' => 'cust-1', 'billingAccountRef' => 'acct-1'],
            'evidenceRefs' => [[
                'evidenceRef' => 'ev-'.$id,
                'kind' => 'refund_provider_fact',
                'digest' => self::DIGEST_4,
                'sanitized' => true,
            ]],
            'money' => ['amount' => $amount, 'currency' => $currency],
            'fundingRefs' => [
                'sourceAuthority' => 'source-gateway',
                'fundingStatus' => 'refunded',
                'providerAccountRef' => 'pa-acct-1',
                'providerOperationRef' => 'psp-rfnd-'.$id,
                'originalOperationRef' => $origOpRef,
                'originalExecutorRef' => $executorRef,
                'observedAt' => '2025-11-05T12:00:00Z',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function historicalInvoice(string $id, string $amount, string $currency, string $issuer, string $number): array
    {
        return [
            'sourceRecordId' => $id,
            'sourceRecordRevision' => 1,
            'recordType' => 'historical_invoice',
            'eventBusinessDate' => '2025-11-01T09:10:00Z',
            'originalBusinessRefs' => [
                'customerRef' => 'cust-1',
                'invoiceRef' => 'src-'.$id,
                'originalIssuer' => $issuer,
                'sourceNumber' => $number,
            ],
            'partyRoles' => ['subjectRef' => 'subj-1', 'payerRef' => 'payer-1', 'customerRef' => 'cust-1'],
            'evidenceRefs' => [[
                'evidenceRef' => 'ev-'.$id,
                'kind' => 'invoice_document',
                'digest' => self::DIGEST_4,
                'sanitized' => true,
            ]],
            'money' => ['amount' => $amount, 'currency' => $currency],
            'legalDocument' => [
                'originalIssuer' => $issuer,
                'sourceNumber' => $number,
                'legalOwnershipConfirmed' => false,
                'issuedAt' => '2025-11-01T09:10:00Z',
                'renderDigest' => self::DIGEST_4,
            ],
        ];
    }
}

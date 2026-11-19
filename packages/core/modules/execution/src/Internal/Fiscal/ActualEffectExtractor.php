<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Fiscal;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\Fiscal\FiscalActualEffect;
use Billium\Execution\Enums\Fiscal\FiscalOperationKind;
use Billium\Execution\Enums\Fiscal\FiscalReceiptKind;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;

/**
 * Extracts authenticated actual provider effects for a root operation directly from execution custody facts.
 *
 * Never derives fiscal amounts from bounded applied capacity totals or ledger allocation state.
 */
#[Internal]
final readonly class ActualEffectExtractor
{
    public function __construct(
        private DatabaseManager $databases,
        private BilliumOptions $options,
    ) {}

    /** @return list<FiscalActualEffect> */
    public function extractForRoot(ExecutionScope $scope, string $rootOperationRef, string $executionProfile): array
    {
        $db = $this->databases->connection($this->options->connection);
        $operations = $db->table('billium_execution_operation')
            ->where('installation_ref', $scope->installationRef)
            ->where('seller_ref', $scope->sellerRef)
            ->where('environment', $scope->environment)
            ->where('root_operation_ref', $rootOperationRef)
            ->whereIn('kind', ['charge', 'capture', 'refund'])
            ->orderBy('operation_ref')
            ->orderBy('id')
            ->get([
                'operation_ref',
                'root_operation_ref',
                'kind',
                'merchant_revision_ref',
                'merchant_account_ref',
                'currency',
                'state',
            ]);

        $effects = [];
        foreach ($operations as $operation) {
            $opRef = FiscalJson::text($operation->operation_ref);
            $opKind = FiscalJson::text($operation->kind);

            $facts = $db->table('billium_execution_provider_fact')
                ->where('installation_ref', $scope->installationRef)
                ->where('seller_ref', $scope->sellerRef)
                ->where('environment', $scope->environment)
                ->where('operation_ref', $opRef)
                ->orderBy('id')
                ->get([
                    'id',
                    'fact_ref',
                    'evidence_mode',
                    'normalized_snapshot',
                    'evidence_refs',
                ]);

            $chosenStable = [];
            $chosenCumulative = null;
            foreach ($facts as $fact) {
                $snapshot = FiscalJson::decodeObject($fact->normalized_snapshot);
                if (($snapshot['normalizedStatus'] ?? null) !== 'succeeded') {
                    continue;
                }
                $amountData = $snapshot['operationAmount'] ?? null;
                if (! is_array($amountData)) {
                    continue;
                }
                $minor = $amountData['minor'] ?? null;
                if (! is_int($minor) || $minor <= 0) {
                    continue;
                }
                $mode = FiscalJson::text($fact->evidence_mode);
                if ($mode === 'stable_effect_id') {
                    $stableKey = is_string($snapshot['stableEffectId'] ?? null)
                        ? $snapshot['stableEffectId']
                        : FiscalJson::text($fact->fact_ref);
                    if (! array_key_exists($stableKey, $chosenStable)) {
                        $chosenStable[$stableKey] = [$fact, $snapshot, $minor, $amountData];
                    }
                } elseif ($chosenCumulative === null) {
                    $chosenCumulative = [$fact, $snapshot, $minor, $amountData];
                }
            }

            $selectedFacts = $chosenStable !== []
                ? array_values($chosenStable)
                : ($chosenCumulative !== null ? [$chosenCumulative] : []);

            if ($selectedFacts === []) {
                continue;
            }

            $discrepancy = $db->table('billium_execution_discrepancy')
                ->where('installation_ref', $scope->installationRef)
                ->where('seller_ref', $scope->sellerRef)
                ->where('environment', $scope->environment)
                ->where('root_operation_ref', $rootOperationRef)
                ->where('operation_ref', $opRef)
                ->orderBy('id')
                ->first([
                    'discrepancy_ref',
                    'kind',
                    'exposure',
                    'last_consistent_applied',
                    'evidence_refs',
                ]);

            $exceptional = $discrepancy !== null;
            $provenance = [];
            if ($discrepancy !== null) {
                $provenance = [
                    'discrepancyRef' => FiscalJson::text($discrepancy->discrepancy_ref),
                    'discrepancyKind' => FiscalJson::text($discrepancy->kind),
                    'exposure' => FiscalJson::decodeObject($discrepancy->exposure),
                    'lastConsistentApplied' => FiscalJson::decodeObject($discrepancy->last_consistent_applied),
                    'operationRef' => $opRef,
                    'rootOperationRef' => $rootOperationRef,
                ];
            }

            [$fiscalOpKind, $receiptKind] = match ($opKind) {
                'charge' => [FiscalOperationKind::Charge, FiscalReceiptKind::Sale],
                'capture' => [FiscalOperationKind::Capture, FiscalReceiptKind::Sale],
                default => [FiscalOperationKind::Refund, FiscalReceiptKind::Refund],
            };

            foreach ($selectedFacts as [$fact, $snapshot, $minor, $amountData]) {
                $currencyCode = is_array($amountData['currency'] ?? null) && is_string($amountData['currency']['code'] ?? null)
                    ? $amountData['currency']['code']
                    : FiscalJson::text($operation->currency);
                $currencyScale = is_array($amountData['currency'] ?? null) && is_int($amountData['currency']['scale'] ?? null)
                    ? $amountData['currency']['scale']
                    : Currency::of($currencyCode)->scale;

                $evidenceRefs = json_decode(FiscalJson::text($fact->evidence_refs), true, 16, JSON_THROW_ON_ERROR);
                $primaryEvidence = is_array($evidenceRefs) && is_string($evidenceRefs[0] ?? null)
                    ? $evidenceRefs[0]
                    : FiscalJson::text($fact->fact_ref);

                $effects[] = new FiscalActualEffect(
                    scope: $scope,
                    executionProfile: $executionProfile,
                    economicEffectRef: FiscalJson::text($fact->fact_ref),
                    rootOperationRef: $rootOperationRef,
                    operationRef: $opRef,
                    operationKind: $fiscalOpKind,
                    receiptKind: $receiptKind,
                    actualAmount: new Money($minor, FiscalJson::currency($currencyCode, $currencyScale)),
                    merchantRevisionRef: FiscalJson::text($operation->merchant_revision_ref),
                    merchantAccountRef: FiscalJson::text($operation->merchant_account_ref),
                    providerEvidenceRef: $primaryEvidence,
                    exceptional: $exceptional,
                    exposureProvenance: $provenance,
                    receiptPayload: [
                        'sourceFactRef' => FiscalJson::text($fact->fact_ref),
                        'operationRef' => $opRef,
                        'rootOperationRef' => $rootOperationRef,
                        'operationKind' => $opKind,
                        'providerEvidenceRef' => $primaryEvidence,
                    ],
                    confirmedActual: true,
                );
            }
        }

        usort($effects, static fn (FiscalActualEffect $a, FiscalActualEffect $b): int => [$a->operationRef, $a->economicEffectRef] <=> [$b->operationRef, $b->economicEffectRef]);

        return $effects;
    }
}

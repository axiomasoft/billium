<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Services;

use Billium\Collection\Contracts\CollectionConsentGuard;
use Billium\Collection\Contracts\ConsentBindingService;
use Billium\Collection\Contracts\ConsentQueries;
use Billium\Collection\Contracts\DunningQueries;
use Billium\Collection\Data\AuthorizeCollection;
use Billium\Collection\Data\ConsentBindingView;
use Billium\Collection\Data\ConsentChargeEligibilityRequest;
use Billium\Collection\Data\DunningCaseView;
use Billium\Collection\Data\HostConsentAuthoritySnapshot;
use Billium\Collection\Enums\CollectionChannel;
use Billium\Collection\Enums\ConsentPurpose;
use Billium\Collection\Internal\Canonical\CanonicalJson;
use Billium\Collection\Internal\Support\RowCast;
use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Execution\Contracts\Collection\CollectionOriginLookup;
use Billium\Execution\Contracts\Collection\RootIdentityStore;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantActionGate;
use Billium\Execution\Data\Collection\RootPaymentSemanticDigest;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\OriginDecision;
use Billium\Ledger\Contracts\CollectionObligation;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Platform\Contracts\Outbox\CommandOutbox;
use Billium\Platform\Data\EventContracts\PreparedInternalCommand;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class CollectionAdmission
{
    public function __construct(
        private ConsentBindingService $consents,
        private ConsentQueries $queries,
        private DunningQueries $cases,
        private CollectionConsentGuard $host,
        private ExecutionStore $execution,
        private RootIdentityStore $rootIdentities,
        private MerchantActionGate $merchantActions,
        private CollectionObligation $obligations,
        private LedgerParticipants $ledger,
        private LedgerQueries $ledgerQueries,
        private CommandOutbox $outbox,
    ) {}

    /** @return array{binding: ConsentBindingView, merchant: MerchantRevision, host: HostConsentAuthoritySnapshot, obligationRef: string, componentRef: string} */
    public function prepare(Caller $caller, AuthorizeCollection $command): array
    {
        $scope = $command->executionScope;
        if ($scope->installationRef !== $caller->installationRef || $scope->sellerRef !== $caller->sellerRef
            || $scope->environment !== $caller->environment || ! in_array($scope->intentOwnerNamespace, $caller->intentOwnerNamespaces, true)
            || ! in_array($command->executionProfile, $caller->sourceAuthorities, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Collection execution route is outside the authenticated scope');
        }
        $step = $command->step;
        $binding = $this->queries->findBinding($step->scope, $command->consentRef, $command->consentVersion)
            ?? throw new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown collection consent binding');
        $case = $this->cases->findCase($step->scope, $step->caseRef)
            ?? throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.collection.dunning_case_not_found');
        $obligation = $this->ledgerQueries->obligation($case->obligationRef);

        return [
            'binding' => $binding,
            'merchant' => $this->execution->merchantRevision($scope, $binding->merchantConfigRevision),
            'host' => $this->host->currentAuthority($step->scope, $binding->billingAccountRef, $binding->consentRef),
            'obligationRef' => $case->obligationRef,
            'componentRef' => RowCast::str($obligation['componentRef'] ?? null, 'componentRef'),
        ];
    }

    public function lockMerchantAndIdentity(TransactionContext $ctx, AuthorizeCollection $command, MerchantRevision $merchant): void
    {
        $scope = $command->executionScope;
        $this->merchantActions->lock($ctx, $scope, $merchant->revisionRef, 'new');
        $this->rootIdentities->lockRootIdentity($ctx, $scope, $command->step->operationRef, $command->step->businessPaymentIntentRef);
    }

    public function lockAndSize(
        TransactionContext $ctx, Connection $db, AuthorizeCollection $command, DunningCaseView $case,
        CollectionChannel $channel, string $ref, ConsentBindingView $binding, MerchantRevision $merchant,
        HostConsentAuthoritySnapshot $host, string $componentRef, ?DateTimeImmutable $dueAsOf,
    ): int {
        $step = $command->step;
        $guard = $this->consents->lockGuard($ctx, $step->scope, $command->consentRef);
        if ($channel === CollectionChannel::PaymentLink || $case->billingAccountRef !== $binding->billingAccountRef
            || get_object_vars($merchant->scope) !== get_object_vars($command->executionScope)
            || $merchant->merchantAccountRef !== $binding->merchantAccountRef || $merchant->revisionRef !== $binding->merchantConfigRevision
            || $merchant->capabilityDigest !== $binding->capabilityDigest
            || ! $host->active || $host->sellerRef !== $case->sellerRef || $host->environment !== $case->environment
            || $host->billingAccountRef !== $case->billingAccountRef || $host->consentRef !== $binding->consentRef
            || $host->consentVersion !== $binding->consentVersion || $host->sourceRevision < $guard->sourceRevision
            || $host->cancellationGeneration !== $case->cancellationGeneration || $guard->cancellationGeneration !== $case->cancellationGeneration) {
            throw new ProblemException(ProblemCode::OperationFinancialHold, 'Collection consent, merchant or cancellation generation is ineligible');
        }
        if ($this->rootIdentities->rootIdentityExists($ctx, $command->executionScope, $step->operationRef, $step->businessPaymentIntentRef)) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'Collection operation or business intent is already claimed by execution');
        }
        $scope = new BillingAccountScope($case->sellerRef, $case->environment, $case->billingAccountRef);
        $now = new DateTimeImmutable;
        $open = $this->obligations->lockCollectibleMinor($ctx, $scope, $case->obligationRef, $step->businessPaymentIntentRef,
            $case->currency, $binding->customerRef, $componentRef, $dueAsOf);
        $pending = $db->table('billium_collection_authorization as a')
            ->join('billium_collection_step as s', 's.id', '=', 'a.step_id')
            ->where('a.seller_ref', $case->sellerRef)->where('a.environment', $case->environment)
            ->where('a.obligation_ref', $case->obligationRef)
            ->whereIn('s.status', ['authorized', 'dispatched', 'unknown'])->exists();
        if ($pending || $open <= 0) {
            throw new ProblemException(ProblemCode::OperationFinancialHold, 'Collection has no unreserved collectible balance');
        }

        if ($case->nextAttemptAt !== null && $case->nextAttemptAt > $now || $step->scheduledAt > $now || $case->recoveryDeadlineAt < $now) {
            throw new ProblemException(ProblemCode::OperationFinancialHold, 'Collection step is outside its due window');
        }

        $amount = min($open, $step->amountMinor);
        $committed = $binding->perPeriodCapMinor === null ? 0 : self::committedMinor($db, $binding);
        $verdict = $this->consents->evaluateChargeEligibility(new ConsentChargeEligibilityRequest(
            $step->scope, $case->billingAccountRef, $binding->consentRef, $binding->consentVersion,
            $binding->merchantAccountRef, $binding->merchantConfigRevision,
            $step->stepNo === 1 ? ConsentPurpose::RecurringCharge : ConsentPurpose::DunningRetry,
            $case->currency, $amount, $now, $committed, $case->cancellationGeneration, $ref, $step->businessPaymentIntentRef,
        ), $ctx);
        if (! $verdict->permitted) {
            throw new ProblemException(ProblemCode::OperationFinancialHold, $verdict->denialCode ?? 'Collection consent refused');
        }

        return $amount;
    }

    public function record(
        TransactionContext $ctx, Connection $db, AuthorizeCollection $command, DunningCaseView $case, ConsentBindingView $binding,
        MerchantRevision $merchant, string $ref, int $stepId, int $amount, string $issuedAt, int $recurringOwnerId, string $componentRef, bool $automaticDue,
    ): void {
        $step = $command->step;
        $scope = $command->executionScope;
        $guard = $this->consents->lockGuard($ctx, $step->scope, $binding->consentRef);
        $authorization = [
            'authorization_ref' => $ref,
            'installation_ref' => $scope->installationRef,
            'seller_ref' => $scope->sellerRef,
            'environment' => $scope->environment,
            'billing_account_ref' => $case->billingAccountRef,
            'obligation_ref' => $case->obligationRef,
            'intent_owner_namespace' => $scope->intentOwnerNamespace,
            'execution_profile' => $command->executionProfile,
            'routing_epoch' => $command->routingEpoch,
            'case_id' => $case->id,
            'recurring_owner_id' => $recurringOwnerId,
            'step_id' => $stepId,
            'operation_ref' => $step->operationRef,
            'business_intent_ref' => $step->businessPaymentIntentRef,
            'consent_ref' => $binding->consentRef,
            'consent_version' => $binding->consentVersion,
            'consent_guard_id' => $guard->id,
            'consent_binding_id' => $binding->id,
            'cancellation_generation' => $case->cancellationGeneration,
            'financial_epoch' => $ctx->sessionEpoch(),
            'issuance_generation' => $ctx->moduleGeneration('collection'),
            'currency' => $case->currency,
            'amount_minor' => $amount,
            'merchant_account_ref' => $binding->merchantAccountRef,
            'merchant_revision_ref' => $binding->merchantConfigRevision,
            'policy_version' => $case->policyRef.'@'.$case->policyVersion,
            'issued_at' => $issuedAt,
        ];
        $basis = CanonicalJson::digest(array_replace($authorization, ['amount_minor' => (string) $amount]) + ['binding_digest' => $binding->proofDigest, 'component_ref' => $componentRef]);
        $origin = new OriginDecision($scope, $step->operationRef, $step->businessPaymentIntentRef, CollectionOriginLookup::SOURCE_CONTEXT,
            $command->executionProfile, $command->routingEpoch, $ref, $basis, $authorization['policy_version'], null);
        $payload = self::paymentPayload((object) $authorization);
        $payment = self::decodePayment($payload);
        $inserted = $db->table('billium_collection_authorization')->insertOrIgnore($authorization + [
            'basis_digest' => $basis,
            'business_digest' => RootPaymentSemanticDigest::forCharge($scope, $payment, $merchant, $origin),
        ]);
        if ($inserted !== 1) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'Collection authorization identity is already reserved');
        }
        $this->ledger->declareSettlementIntent($ctx, new DeclareSettlementIntent(
            $step->commandKey, new BillingAccountScope($case->sellerRef, $case->environment, $case->billingAccountRef),
            $step->businessPaymentIntentRef, $case->currency, [$case->obligationRef], requestedMinor: $amount,
        ));
        $this->outbox->record($ctx, new PreparedInternalCommand('collection.execute:'.hash('sha256', $basis), $step->businessPaymentIntentRef,
            'collection', 'collection.execute.v1', 'billium.command.v1',
            ['intentOwnerNamespace' => $scope->intentOwnerNamespace, 'payment' => $payload], ['execution']));
        $db->table('billium_collection_work')->insert([
            'step_id' => $stepId, 'seller_ref' => $scope->sellerRef, 'environment' => $scope->environment,
            'next_attempt_at' => $issuedAt, 'automatic_due' => $automaticDue,
        ]);
    }

    /** @return array<string, mixed> */
    public static function paymentPayload(stdClass $authorization): array
    {
        $money = new Money(RowCast::integer($authorization->amount_minor, 'amount_minor'), Currency::of(RowCast::str($authorization->currency, 'currency')));

        return [
            'schema' => ExecutePayment::SCHEMA,
            'commandKey' => self::commandKey(RowCast::str($authorization->authorization_ref, 'authorization_ref'), 'execute'),
            'installationRef' => $authorization->installation_ref,
            'sellerRef' => $authorization->seller_ref,
            'environment' => $authorization->environment,
            'operationRef' => $authorization->operation_ref,
            'operationKind' => 'charge',
            'source' => (object) ['context' => CollectionOriginLookup::SOURCE_CONTEXT, 'intentRef' => $authorization->business_intent_ref],
            'executionProfile' => $authorization->execution_profile,
            'routingEpoch' => RowCast::integer($authorization->routing_epoch, 'routing_epoch'),
            'merchantAccountRef' => $authorization->merchant_account_ref,
            'money' => $money->toWire(),
            'bindingRevision' => $authorization->merchant_revision_ref,
            'requestedAt' => RowCast::formatUtc(RowCast::utcDateTime($authorization->issued_at, 'issued_at')),
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function decodePayment(array $payload): ExecutePayment
    {
        $payment = (new Codec)->decode(json_encode($payload, JSON_THROW_ON_ERROR));
        if (! $payment instanceof ExecutePayment) {
            throw new LogicException('Collection produced an invalid execution command');
        }

        return $payment;
    }

    public static function commandKey(string $ref, string $purpose): string
    {
        $hash = hash('sha256', $purpose.':'.$ref);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-'.substr($hash, 12, 4).'-'.substr($hash, 16, 4).'-'.substr($hash, 20, 12);
    }

    private static function committedMinor(Connection $db, ConsentBindingView $binding): int
    {
        // There is no host period boundary on this mandate. Keep its cap across all retained commitments.
        $sum = $db->table('billium_collection_authorization as a')->join('billium_collection_step as s', 's.id', '=', 'a.step_id')
            ->where('a.seller_ref', $binding->sellerRef)->where('a.environment', $binding->environment)
            ->where('a.consent_ref', $binding->consentRef)->where('a.currency', $binding->capCurrency)
            ->whereNotIn('s.status', ['cancelled', 'failed_terminal'])->sum('a.amount_minor');

        return (int) $sum;
    }
}

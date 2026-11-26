<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Queries;

use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Ledger\Contracts\ObservationCheckpointQueries;
use Billium\Ledger\Data\Allocations\AllocationReceipt;
use Billium\Ledger\Data\Allocations\ObservationCheckpoint;
use Billium\Ledger\Data\Views\LedgerView;
use Billium\Ledger\Internal\Observation\ParkedDrainer;
use Billium\Ledger\Internal\Observation\PaymentProjection;
use Billium\Platform\Contracts\Feed\FeedRecoveryQueries;
use Illuminate\Database\Connection;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class DatabaseObservationCheckpointQueries implements ObservationCheckpointQueries
{
    public function __construct(private ReadSession $reads, private FeedRecoveryQueries $recovery) {}

    public function checkpoint(FinancialScope $scope, PaymentObservation $observation): ?ObservationCheckpoint
    {
        return $this->reads->run(function (Connection $db, Caller $caller) use ($scope, $observation): ?ObservationCheckpoint {
            $source = $scope->sourceAuthority;
            $owner = $scope->intentOwnerNamespace;
            if ($source === null || $owner === null || ! in_array($owner, $caller->intentOwnerNamespaces, true) || ! in_array($source, $caller->sourceAuthorities, true)
                || [$scope->installationRef, $scope->sellerRef, $scope->environment] !== [$caller->installationRef, $caller->sellerRef, $caller->environment]
                || [$observation->installationRef, $observation->sellerRef, $observation->environment, $observation->sourceAuthority]
                    !== [$caller->installationRef, $caller->sellerRef, $caller->environment, $source]) {
                throw new ProblemException(ProblemCode::AuthDenied, 'Observation query scope is not authenticated');
            }

            $root = $observation->originalPaymentRef ?? $observation->operationRef;
            $revision = $db->selectOne(<<<'SQL'
                SELECT o.id AS observed_id, r.material_digest
                FROM public.billium_observed_payment o
                JOIN public.billium_observed_payment_revision r ON
                    (r.installation_ref,r.seller_ref,r.environment,r.observed_payment_id) = (o.installation_ref,o.seller_ref,o.environment,o.id)
                JOIN public.billium_observation_owner_binding b ON
                    b.operation_key = encode(sha256(convert_to(jsonb_build_array(o.seller_ref,o.environment,o.source_authority,o.operation_ref)::text,'UTF8')),'hex')
                WHERE (o.installation_ref,o.seller_ref,o.environment,o.source_authority,o.operation_ref,o.provider_account_ref,o.kind)
                    = (?,?,?,?,?,?,?)
                  AND (b.installation_ref,b.seller_ref,b.environment,b.source_authority,b.operation_ref,b.provider_account_ref)
                    = (o.installation_ref,o.seller_ref,o.environment,o.source_authority,o.operation_ref,o.provider_account_ref)
                  AND b.intent_owner_namespace = ? AND b.root_operation_ref = ?
                  AND r.revision = ? AND r.payload->>'contentDigest' = ? AND r.payload->>'eventId' = ?
                  AND r.payload->>'sourceLineage' = ? AND r.payload->>'partitionRef' = ?
                SQL, [$caller->installationRef, $caller->sellerRef, $caller->environment, $source, $observation->operationRef,
                $observation->providerAccountRef, $observation->operationKind, $owner, $root, $observation->operationRevision,
                $observation->contentDigest, $observation->eventId, $observation->sourceLineage, $observation->partitionRef]);
            if (! $revision instanceof stdClass) {
                return null;
            }

            $address = ['installation_ref' => $caller->installationRef, 'seller_ref' => $caller->sellerRef, 'environment' => $caller->environment];
            $isRefund = $observation->operationKind === 'refund';
            $receiptRef = PaymentProjection::receiptRef($isRefund ? 'refund-detail' : 'observation', $source, $observation->operationRef, $observation->operationRevision);
            $saved = $db->table('billium_allocation_receipt')->where($address)->where('receipt_ref', $receiptRef)
                ->where('kind', $isRefund ? 'refund_detail' : 'payment_observation')->where('semantic_digest', LedgerView::text($revision->material_digest))
                ->first(['kind', 'body']);
            $receipt = $saved === null ? null : new AllocationReceipt($receiptRef, LedgerView::text($saved->kind), LedgerView::json($saved->body), replayed: true);
            $pending = $db->table('billium_observation_park')->where($address)->where('observed_payment_id', LedgerView::integer($revision->observed_id))
                ->where('revision', $observation->operationRevision)->whereNull('released_at')->orderByDesc('id')->value('dependency_kind');
            $connection = $db->getName() ?? throw new LogicException('Observation query connection must be named');
            $state = $this->recovery->state($connection, ParkedDrainer::CHANNEL, $source, $observation->partitionRef);

            return new ObservationCheckpoint(true, $receipt, $pending === null ? null : LedgerView::text($pending), $state['recoveryRequired']);
        });
    }
}

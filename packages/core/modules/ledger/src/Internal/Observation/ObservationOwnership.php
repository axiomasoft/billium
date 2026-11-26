<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Observation;

use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;

#[Internal]
final class ObservationOwnership
{
    public static function hasLegacyRevision(Connection $db, PaymentObservation $o): bool
    {
        return $db->table('billium_observed_payment as p')->where('p.installation_ref', $o->installationRef)->where('p.seller_ref', $o->sellerRef)
            ->where('p.environment', $o->environment)->where('p.source_authority', $o->sourceAuthority)->where('p.provider_account_ref', $o->providerAccountRef)
            ->where('p.operation_ref', $o->operationRef)->whereNotExists(static fn (Builder $q) => $q->selectRaw('1')
            ->from('billium_observation_owner_binding as b')->whereColumn('b.seller_ref', 'p.seller_ref')->whereColumn('b.environment', 'p.environment')
            ->whereColumn('b.source_authority', 'p.source_authority')->whereColumn('b.operation_ref', 'p.operation_ref'))->exists();
    }

    public static function prove(Connection $db, TransactionContext $ctx, PaymentObservation $o, string $proof): void
    {
        $expected = $ctx->spec()->financialScope;
        if ($expected === null || $expected->intentOwnerNamespace === null || $expected->installationRef !== $o->installationRef || $expected->sellerRef !== $o->sellerRef
            || $expected->environment !== $o->environment || $expected->sourceAuthority !== $o->sourceAuthority) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Original revision proof differs from admitted scope');
        }
        $verdict = $db->scalar('SELECT public.billium_prove_observation_revision(?,?,?,?,?,?,?,?,?::jsonb,?)', [
            $o->installationRef, $o->sellerRef, $o->environment, $o->sourceAuthority, $o->providerAccountRef, $o->operationRef, $o->originalPaymentRef ?? $o->operationRef,
            $expected->intentOwnerNamespace, json_encode($o->raw, JSON_THROW_ON_ERROR), $proof]);
        if ($verdict === 'denied') {
            throw new ProblemException(ProblemCode::ObservationConflict, 'Original revision already has a different verified owner');
        }
    }

    public static function check(Connection $db, TransactionContext $context, PaymentObservation $observation): void
    {
        $caller = $context->caller();
        $binding = $db->selectOne('SELECT public.billium_claim_observation_owner(?,?,?,?,?,?,?,?,?::jsonb) AS verdict', [
            $caller->installationRef, $caller->sellerRef, $caller->environment, $observation->sourceAuthority, $observation->providerAccountRef,
            $observation->operationRef, $observation->originalPaymentRef ?? $observation->operationRef, $context->spec()->financialScope?->intentOwnerNamespace,
            json_encode($observation->raw, JSON_THROW_ON_ERROR),
        ]);
        $verdict = is_object($binding) ? ($binding->verdict ?? null) : null;
        if ($verdict !== 'accepted') {
            throw new ProblemException($verdict === 'unproven' ? ProblemCode::RecoveryRequired : ProblemCode::ObservationConflict,
                'Economic operation and original charge need a matching immutable observation owner');
        }
    }
}

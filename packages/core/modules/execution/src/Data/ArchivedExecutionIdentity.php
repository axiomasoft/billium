<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Enums\EffectKind;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Original commercial terms and the already-started first dispatch. Constructing this value confers no import authority. */
#[Api(since: '1.0')]
final readonly class ArchivedExecutionIdentity
{
    public function __construct(
        public FrozenBinding $binding, public EffectKind $kind, public Money $requested, public string $businessIntentRef,
        public string $operationRef, public string $semanticDigest, public string $commandKey, public string $qualifiedKeyDigest,
        public string $inputDigest, public string $attemptRef, public string $providerReference, public string $providerId,
        public string $merchantRevisionDigest, public string $providerNamespace, public string $credentialVersionRef,
        public string $requestDigest, public int $originalFence, public string $originalOwner, public int $originalEpoch,
        public string $originalLineage, public string $firstTicketRef, public ?string $collectionPermitRef,
        public DateTimeImmutable $createdAt, public DateTimeImmutable $startedAt, public DateTimeImmutable $deadline,
        public string $manifestDigest, public string $sourceJournalDigest, public ?string $reasonCode = null, public ?string $category = null, public ?string $providerOperationId = null,
    ) {
        foreach ([$businessIntentRef, $operationRef, $commandKey, $attemptRef, $providerReference, $providerId, $providerNamespace,
            $credentialVersionRef, $originalOwner, $originalLineage, $firstTicketRef, $reasonCode, $category, $providerOperationId] as $field) {
            if ($field !== null && ($field === '' || strlen($field) > 200)) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid archived original reference');
            }
        }
        foreach ([$qualifiedKeyDigest, $inputDigest, $requestDigest, $manifestDigest, $sourceJournalDigest, $semanticDigest] as $digest) {
            if (preg_match('/^sha256:[0-9a-f]{64}$/D', $digest) !== 1) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Incomplete original manifest or source journal');
            }
        }
        $root = in_array($kind, [EffectKind::Charge, EffectKind::Authorize], true);
        if ($binding->origin === null || $root !== ($operationRef === $binding->rootOperationRef)
            || ($root && ($businessIntentRef !== $binding->businessIntentRef || $semanticDigest !== $binding->semanticDigest))
            || $requested->minor <= 0 || $originalFence < 1 || $originalFence === PHP_INT_MAX || $originalEpoch < 1 || $createdAt > $startedAt || $startedAt >= $deadline
            || ($binding->collectionAuthorizationRef === null && $collectionPermitRef !== null)
            || ($kind !== EffectKind::Refund && $binding->collectionAuthorizationRef !== null && $collectionPermitRef === null)
            || in_array($kind, [EffectKind::VoidAuthorization, EffectKind::Refund], true) !== ($reasonCode !== null)
            || ($kind === EffectKind::Refund) !== ($category !== null)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Archived dispatch differs from its original commercial terms');
        }
    }

    public function commandSchema(): string
    {
        return match ($this->kind) {
            EffectKind::Charge => 'payment.execute.v1', EffectKind::Authorize => 'payment.authorize.v1',
            EffectKind::Capture => 'payment.capture.v1', EffectKind::Refund => 'payment.refund.v1',
            EffectKind::VoidAuthorization => 'payment.void_authorization.v1',
        };
    }
}

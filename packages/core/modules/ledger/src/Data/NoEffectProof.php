<?php

declare(strict_types=1);

namespace Billium\Ledger\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;

/**
 * FA02 typed proof that a collection step never had an external effect. The collection owner mints it under its
 * DunningCase guard row in the SAME root Tx (`rootRef` = TransactionContext::rootRef()) after it checked the step
 * authorization, revocation and the absence of a committed dispatch permit and of pending/unknown work. The ledger
 * accepts only a proof of its current root; a proof that reports a permit or any external effect is not a no-effect proof.
 */
#[Api]
final readonly class NoEffectProof
{
    public const array KINDS = ['not_admitted', 'not_dispatched'];

    public const array EFFECTS = ['none', 'pending', 'unknown', 'sent'];

    public function __construct(
        public string $rootRef,
        public string $settlementIntentRef,
        public int $expectedRevision,
        public string $stepAuthorizationRef,
        public string $kind,
        public string $evidenceRef,
        public bool $dispatchPermitCommitted = false,
        public string $externalEffect = 'none',
    ) {
        if ($rootRef === '' || $settlementIntentRef === '' || $stepAuthorizationRef === '' || $evidenceRef === ''
            || ! in_array($kind, self::KINDS, true) || ! in_array($externalEffect, self::EFFECTS, true) || $expectedRevision < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'a no-effect proof names its root, intent revision, step, kind and evidence');
        }
    }

    /** Semantic digest; the root binding is excluded, so the same proof in a later root replays the same closure. */
    public function digest(): string
    {
        $values = get_object_vars($this);
        unset($values['rootRef']);

        return 'sha256:'.hash('sha256', (new Codec)->canonicalize($values));
    }
}

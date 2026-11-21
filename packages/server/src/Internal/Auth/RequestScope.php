<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Auth;

use Billium\Contracts\Data\Envelope;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Data\Envelopes\UsageFact;
use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runtime\Caller;
use Billium\Server\Data\ExecutionContext;
use Billium\Server\Data\ResourceScope;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class RequestScope
{
    private const array SELECTORS = [
        'operationRef', 'originalOperationRef', 'originalPaymentRef', 'bindingRevision', 'executionProfile',
        'merchantAccountRef', 'providerAccountRef', 'eventId', 'sourceAuthority', 'sourceLineage', 'partitionRef',
        'businessIntentRef', 'commandKey', 'receiptRef', 'query', 'selector', 'cursor', 'asOf', 'subscriptionRef',
        'selectionDigest', 'scope', 'source', 'subjectRef', 'factId', 'attribution', 'obligationRef', 'invoiceRef',
        'billingAccountRef', 'customerRef', 'collectionRef', 'mandateRef',
    ];

    public static function select(ExecutionContext $context, Envelope $envelope, mixed $routeOperation): ResourceScope
    {
        $body = $envelope->raw;
        // D9: usage facts in this revision carry a seller selector only.
        $hasBodyScope = ! $envelope instanceof UsageFact && ! $envelope instanceof UsageFactV2;
        if (($body->sellerRef ?? null) !== $context->sellerRef
            || ($hasBodyScope && (($body->environment ?? null) !== $context->environment || ($body->installationRef ?? null) !== $context->installationRef))
            || ($routeOperation !== null && $routeOperation !== ($body->operationRef ?? null))) {
            throw new ProblemException(ProblemCode::AuthDenied);
        }

        $selectors = array_intersect_key(get_object_vars($body), array_flip(self::SELECTORS));

        return new ResourceScope($context->installationRef, $context->environment, $context->sellerRef,
            array_map(self::copySelector(...), $selectors), $context->actorRef);
    }

    public static function caller(ExecutionContext $context, Envelope $envelope): Caller
    {
        $authorities = $context->executionAuthorities;
        if ($envelope instanceof PaymentObservation) {
            $authorities = [];
            foreach ($context->sourceAuthorities as $grant) {
                if ($grant->coversObservation($envelope)) {
                    $authorities = [$grant->sourceAuthority];
                    break;
                }
            }
            if ($authorities === []) {
                throw new ProblemException(ProblemCode::AuthDenied);
            }
        }

        return new Caller($context->sellerRef, $context->environment, $context->installationRef, $context->actorRef,
            $authorities, $context->intentOwnerNamespace === null ? [] : [$context->intentOwnerNamespace], $context->permissions);
    }

    private static function copySelector(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? array_map(self::copySelector(...), $value) : $value;
    }
}

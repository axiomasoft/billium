<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Immutable configuration interval and account-continuity metadata; lifecycle permissions are separate events. */
#[Api(since: '1.0')]
final readonly class MerchantRevisionPolicy
{
    public function __construct(
        public DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validUntil,
        public ?string $predecessorRef,
        public string $apiProfileVersion,
        public string $webhookRouteRef,
        public string $continuityEvidenceRef,
        public string $settlementAccountRef,
        public string $mandateNamespaceRef,
    ) {
        if ($validUntil !== null && $validUntil <= $validFrom) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Merchant effective interval must be increasing');
        }
        foreach ([$apiProfileVersion, $webhookRouteRef, $continuityEvidenceRef, $settlementAccountRef, $mandateNamespaceRef] as $value) {
            if ($value === '' || strlen($value) > 200) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Merchant policy identities must be bounded and nonempty');
            }
        }
        if ($predecessorRef !== null && ($predecessorRef === '' || strlen($predecessorRef) > 200)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Merchant predecessor must be null or a bounded nonempty ref');
        }
    }
}

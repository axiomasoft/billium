<?php

declare(strict_types=1);

namespace Billium\Collection\Internal;

use Billium\Collection\Contracts\CollectionConsentGuard;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\HostConsentAuthoritySnapshot;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Override;
use Packman\Attributes\Internal;

/**
 * Default unbound CollectionConsentGuard: fails closed until the host provides its current consent authority.
 */
#[Internal]
final readonly class UnboundCollectionConsentGuard implements CollectionConsentGuard
{
    #[Override]
    public function currentAuthority(
        CollectionScope $scope,
        string $billingAccountRef,
        string $consentRef,
    ): HostConsentAuthoritySnapshot {
        throw new ProblemException(
            ProblemCode::LookupUnavailable,
            'billium.collection.consent_guard_unbound',
        );
    }
}

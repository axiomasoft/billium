<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Authorization;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Data\AuthorizationRecord;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\CapacitySnapshot;
use Billium\Execution\Enums\CaptureMode;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Internal\Capacity\CapacityAmounts;
use DateTimeImmutable;
use Packman\Attributes\Internal;

#[Internal]
final class AuthorizationPolicy
{
    public static function assertOpen(?AuthorizationRecord $auth, DateTimeImmutable $now): void
    {
        if ($auth === null || ($auth->providerExpiresAt === null) !== ($auth->expiryEvidenceRef === null)) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Authorization lacks a verified lifecycle or expiry basis');
        }
        if (! in_array($auth->state, ['authorized', 'partially_captured'], true)
            || ($auth->providerExpiresAt !== null && $auth->providerExpiresAt <= $now)) {
            throw new ProblemException(ProblemCode::AuthorizationCapacityExceeded, 'Authorization is closed to further capture or release');
        }
    }

    public static function assertAmount(EffectKind $kind, Money $amount, CapabilitySnapshot $capability, CapacitySnapshot $capacity): void
    {
        if ($capacity->financialHold) {
            throw new ProblemException(ProblemCode::OperationFinancialHold, 'Original payment has an active financial hold');
        }
        // A full-only provider sees the confirmed remainder. A concurrent reservation has yet to reach the provider.
        $remainder = CapacityAmounts::remaining($capacity->authorizedLimit->minor, $capacity->confirmedCapture->minor, $capacity->releasedAuthorization->minor);
        if ($kind === EffectKind::Capture) {
            $mode = $capability->details->captureMode;
            if ($mode === CaptureMode::None || ($mode === CaptureMode::Full && $amount->minor !== $remainder)
                || ($mode === CaptureMode::PartialSingle && ($capacity->heldCapture->minor > 0 || $capacity->confirmedCapture->minor > 0))) {
                throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Requested capture is outside the original account capture mode');
            }
        } elseif ($kind === EffectKind::VoidAuthorization && ! $capability->partialVoidAuthorization && $amount->minor !== $remainder) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Original account requires release of the full authorization remainder');
        }
    }
}

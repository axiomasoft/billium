<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Validation;

use Billium\Collection\Contracts\SavedMethodCapabilityValidator;
use Billium\Collection\Data\SavedMethodCapabilityRequirement;
use Billium\Collection\Enums\ConsentPurpose;
use Billium\Collection\Internal\Support\RowCast;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Enums\DriverCapability;
use Billium\Execution\Enums\EffectKind;
use Override;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DefaultSavedMethodCapabilityValidator implements SavedMethodCapabilityValidator
{
    #[Override]
    public function assertMerchantSupportsSavedMethod(
        MerchantRevision $merchant,
        CapabilitySnapshot $capabilities,
        SavedMethodCapabilityRequirement $requirement,
    ): void {
        RowCast::currency($requirement->currency);

        if (
            $merchant->scope->sellerRef !== $requirement->scope->sellerRef
            || $merchant->scope->environment !== $requirement->scope->environment
        ) {
            throw new ProblemException(
                ProblemCode::AuthDenied,
                'billium.collection.merchant_seller_mismatch',
            );
        }

        if (
            $merchant->merchantAccountRef !== $requirement->merchantAccountRef
            || $merchant->revisionRef !== $requirement->merchantConfigRevision
        ) {
            throw new ProblemException(
                ProblemCode::AuthDenied,
                'billium.collection.merchant_binding_mismatch',
            );
        }

        if (
            $capabilities->snapshotRef !== $merchant->capabilitySnapshotRef
            || $capabilities->digest !== $merchant->capabilityDigest
            || $capabilities->merchantAccountRef !== $merchant->merchantAccountRef
            || $capabilities->driverId !== $merchant->driverId
            || $capabilities->driverContractVersion !== $merchant->driverContractVersion
        ) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'billium.collection.capability_snapshot_mismatch',
            );
        }

        if (! in_array($requirement->currency, $merchant->currencyCodes, true)) {
            throw new ProblemException(
                ProblemCode::CapabilityUnsupported,
                'billium.collection.merchant_currency_unsupported',
            );
        }

        $capabilities->requireEffect(EffectKind::Charge, $requirement->currency, $requirement->production);
        $capabilities->requireCapability(DriverCapability::SavedMethod, $requirement->production);
        if ($requirement->purpose !== ConsentPurpose::OneOffSavedMethod) {
            $capabilities->requireCapability(DriverCapability::RecurringConsent, $requirement->production);
        }
    }
}

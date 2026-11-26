<?php

declare(strict_types=1);

namespace Billium\Testkit\Drivers;

use Billium\Execution\Data\AdapterQualificationManifest;
use Billium\Execution\Data\CapabilityDetails;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Enums\CaptureMode;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ProviderEvidenceMode;
use Billium\Execution\Enums\QualificationMode;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Every guarantee in this builder is explicitly simulated, never provider qualification. */
#[Api(since: '1.0')]
final class SimulatedCapabilities
{
    /** @param list<EffectKind>|null $effects */
    public static function snapshot(string $account = 'account-a', CaptureMode $capture = CaptureMode::PartialMultiple, ?array $effects = null, ?int $idempotencySeconds = 60, string $visibility = 'complete', bool $completeInventory = true, bool $absence = true, bool $partialRefund = true, bool $multipleRefund = true, ProviderEvidenceMode $evidenceMode = ProviderEvidenceMode::CumulativeSnapshot, bool $inventorySupported = true, bool $partialVoid = true): CapabilitySnapshot
    {
        $effects ??= EffectKind::cases();
        $hasRefund = in_array(EffectKind::Refund, $effects, true);
        $hasAuthorize = in_array(EffectKind::Authorize, $effects, true);
        $details = new CapabilityDetails('simulated.v1', $idempotencySeconds !== null, $idempotencySeconds === null, $idempotencySeconds === null ? null : 'account/environment/effect/reference', $idempotencySeconds, $visibility !== 'none', $visibility, $visibility === 'none' ? null : 3600, $capture, $hasRefund && $multipleRefund, $hasRefund && $visibility !== 'none', true, true, true, true, $inventorySupported, $inventorySupported, true, false, false, false, 'simulated/account/reference', 'immutable-reference.v1', 'simulated-manifest.v1');
        $digest = hash('sha256', serialize([$account, $effects, $details, $completeInventory, $absence, $evidenceMode, $partialRefund, $partialVoid]));

        return new CapabilitySnapshot('simulated:'.$digest, 'simulated-driver', 'simulated-driver.v1', 'simulated.v1', $account, $effects, ['RUB'], $evidenceMode, in_array($capture, [CaptureMode::PartialSingle, CaptureMode::PartialMultiple], true), $hasRefund && $partialRefund, $hasAuthorize && $partialVoid, $hasAuthorize, $visibility !== 'none', $completeInventory, $absence, null, QualificationMode::Simulated, 'simulated-qualification.v1', $digest, $details);
    }

    public static function manifest(CapabilitySnapshot $snapshot, DateTimeImmutable $checkedAt): AdapterQualificationManifest
    {
        return new AdapterQualificationManifest('simulated-manifest.v1', $snapshot->driverId, $snapshot->driverContractVersion, 'simulated-provider.v1', $snapshot->digest, $snapshot->merchantAccountRef, 'testing', [], $checkedAt, 'explicit historical account continuity required', [], ['No live provider evidence; no production fencing qualification'], QualificationMode::Simulated, $snapshot->qualificationEvidenceRef);
    }
}

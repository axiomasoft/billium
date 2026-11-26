<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Installation;

use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Platform\Data\Installation\FinancialSessionView;
use Billium\Platform\Data\Installation\ModuleTransitionRequest;
use Billium\Platform\Exceptions\TransitionRefused;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Installation administration of the platform module: explicit bootstrap, the local financial-session barrier
 * (D54/D62) and module lifecycle transitions (D55). The installation is the authenticated caller's. Every transition is
 * a keyed root operation (its ref is the idempotency key); replays return the original receipt.
 *
 * Session protocol: the host authority opens a new epoch in recovery for every deployment/attachment generation
 * transfer, restore or continuity loss (the previous live epoch is superseded, never treated as sealed), activates it
 * only with an external ActivationCertificate, and seals after it closed admission and drained: seal takes the session
 * row FOR UPDATE with billium.financial_session.seal_lock_timeout_ms. No commit_no equality or clean release activates
 * an owner.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface Installation
{
    /** Idempotent: installation_state, the outbox watermark and accepting module rows of the enabled modules. */
    public function init(string $installationRef): void;

    public function currentSession(string $installationRef): ?FinancialSessionView;

    /** @throws TransitionRefused */
    public function openSession(string $transitionRef, string $ownerSessionRef, int $deploymentGeneration, int $attachmentGeneration, int $authorityEpoch, string $evidenceRef): FinancialSessionView;

    /** @throws TransitionRefused */
    public function activateSession(string $transitionRef, int $epoch, string $activationCertificateRef, string $externalAdmissionRef, string $evidenceRef): FinancialSessionView;

    /** @throws TransitionRefused */
    public function beginSealing(string $transitionRef, int $epoch): FinancialSessionView;

    /** @throws TransitionRefused seal_lock_timeout leaves the session as it was */
    public function seal(string $transitionRef, int $epoch): FinancialSessionView;

    /** @throws TransitionRefused */
    public function transitionModule(ModuleTransitionRequest $request): StoredOutcome;
}

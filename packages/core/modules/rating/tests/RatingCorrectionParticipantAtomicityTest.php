<?php

declare(strict_types=1);

namespace Billium\Rating\Tests;

use Billium\Rating\Tests\Feature\CorrectionIntegrationSmokeTest;

/** Includes rollback proofs over both ledger participants and correction-owned boundaries. */
final class RatingCorrectionParticipantAtomicityTest extends CorrectionIntegrationSmokeTest {}
